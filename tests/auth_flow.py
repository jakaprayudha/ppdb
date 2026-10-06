"""HTTP integration tests using only Python's standard library and the PHP runtime."""

import hashlib
import http.cookiejar
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request


ROOT = Path(__file__).resolve().parents[1]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookies), NoRedirect()
        )

    def request(self, path, data=None, method=None):
        payload = urllib.parse.urlencode(data).encode() if data is not None else None
        request = urllib.request.Request(self.base + path, data=payload, method=method)
        try:
            response = self.opener.open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.headers

    def csrf(self, path):
        status, body, _ = self.request(path)
        if status != 200:
            raise AssertionError(f"Expected a form at {path}, got HTTP {status}")
        match = re.search(r'name="csrf" value="([a-f0-9]+)"', body)
        if not match:
            raise AssertionError(f"No CSRF token at {path}")
        return match.group(1)


class AuthFlow(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        php = shutil.which("php")
        if not php:
            raise RuntimeError("PHP is required to run auth tests.")
        cls.temp = tempfile.TemporaryDirectory(prefix="spmb-auth-test-")
        cls.storage = Path(cls.temp.name) / "storage"
        cls.log = open(Path(cls.temp.name) / "server.log", "w+")
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]
        cls.base = f"http://127.0.0.1:{port}"
        environment = dict(
            os.environ, APP_ENV="development", APP_URL=cls.base,
            APP_STORAGE=str(cls.storage), MAIL_TRANSPORT="file"
        )
        environment.update(getattr(cls, "server_environment", {}))
        cls.process = subprocess.Popen(
            [php, "-S", f"127.0.0.1:{port}", "-t", "public", "public/router.php"],
            cwd=ROOT, env=environment, stdout=cls.log, stderr=cls.log
        )
        try:
            for _ in range(100):
                if cls.process.poll() is not None:
                    raise RuntimeError("PHP server stopped before startup.")
                try:
                    if Client(cls.base).request("/login")[0] == 200:
                        return
                except urllib.error.URLError:
                    pass
                time.sleep(0.1)
            raise RuntimeError("PHP server did not become responsive.")
        except Exception:
            cls.stop_server()
            raise

    @classmethod
    def stop_server(cls):
        if cls.process.poll() is None:
            cls.process.terminate()
            cls.process.wait(timeout=10)
        cls.log.close()
        cls.temp.cleanup()

    @classmethod
    def tearDownClass(cls):
        cls.stop_server()

    def setUp(self):
        self.client = Client(self.base)

    def register(self, email, name="Wali Uji"):
        return self.client.request("/register", {
            "csrf": self.client.csrf("/register"),
            "name": name, "email": email,
            "password": "password-uji-awal-123",
            "password_confirmation": "password-uji-awal-123",
            "privacy": "1", "role": "admin"
        })

    def login(self, client, email, password="password-uji-awal-123"):
        return client.request("/login", {
            "csrf": client.csrf("/login"), "email": email, "password": password
        })

    def request_reset(self, client, email):
        return client.request("/forgot-password", {
            "csrf": client.csrf("/forgot-password"), "email": email
        })

    def reset_path(self, email):
        messages = [
            path for path in self.storage.joinpath("mail").glob("*.eml")
            if f"To: {email}\n" in path.read_text()
        ]
        latest = max(messages, key=lambda path: path.stat().st_mtime_ns)
        message = latest.read_text()
        token = re.search(r"token=([a-f0-9]{64})", message).group(1)
        return "/reset-password?token=" + token, token

    def test_full_auth_and_password_recovery(self):
        email = "recovery@example.test"
        status, _, headers = self.register(email, "<script>alert(1)</script>")
        self.assertEqual(status, 303)
        self.assertEqual(headers["Location"], "/login")
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            row = db.execute(
                "SELECT password_hash, role FROM users WHERE email = ?", (email,)
            ).fetchone()
        self.assertNotEqual(row[0], "password-uji-awal-123")
        self.assertTrue(row[0].startswith("$2y$"))
        self.assertEqual(row[1], "guardian")

        status, body, _ = self.register(email.upper())
        self.assertEqual(status, 422)
        self.assertIn("sudah terdaftar", body)
        self.assertEqual(self.login(self.client, email, "wrong-password")[0], 422)
        previous_session = next(cookie.value for cookie in self.client.cookies if cookie.name == "spmb_session")
        self.assertEqual(self.login(self.client, email)[0], 303)
        signed_in_session = next(cookie.value for cookie in self.client.cookies if cookie.name == "spmb_session")
        self.assertNotEqual(previous_session, signed_in_session)
        status, body, headers = self.client.request("/dashboard")
        self.assertEqual(status, 200)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", body)
        self.assertNotIn("<script>alert(1)</script>", body)
        self.assertEqual(headers["Cache-Control"], "no-store")
        self.assertIn("frame-ancestors 'none'", headers["Content-Security-Policy"])
        self.assertTrue(any(cookie.has_nonstandard_attr("HttpOnly") for cookie in self.client.cookies))

        second_session = Client(self.base)
        self.assertEqual(self.login(second_session, email)[0], 303)
        recovery = Client(self.base)
        self.assertEqual(self.request_reset(recovery, email)[0], 303)
        path, token = self.reset_path(email)
        _, body, _ = recovery.request("/forgot-password")
        self.assertNotIn(token, body)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            stored = db.execute("SELECT token_hash FROM password_resets").fetchone()[0]
        self.assertEqual(stored, hashlib.sha256(token.encode()).hexdigest())

        status, body, _ = recovery.request(path, {
            "csrf": recovery.csrf(path), "password": "short",
            "password_confirmation": "short"
        })
        self.assertEqual(status, 422)
        self.assertIn("minimal 12", body)
        self.assertEqual(recovery.request(path, {
            "csrf": recovery.csrf(path), "password": "password-uji-baru-456",
            "password_confirmation": "password-uji-baru-456"
        })[0], 303)
        self.assertEqual(self.client.request("/dashboard")[0], 303)
        self.assertEqual(second_session.request("/dashboard")[0], 303)
        _, body, _ = recovery.request(path)
        self.assertIn("sudah digunakan", body)
        self.assertNotIn('name="password"', body)
        self.assertEqual(self.login(self.client, email)[0], 422)
        self.assertEqual(self.login(self.client, email, "password-uji-baru-456")[0], 303)
        self.assertEqual(self.client.request("/logout")[0], 405)
        status, body, _ = self.client.request("/dashboard")
        csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', body).group(1)
        self.assertEqual(self.client.request("/logout", {"csrf": csrf})[0], 303)
        self.assertEqual(self.client.request("/dashboard")[0], 303)

        self.assertEqual(self.request_reset(recovery, email)[0], 303)
        expired_path, expired_token = self.reset_path(email)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("UPDATE password_resets SET expires_at = ? WHERE token_hash = ?",
                       (int(time.time()) - 1, hashlib.sha256(expired_token.encode()).hexdigest()))
        _, body, _ = recovery.request(expired_path)
        self.assertIn("kedaluwarsa", body)
        self.assertNotIn('name="password"', body)

        self.assertEqual(self.request_reset(recovery, email)[0], 303)
        unused_path, _ = self.reset_path(email)
        csrf = recovery.csrf(unused_path)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("UPDATE password_resets SET used_at = ?", (int(time.time()),))
        status, body, _ = recovery.request(unused_path, {
            "csrf": csrf, "password": "password-lain-baru-789",
            "password_confirmation": "password-lain-baru-789"
        })
        self.assertEqual(status, 422)
        self.assertIn("tidak valid", body)
        self.assertEqual(self.request_reset(recovery, email)[0], 429)

        with sqlite3.connect(self.storage / "app.sqlite") as db:
            actions = {row[0] for row in db.execute("SELECT action FROM audit_events")}
        self.assertTrue({"auth.register", "auth.login", "auth.login_failed",
                         "auth.password_reset", "auth.logout"} <= actions)

    def test_csrf_validation_routes_and_private_storage(self):
        self.assertEqual(self.client.request("/dashboard")[0], 303)
        self.assertEqual(self.client.request("/register", {
            "name": "Wali", "email": "csrf@example.test",
            "password": "password-uji-awal-123",
            "password_confirmation": "password-uji-awal-123", "privacy": "1"
        })[0], 419)
        status, body, _ = self.client.request("/register", {
            "csrf": self.client.csrf("/register"), "name": "",
            "email": "invalid", "password": "short",
            "password_confirmation": "different"
        })
        self.assertEqual(status, 422)
        self.assertIn('aria-invalid="true"', body)
        self.assertIn("pemberitahuan privasi", body)
        for path in ["/storage/app.sqlite", "/app/bootstrap.php", "/prd.md", "/not-found",
                     "/assets/../storage/app.sqlite"]:
            self.assertEqual(self.client.request(path)[0], 404)
        self.assertEqual(self.client.request("/login", method="PUT")[0], 405)
        self.assertEqual(self.client.request("/assets/app.css")[0], 200)
        self.assertEqual(self.client.request("/assets/app.js")[0], 200)
        self.assertEqual(self.client.request("/privacy")[0], 200)

    def test_configuration_guards(self):
        cases = [
            {"APP_ENV": "production", "APP_URL": "http://example.test", "MAIL_TRANSPORT": "mail"},
            {"APP_ENV": "production", "APP_URL": "https://example.test", "MAIL_TRANSPORT": "file"},
            {"APP_URL": "http://user@example.test"},
            {"APP_URL": "http://example.test?query=value"},
            {"APP_URL": "http://example.test#fragment"},
            {"APP_STORAGE": str(ROOT / "public")},
        ]
        for settings in cases:
            with self.subTest(settings=settings):
                environment = dict(
                    os.environ, APP_ENV="development", APP_URL=self.base,
                    APP_STORAGE=str(self.storage), MAIL_TRANSPORT="file"
                )
                environment.update(settings)
                result = subprocess.run(
                    [shutil.which("php"), "-r",
                     "require 'public/index.php'; echo '\\nSTATUS=' . http_response_code();"],
                    cwd=ROOT, env=environment, capture_output=True, text=True, timeout=10
                )
                self.assertEqual(result.returncode, 0)
                self.assertIn("STATUS=503", result.stdout)
                self.assertIn("Layanan belum tersedia", result.stdout)
                self.assertIn("[SPMB]", result.stderr)

    def test_ppdb_identity_on_auth_pages(self):
        for path in ["/login", "/register", "/forgot-password", "/reset-password?token=invalid"]:
            with self.subTest(path=path):
                status, body, _ = self.client.request(path)
                self.assertEqual(status, 200)
                self.assertIn("— PPDB</title>", body)
                self.assertIn("PORTAL RESMI PEMERINTAH", body)
                self.assertIn("Penerimaan <br>Peserta Didik <br>", body)
                self.assertNotIn("RUANG UNTUK BERTUMBUH", body)
                self.assertNotIn("Langkah kecil.", body)

    def test_account_enumeration_and_rate_limit(self):
        email = "unknown@example.test"
        for _ in range(3):
            self.assertEqual(self.request_reset(self.client, email)[0], 303)
            status, body, _ = self.client.request("/forgot-password")
            self.assertEqual(status, 200)
            self.assertIn("Jika email terdaftar", body)
        self.assertEqual(self.request_reset(self.client, email)[0], 429)
        for _ in range(8):
            self.assertEqual(self.login(self.client, "bruteforce@example.test")[0], 422)
        status, _, headers = self.login(self.client, "bruteforce@example.test")
        self.assertEqual(status, 429)
        self.assertEqual(headers["Retry-After"], "900")


if __name__ == "__main__":
    unittest.main(verbosity=2)
