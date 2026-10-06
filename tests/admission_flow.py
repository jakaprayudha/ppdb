"""Admission integration tests; each test server uses private temporary storage."""

import base64
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import time
import unittest
import urllib.error
import urllib.request

import auth_flow as auth_helpers
from auth_flow import Client, ROOT


PNG = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZkAAAAASUVORK5CYII="
)
PDF = b"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF"


class AdmissionClient(Client):
    def upload(self, path, fields, filename, contents, mime="image/png"):
        boundary = "ppdb-test-boundary"
        parts = []
        for key, value in fields.items():
            parts.append(
                f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
            )
        parts.append(
            f'--{boundary}\r\nContent-Disposition: form-data; name="document"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode()
            + contents + b"\r\n"
        )
        parts.append(f"--{boundary}--\r\n".encode())
        request = urllib.request.Request(
            self.base + path, data=b"".join(parts),
            headers={"Content-Type": f"multipart/form-data; boundary={boundary}"}
        )
        return self.raw_request(request)

    def raw_request(self, request):
        try:
            response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read(), response.headers


class AdmissionFlow(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        auth_helpers.AuthFlow.setUpClass.__func__(cls)
        result = cls.cli("demo")
        if result.returncode != 0:
            cls.stop_server()
            raise RuntimeError(result.stderr)

    stop_server = classmethod(auth_helpers.AuthFlow.stop_server.__func__)
    tearDownClass = classmethod(auth_helpers.AuthFlow.tearDownClass.__func__)
    register = auth_helpers.AuthFlow.register
    login = auth_helpers.AuthFlow.login

    @classmethod
    def cli(cls, *args, extra_environment=None):
        environment = dict(
            os.environ, APP_ENV="development", APP_URL=cls.base,
            APP_STORAGE=str(cls.storage), MAIL_TRANSPORT="file"
        )
        environment.update(extra_environment or {})
        return subprocess.run(
            [shutil.which("php"), "bin/admissions.php", *args],
            cwd=ROOT, env=environment, capture_output=True, text=True, timeout=10
        )

    def setUp(self):
        self.client = AdmissionClient(self.base)
        self.email = hashlib.sha256(self._testMethodName.encode()).hexdigest()[:32] + "@example.test"
        self.assertEqual(self.register(self.email)[0], 303)
        self.assertEqual(self.login(self.client, self.email)[0], 303)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.period_id = db.execute(
                "SELECT id FROM admission_periods WHERE code='demo-pendaftaran'"
            ).fetchone()[0]
        self.participant = {
            "name": "Peserta Contoh", "nisn": "", "sex": "P",
            "birth_place": "Kota Uji", "birth_date": "2014-02-28",
            "source_school": "Sekolah Dasar Uji", "guardian_name": "Wali Uji",
            "relationship": "Ibu", "phone": "081234567890",
            "address": "Jalan Contoh 1", "province": "Provinsi Uji",
            "city": "Kota Uji", "district": "Kecamatan Uji",
            "village": "Kelurahan Uji", "postal_code": "12345"
        }

    def create_profile(self, data=None):
        status, body, headers = self.client.request("/participants/new", {
            "csrf": self.client.csrf("/participants/new"), **(data or self.participant)
        })
        self.assertEqual(status, 303, body)
        return headers["Location"].split("/")[-1]

    def create_application(self, profile_id, period_id=None):
        path = "/applications/new?period=" + (period_id or self.period_id)
        status, body, headers = self.client.request(path, {
            "csrf": self.client.csrf(path), "profile_id": profile_id,
            "period_id": period_id or self.period_id, "pathway": "simulasi"
        })
        self.assertEqual(status, 303, body)
        return headers["Location"].split("/")[-1]

    def application(self, app_id):
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.row_factory = sqlite3.Row
            return dict(db.execute("SELECT * FROM applications WHERE id=?", (app_id,)).fetchone())

    def post_application(self, app_id, step, action, data=None, version=None):
        path = f"/applications/{app_id}?step={step}"
        return self.client.request(path, {
            "csrf": self.client.csrf(path),
            "version": version if version is not None else self.application(app_id)["version"],
            "action": action, **(data or {})
        })

    def upload(self, app_id, contents=PNG, filename="preuve.png", kind="identitas-demo", version=None):
        path = f"/applications/{app_id}?step=3"
        return self.client.upload(path, {
            "csrf": self.client.csrf(path),
            "version": version if version is not None else self.application(app_id)["version"],
            "action": "upload", "kind": kind
        }, filename, contents)

    def active_document(self, app_id, kind="identitas-demo"):
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            return db.execute(
                "SELECT id FROM application_documents WHERE application_id=? AND kind=? AND deleted_at IS NULL",
                (app_id, kind)
            ).fetchone()[0]

    def test_full_draft_documents_and_submission(self):
        profile_id = self.create_profile()
        app_id = self.create_application(profile_id)
        self.assertEqual(self.create_application(profile_id), app_id)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertEqual(db.execute(
                "SELECT count(*) FROM applications WHERE profile_id=?", (profile_id,)
            ).fetchone()[0], 1)
        for step in range(1, 5):
            self.assertEqual(self.client.request(f"/applications/{app_id}?step={step}")[0], 200)
        self.assertEqual(self.client.request(f"/applications/{app_id}/receipt")[0], 409)

        self.assertEqual(self.post_application(app_id, 1, "save", {"name": "Nama draf"})[0], 303)
        status, body, _ = self.post_application(app_id, 4, "submit")
        self.assertEqual(status, 422)
        self.assertIn("wajib diisi", body)
        self.assertIn("dokumen wajib", body)
        self.assertIn("konfirmasi", body)
        self.assertIsNone(self.application(app_id)["registration_number"])

        old_version = self.application(app_id)["version"]
        self.assertEqual(self.post_application(app_id, 1, "save", self.participant)[0], 303)
        self.assertEqual(self.post_application(app_id, 1, "save", {
            "name": "Tidak boleh tertimpa"
        }, version=old_version)[0], 409)
        self.assertEqual(json.loads(self.application(app_id)["data_json"])["name"], "Peserta Contoh")
        self.assertEqual(self.post_application(app_id, 2, "save", {
            **self.participant, "pathway": "simulasi", "next": "1"
        })[0], 303)

        for contents, filename in [(b"<?php echo 1;", "fake.png"), (b"", "empty.png"), (PNG, "test.html"),
                                   (b"x" * (2 * 1024 * 1024 + 1), "large.png")]:
            with self.subTest(filename=filename):
                status, response, _ = self.upload(app_id, contents, filename)
                if filename == "large.png":
                    self.assertIn(status, [413, 422])
                    self.assertIn("2 MB", response.decode())
                else:
                    self.assertEqual(status, 422)
        self.assertEqual(self.upload(app_id, kind="unexpected")[0], 422)
        self.assertEqual(self.upload(app_id)[0], 303)
        first_document = self.active_document(app_id)
        status, contents, headers = self.client.raw_request(
            urllib.request.Request(self.base + "/documents/" + first_document)
        )
        self.assertEqual(status, 200)
        self.assertEqual(contents, PNG)
        self.assertEqual(headers["Content-Type"], "image/png")
        self.assertIn("inline", headers["Content-Disposition"])

        self.assertEqual(self.upload(app_id, filename="new.png")[0], 303)
        self.assertEqual(self.client.request("/documents/" + first_document)[0], 404)
        document_id = self.active_document(app_id)
        self.assertEqual(self.post_application(app_id, 3, "remove-document", {
            "document_id": document_id
        })[0], 303)
        self.assertEqual(self.client.request("/documents/" + document_id)[0], 404)
        self.assertEqual(self.post_application(app_id, 4, "submit", {"declaration": "1"})[0], 422)
        self.assertEqual(self.upload(app_id)[0], 303)
        self.assertEqual(self.upload(app_id, PDF, "school.pdf", "sekolah-demo")[0], 303)
        pdf_id = self.active_document(app_id, "sekolah-demo")
        status, contents, headers = self.client.raw_request(
            urllib.request.Request(self.base + "/documents/" + pdf_id)
        )
        self.assertEqual(status, 200)
        self.assertEqual(contents, PDF)
        self.assertIn("attachment", headers["Content-Disposition"])

        submission_version = self.application(app_id)["version"]
        status, body, headers = self.post_application(app_id, 4, "submit", {"declaration": "1"})
        self.assertEqual(status, 303, body)
        self.assertTrue(headers["Location"].endswith("/receipt"))
        submitted = self.application(app_id)
        self.assertEqual(submitted["status"], "submitted")
        self.assertRegex(submitted["registration_number"], r"^PPDB-\d{4}-[A-F0-9]{32}$")
        self.assertIsNotNone(submitted["rule_snapshot_json"])
        self.assertIsNotNone(submitted["declaration_at"])
        self.assertEqual(self.post_application(app_id, 4, "submit", {
            "declaration": "1"
        }, version=submission_version)[0], 303)
        self.assertEqual(self.application(app_id)["registration_number"], submitted["registration_number"])
        self.assertEqual(self.post_application(app_id, 1, "save", self.participant)[0], 409)
        self.assertEqual(self.upload(app_id)[0], 409)
        self.assertEqual(self.post_application(app_id, 3, "remove-document", {
            "document_id": self.active_document(app_id)
        })[0], 409)
        status, receipt, _ = self.client.request(f"/applications/{app_id}/receipt")
        self.assertEqual(status, 200)
        self.assertIn(submitted["registration_number"], receipt)
        self.assertIn("bukan bukti diterima", receipt)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertEqual(db.execute(
                "SELECT count(*) FROM application_events WHERE application_id=? AND action='submitted'", (app_id,)
            ).fetchone()[0], 1)
        self.assertIn("Belum dibuka untuk penerimaan nyata", receipt)
        self.assertNotIn("Dokumen identitas fiktif (DEMO)", receipt)
        self.assertIn("Dokumen identitas", receipt)
        self.assertEqual(self.application(app_id)["rule_snapshot_json"], submitted["rule_snapshot_json"])
        self.assertIn("Peserta Contoh", self.client.request("/dashboard")[1])

    def test_ownership_csrf_and_snapshot_isolation(self):
        profile_id = self.create_profile()
        app_id = self.create_application(profile_id)
        self.assertEqual(self.upload(app_id)[0], 303)
        document_id = self.active_document(app_id)
        version = self.application(app_id)["version"]
        status, _, _ = self.client.request(f"/applications/{app_id}?step=1", {
            "csrf": "invalid", "version": version, "action": "save", "name": "CSRF"
        })
        self.assertEqual(status, 419)
        self.assertEqual(self.application(app_id)["version"], version)
        stranger = AdmissionClient(self.base)
        for path in ["/participants", "/admissions", f"/applications/{app_id}", f"/documents/{document_id}"]:
            self.assertEqual(stranger.request(path)[0], 303)
        original = self.client
        self.client = stranger
        self.assertEqual(self.register("stranger@example.test")[0], 303)
        self.assertEqual(self.login(stranger, "stranger@example.test")[0], 303)
        for path in [f"/participants/{profile_id}", f"/applications/{app_id}", f"/applications/{app_id}/receipt", f"/documents/{document_id}"]:
            self.assertEqual(stranger.request(path)[0], 404)
        new_path = "/applications/new?period=" + self.period_id
        self.assertEqual(stranger.request(new_path, {
            "csrf": stranger.csrf(new_path), "profile_id": profile_id,
            "period_id": self.period_id, "pathway": "simulasi"
        })[0], 404)
        self.client = original

        profile_path = "/participants/" + profile_id
        self.assertEqual(self.client.request(profile_path, {
            "csrf": self.client.csrf(profile_path), "version": "1",
            **self.participant, "name": "Nama profil baru"
        })[0], 303)
        self.assertEqual(self.client.request(profile_path, {
            "csrf": self.client.csrf(profile_path), "version": "1",
            **self.participant, "name": "Tab lama"
        })[0], 409)
        self.assertEqual(json.loads(self.application(app_id)["data_json"])["name"], self.participant["name"])
        self.assertEqual(self.client.request(f"/applications/{app_id}?step=9")[0], 404)
        self.assertEqual(self.post_application(app_id, 1, "submit")[0], 400)
        self.assertEqual(self.client.request("/admissions", {}, method="POST")[0], 405)

    def test_draft_validation_and_closed_period(self):
        profile_id = self.create_profile({"name": "Peserta Minimal"})
        app_id = self.create_application(profile_id)
        for field, value in [("nisn", "123"), ("birth_date", "2014-02-30"), ("sex", "X"),
                             ("birth_date", "2099-01-01"), ("sex", "0"), ("birth_date", "0"),
                             ("nisn", "0"), ("name", "0")]:
            status, _, _ = self.post_application(app_id, 1, "save", {
                "name": "Peserta Minimal", field: value
            })
            self.assertEqual(status, 422)
        for field, value in [("phone", "abc"), ("relationship", "Tidak sah"), ("postal_code", "123"),
                             ("phone", "0"), ("relationship", "0"), ("postal_code", "0")]:
            self.assertEqual(self.post_application(app_id, 2, "save", {
                field: value, "pathway": "simulasi"
            })[0], 422)
        self.assertEqual(self.post_application(app_id, 2, "save", {"pathway": "fake"})[0], 422)
        self.assertEqual(self.post_application(app_id, 1, "save", {"name": ""})[0], 303)
        self.assertEqual(self.post_application(app_id, 4, "submit", {"declaration": "1"})[0], 422)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            original = db.execute("SELECT closes_at FROM admission_periods WHERE id=?", (self.period_id,)).fetchone()[0]
            db.execute("UPDATE admission_periods SET closes_at=? WHERE id=?", (int(time.time()) - 1, self.period_id))
        try:
            self.assertEqual(self.client.request(f"/applications/{app_id}")[0], 200)
            self.assertEqual(self.post_application(app_id, 1, "save", self.participant)[0], 409)
            self.assertEqual(self.upload(app_id)[0], 409)
            self.assertEqual(self.post_application(app_id, 4, "submit", {"declaration": "1"})[0], 409)
            path = "/applications/new?period=" + self.period_id
            self.assertEqual(self.client.request(path, {
                "csrf": self.client.csrf(path), "profile_id": profile_id,
                "period_id": self.period_id, "pathway": "simulasi"
            })[0], 409)
        finally:
            with sqlite3.connect(self.storage / "app.sqlite") as db:
                db.execute("UPDATE admission_periods SET closes_at=? WHERE id=?", (original, self.period_id))

    def test_configuration_import_guards(self):
        self.assertNotEqual(self.cli("demo").returncode, 0)
        self.assertIn("DEMO", self.cli("list").stdout)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            configuration = json.loads(db.execute(
                "SELECT config_json FROM admission_periods WHERE id=?", (self.period_id,)
            ).fetchone()[0])
        configuration.update(code="configured-test", school="Sekolah Konfigurasi Uji", is_demo=False)
        path = Path(self.temp.name) / "period.json"
        path.write_text(json.dumps(configuration))
        self.assertEqual(self.cli("import", str(path)).returncode, 0)
        self.assertNotEqual(self.cli("import", str(path)).returncode, 0)
        self.assertNotEqual(self.cli("demo", extra_environment={
            "APP_ENV": "production", "APP_URL": "https://example.test", "MAIL_TRANSPORT": "mail"
        }).returncode, 0)
        for changes in [{"closes_at": "2000-01-01 00:00:00"},
                        {"timezone": "invalid"}, {"academic_year": "2026/2028"},
                        {"pathways": []}, {"pathways": [configuration["pathways"][0]] * 2}]:
            invalid = {**configuration, "code": "invalid-config", **changes}
            path.write_text(json.dumps(invalid))
            self.assertNotEqual(self.cli("import", str(path)).returncode, 0)

    def test_public_and_private_pathway_templates(self):
        for mode in ["negeri", "swasta"]:
            for level in ["SD", "SMP", "SMA"]:
                with self.subTest(mode=mode, level=level):
                    result = self.cli("template", mode, level)
                    self.assertEqual(result.returncode, 0, result.stderr)
                    with sqlite3.connect(self.storage / "app.sqlite") as db:
                        period_id, raw = db.execute(
                            "SELECT id, config_json FROM admission_periods WHERE code=?",
                            (f"contoh-{mode}-{level.lower()}",)
                        ).fetchone()
                    configuration = json.loads(raw)
                    codes = [item["code"] for item in configuration["pathways"]]
                    expected = (["domisili", "afirmasi", "mutasi"] if level == "SD"
                                else ["domisili", "afirmasi", "prestasi", "mutasi"]) if mode == "negeri" else (
                                    ["reguler", "beasiswa"] if level == "SD" else ["reguler", "prestasi", "beasiswa"])
                    self.assertEqual(codes, expected)
                    self.assertTrue(configuration["is_demo"])
                    self.assertEqual(configuration["admission_mode"],
                                     "public_spmb" if mode == "negeri" else "private_independent")
                    self.assertTrue(all(
                        ("kelulusan" in [doc["code"] for doc in item["documents"]]) == (level != "SD")
                        for item in configuration["pathways"]
                    ))
                    status, body, _ = self.client.request("/applications/new?period=" + period_id)
                    self.assertEqual(status, 200)
                    self.assertIn("Negeri · SPMB" if mode == "negeri" else "Swasta · Penerimaan mandiri", body)
                    self.assertIn("Belum dibuka untuk penerimaan nyata", body)
                    self.assertNotIn("fiktif untuk demo", body)
                    self.assertNotIn("(DEMO)", body)
                    if mode == "negeri":
                        self.assertIn("Domisili adalah istilah pengganti zonasi", body)
                    self.assertNotEqual(self.cli("template", mode, level).returncode, 0)
                    if mode == "negeri" and level == "SD":
                        configuration["code"] = "invalid-sd-prestasi"
                        configuration["pathways"].append({
                            "code": "prestasi", "name": "Prestasi", "description": "", "documents": []
                        })
                        config_path = Path(self.temp.name) / "invalid-sd.json"
                        config_path.write_text(json.dumps(configuration))
                        invalid = self.cli("import", str(config_path))
                        self.assertNotEqual(invalid.returncode, 0)
                        self.assertIn("Prestasi tidak berlaku", invalid.stderr)
        self.assertNotEqual(self.cli("template", "invalid", "SMP").returncode, 0)
        self.assertNotEqual(self.cli("template", "negeri", "SMK").returncode, 0)
        self.assertNotEqual(self.cli("template", "negeri", "SMP", extra_environment={
            "APP_ENV": "production", "APP_URL": "https://example.test", "MAIL_TRANSPORT": "mail"
        }).returncode, 0)

    def test_legacy_document_labels_display_without_changing_configuration(self):
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            configuration = json.loads(db.execute(
                "SELECT config_json FROM admission_periods WHERE id=?", (self.period_id,)
            ).fetchone()[0])
        configuration["code"] = "legacy-labels-test"
        configuration["pathways"][0]["documents"] = [
            {"code": "kartu-keluarga", "label": "Kartu keluarga — gunakan berkas fiktif untuk demo", "required": True},
            {"code": "akta-kelahiran", "label": "Akta kelahiran — berkas fiktif untuk demo", "required": True},
            {"code": "bukti-afirmasi", "label": "Bukti afirmasi — fiktif untuk demo", "required": True}
        ]
        path = Path(self.temp.name) / "legacy-labels.json"
        path.write_text(json.dumps(configuration))
        self.assertEqual(self.cli("import", str(path)).returncode, 0)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            period_id, before = db.execute("SELECT id, config_json FROM admission_periods WHERE code=?", (configuration["code"],)).fetchone()
        profile_id = self.create_profile()
        app_id = self.create_application(profile_id, period_id)
        for route in ["/applications/new?period=" + period_id,
                      f"/applications/{app_id}?step=3", f"/applications/{app_id}?step=4"]:
            status, body, _ = self.client.request(route)
            self.assertEqual(status, 200)
            self.assertIn("Kartu keluarga", body)
            self.assertIn("Akta kelahiran", body)
            self.assertIn("Bukti afirmasi", body)
            self.assertNotIn("fiktif untuk demo", body)
            self.assertIn("Belum dibuka untuk penerimaan nyata", body)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            after = db.execute("SELECT config_json FROM admission_periods WHERE id=?", (period_id,)).fetchone()[0]
        self.assertEqual(after, before)

    def test_document_integrity_and_request_limits(self):
        profile_id = self.create_profile()
        app_id = self.create_application(profile_id)
        self.assertEqual(self.upload(app_id)[0], 303)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            document_id, stored = db.execute(
                "SELECT id, storage_name FROM application_documents WHERE application_id=? AND deleted_at IS NULL",
                (app_id,)
            ).fetchone()
        document_path = self.storage / "documents" / stored
        document_path.write_bytes(b"corrupted")
        self.assertEqual(self.client.request("/documents/" + document_id)[0], 503)
        self.assertEqual(self.post_application(app_id, 4, "submit", {"declaration": "1"})[0], 503)
        self.assertEqual(self.application(app_id)["status"], "draft")
        self.assertIsNone(self.application(app_id)["registration_number"])
        # PHP discards both POST and FILES when post_max_size is exceeded.
        self.assertEqual(self.upload(app_id, b"x" * (9 * 1024 * 1024), "large.png")[0], 413)

    def test_pathway_specific_requirements_and_upload_rate_limit(self):
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            configuration = json.loads(db.execute(
                "SELECT config_json FROM admission_periods WHERE id=?", (self.period_id,)
            ).fetchone()[0])
        configuration["code"] = "conditional-documents-test"
        configuration["pathways"].append({
            "code": "alternatif", "name": "Simulasi alternatif", "description": "Hanya pengujian.",
            "documents": [{"code": "bukti-alternatif", "label": "Bukti alternatif", "required": True}]
        })
        path = Path(self.temp.name) / "conditional.json"
        path.write_text(json.dumps(configuration))
        self.assertEqual(self.cli("import", str(path)).returncode, 0)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            period_id = db.execute("SELECT id FROM admission_periods WHERE code=?", (configuration["code"],)).fetchone()[0]
        app_id = self.create_application(self.create_profile(), period_id)
        self.assertEqual(self.upload(app_id)[0], 303)
        self.assertEqual(self.post_application(app_id, 2, "save", {
            **self.participant, "pathway": "alternatif"
        })[0], 303)
        _, body, _ = self.client.request(f"/applications/{app_id}?step=3")
        self.assertIn("Bukti alternatif", body)
        self.assertNotIn("identitas-demo", body)
        self.assertEqual(self.post_application(app_id, 4, "submit", {"declaration": "1"})[0], 422)
        self.assertEqual(self.upload(app_id)[0], 422)
        self.assertEqual(self.upload(app_id, kind="bukti-alternatif")[0], 303)
        self.assertEqual(self.post_application(app_id, 4, "submit", {"declaration": "1"})[0], 303)
        bucket = hashlib.sha256(
            ("document-upload:identity:" + str(self.application(app_id)["user_id"])).encode()
        ).hexdigest()
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("UPDATE rate_limits SET attempts=30 WHERE bucket=?", (bucket,))
        status, contents, headers = self.upload(app_id, kind="bukti-alternatif")
        self.assertEqual(status, 429)
        self.assertIn("Terlalu banyak unggahan", contents.decode())
        self.assertEqual(headers["Retry-After"], "900")

    def test_production_registration_is_disabled(self):
        csrf = self.client.csrf("/participants/new")
        session_id = next(cookie.value for cookie in self.client.cookies if cookie.name == "spmb_session")
        environment = dict(
            os.environ, APP_ENV="production", APP_URL="https://example.test",
            APP_STORAGE=str(self.storage), MAIL_TRANSPORT="mail"
        )
        code = """
            session_id($argv[1]);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = '/participants/new';
            $_POST = ['csrf' => $argv[2], 'name' => 'Peserta Produksi'];
            register_shutdown_function(function () { echo '\\nSTATUS=' . http_response_code(); });
            require 'public/index.php';
        """
        result = subprocess.run(
            [shutil.which("php"), "-r", code, session_id, csrf],
            cwd=ROOT, env=environment, capture_output=True, text=True, timeout=10
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("STATUS=403", result.stdout)
        self.assertIn("belum dibuka untuk data nyata", result.stdout)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertEqual(db.execute(
                "SELECT count(*) FROM participant_profiles WHERE data_json LIKE '%Peserta Produksi%'"
            ).fetchone()[0], 0)

    def test_z_serdang_bedagai_catalogue_and_archiving(self):
        profile_id = self.create_profile()
        archived_app = self.create_application(profile_id)
        self.assertEqual(self.upload(archived_app)[0], 303)
        old_document = self.active_document(archived_app)
        result = self.cli("sergai")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("40 periode", result.stdout)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            active = db.execute("""SELECT p.id, p.config_json FROM admission_periods p
                JOIN admission_period_availability v ON v.period_id=p.id WHERE v.enabled=1""").fetchall()
            self.assertEqual(len(active), 40)
            configurations = [json.loads(raw) for _, raw in active]
            self.assertEqual(len({item["npsn"] for item in configurations}), 40)
            self.assertEqual(len({item["district"] for item in configurations}), 17)
            self.assertTrue(all(item["admission_mode"] == "public_spmb" and item["level"] == "SMP"
                                and item["regency"] == "Kabupaten Serdang Bedagai" and item["is_demo"]
                                for item in configurations))
            self.assertNotIn("75683280", [item["npsn"] for item in configurations])
            before = db.execute("SELECT id, config_json FROM admission_periods ORDER BY id").fetchall()
        self.assertEqual(self.cli("sergai").returncode, 0)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertEqual(before, db.execute("SELECT id, config_json FROM admission_periods ORDER BY id").fetchall())
        status, body, _ = self.client.request("/admissions")
        self.assertEqual(status, 200)
        self.assertEqual(body.count('class="period-card"'), 40)
        school_cards = "\n".join(re.findall(r'<article class="period-card">(.*?)</article>', body, re.S))
        self.assertNotIn("SMP Swasta Contoh", school_cards)
        self.assertNotIn("Sekolah Contoh SMP", school_cards)
        self.assertIn("40 dari 40", body)
        status, body, _ = self.client.request("/admissions?district=Sei%20Rampah")
        self.assertEqual(status, 200)
        self.assertEqual(body.count('class="period-card"'), 4)
        self.assertIn("4 dari 40", body)
        status, body, _ = self.client.request("/admissions?q=10209337")
        self.assertEqual(status, 200)
        self.assertEqual(body.count('class="period-card"'), 1)
        self.assertIn("SMP NEGERI 1 SEI RAMPAH", body)
        self.assertEqual(self.client.request("/admissions?q=tidak-ada")[1].count('class="period-card"'), 0)
        self.assertEqual(self.client.request(f"/applications/{archived_app}")[0], 200)
        self.assertEqual(self.client.raw_request(urllib.request.Request(self.base + "/documents/" + old_document))[0], 200)
        self.assertEqual(self.post_application(archived_app, 1, "save", self.participant)[0], 409)
        self.assertEqual(self.upload(archived_app)[0], 409)
        self.assertEqual(self.post_application(archived_app, 4, "submit", {"declaration": "1"})[0], 409)
        old_path = "/applications/new?period=" + self.period_id
        status, body, _ = self.client.request(old_path)
        self.assertEqual(status, 409)
        self.assertNotIn("Buat / lanjutkan draf", body)
        self.assertEqual(self.client.request(old_path, {
            "csrf": self.client.csrf("/dashboard"), "period_id": self.period_id,
            "profile_id": profile_id, "pathway": "simulasi"
        })[0], 409)
        new_period, _ = active[0]
        new_path = "/applications/new?period=" + new_period
        status, body, headers = self.client.request(new_path, {
            "csrf": self.client.csrf(new_path), "period_id": new_period,
            "profile_id": profile_id, "pathway": "domisili"
        })
        self.assertEqual(status, 303, body)
        self.assertNotEqual(headers["Location"], "/applications/" + archived_app)
        self.assertNotEqual(self.cli("sergai", extra_environment={
            "APP_ENV": "production", "APP_URL": "https://example.test", "MAIL_TRANSPORT": "mail"
        }).returncode, 0)


if __name__ == "__main__":
    unittest.main(verbosity=2)
