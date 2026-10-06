"""Central admin integration tests with isolated accounts and submitted fixtures."""

import json
import os
import re
import shutil
import sqlite3
import subprocess
import unittest
import urllib.request

import admission_flow as admission_helpers
import auth_flow as auth_helpers
from auth_flow import Client, ROOT
from staff_helpers import complete_central_security


class AdminFlow(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        admission_helpers.AdmissionFlow.setUpClass.__func__(cls)
        cls.seed = cls.admin_cli()
        if cls.seed.returncode:
            cls.stop_server()
            raise RuntimeError(cls.seed.stderr)
        cls.admin_password = re.search(r"Password: (.+)", cls.seed.stdout).group(1)
        cls.central_security = {}

    cli = classmethod(admission_helpers.AdmissionFlow.cli.__func__)
    stop_server = classmethod(auth_helpers.AuthFlow.stop_server.__func__)
    tearDownClass = classmethod(auth_helpers.AuthFlow.tearDownClass.__func__)
    register = auth_helpers.AuthFlow.register
    login = auth_helpers.AuthFlow.login
    create_profile = admission_helpers.AdmissionFlow.create_profile
    create_application = admission_helpers.AdmissionFlow.create_application
    application = admission_helpers.AdmissionFlow.application
    upload = admission_helpers.AdmissionFlow.upload
    active_document = admission_helpers.AdmissionFlow.active_document
    post_application = admission_helpers.AdmissionFlow.post_application

    @classmethod
    def admin_cli(cls, **extra):
        environment = dict(os.environ, APP_ENV="development", APP_URL=cls.base,
                           APP_STORAGE=str(cls.storage), MAIL_TRANSPORT="file", **extra)
        return subprocess.run([shutil.which("php"), "bin/admin.php", "seed"], cwd=ROOT,
                              env=environment, capture_output=True, text=True, timeout=10)

    def setUp(self):
        admission_helpers.AdmissionFlow.setUp(self)
        self.admin = admission_helpers.AdmissionClient(self.base)
        self.assertEqual(self.login(self.admin, "admin.pusat@example.test", self.admin_password)[0], 303)
        complete_central_security(self)

    def submit_fixture(self):
        app_id = self.create_application(self.create_profile())
        self.assertEqual(self.upload(app_id)[0], 303)
        self.assertEqual(self.upload(app_id, admission_helpers.PDF, "school.pdf", "sekolah-demo")[0], 303)
        self.assertEqual(self.post_application(app_id, 4, "submit", {"declaration": "1"})[0], 303)
        return app_id

    def decide(self, app_id, status="valid", note="Dokumen telah diperiksa.", version=0, csrf=None):
        path = "/admin/applications/" + app_id
        return self.admin.request(path, {
            "csrf": csrf if csrf is not None else self.admin.csrf(path),
            "verification_version": version, "decision": status, "note": note
        })

    def test_role_guards_and_admin_navigation(self):
        for path in ["/admin", "/admin/applications", "/admin/master-data/periods", "/admin/master-data/schools", "/admin/accounts", "/admin/audit"]:
            self.assertEqual(Client(self.base).request(path)[0], 303)
            self.assertEqual(self.client.request(path)[0], 403)
            status, body, _ = self.admin.request(path)
            self.assertEqual(status, 200, body)
            self.assertIn("ADMIN PUSAT", body)
        self.assertEqual(self.admin.request("/dashboard")[2]["Location"], "/admin")
        self.assertEqual(self.admin.request("/participants/new")[2]["Location"], "/admin")
        self.assertEqual(self.admin.request("/admin/master-data/periods", {})[0], 405)
        self.assertEqual(self.admin.request("/admin/not-found")[0], 404)
        self.assertEqual(self.admin.request("/admin/applications?page=invalid")[0], 422)
        self.assertEqual(self.admin.request("/admin/applications?status=invalid-state")[0], 422)
        result = self.admin_cli()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("Password:", result.stdout)
        environment = dict(os.environ, APP_ENV="production", APP_URL="https://example.test",
                           APP_STORAGE=str(self.storage), MAIL_TRANSPORT="mail")
        result = subprocess.run([shutil.which("php"), "bin/admin.php", "seed"], cwd=ROOT,
                                env=environment, capture_output=True, text=True, timeout=10)
        self.assertNotEqual(result.returncode, 0)

    def test_verification_and_guardian_status_with_immutable_submission(self):
        app_id = self.submit_fixture()
        before = self.application(app_id)
        document_id = self.active_document(app_id)
        status, contents, _ = self.admin.raw_request(urllib.request.Request(self.base + "/documents/" + document_id))
        self.assertEqual(status, 200)
        self.assertEqual(contents, admission_helpers.PNG)
        self.assertEqual(self.client.request("/admin/applications/" + app_id)[0], 403)
        self.assertEqual(self.decide(app_id, csrf="invalid")[0], 419)
        self.assertEqual(self.decide(app_id, status="accepted")[0], 422)
        self.assertEqual(self.decide(app_id, note="")[0], 422)
        self.assertEqual(self.decide(app_id, version=-1)[0], 409)
        self.assertEqual(self.decide(app_id)[0], 303)
        self.assertEqual(self.decide(app_id, status="invalid", version=0)[0], 409)
        note = "Periksa kembali dokumen <script>alert(1)</script>."
        self.assertEqual(self.decide(app_id, status="needs_correction", note=note, version=1)[0], 303)
        for path in [f"/applications/{app_id}?step=4", f"/applications/{app_id}/receipt"]:
            status, body, _ = self.client.request(path)
            self.assertEqual(status, 200, body)
            self.assertIn("Perlu perbaikan", body)
            self.assertIn("&lt;script&gt;", body)
            self.assertNotIn("<script>alert(1)</script>", body)
        self.assertIn("Perlu perbaikan", self.client.request("/dashboard")[1])
        self.assertEqual(self.post_application(app_id, 1, "save", self.participant)[0], 409)
        self.assertEqual(self.upload(app_id)[0], 409)
        self.assertEqual(self.application(app_id), before)
        self.assertEqual(self.decide(app_id, status="invalid", version=2)[0], 303)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertEqual(db.execute("SELECT count(*) FROM verification_history WHERE application_id=?", (app_id,)).fetchone()[0], 3)
            self.assertEqual(db.execute("SELECT status, version FROM application_verifications WHERE application_id=?", (app_id,)).fetchone(), ("invalid", 3))
        body = self.admin.request("/admin/applications?status=invalid&q=" + before["registration_number"])[1]
        self.assertIn("/admin/applications/" + app_id, body)
        body = self.admin.request("/admin/applications?status=pending&q=" + before["registration_number"])[1]
        self.assertNotIn("/admin/applications/" + app_id, body)

    def test_drafts_not_reviewable_and_nonowner_documents_blocked(self):
        app_id = self.create_application(self.create_profile())
        self.assertEqual(self.upload(app_id)[0], 303)
        document_id = self.active_document(app_id)
        self.assertEqual(self.admin.request("/admin/applications/" + app_id)[0], 404)
        self.assertEqual(self.admin.request("/documents/" + document_id)[0], 404)
        self.assertEqual(self.admin.request("/admin/applications/" + app_id, {
            "csrf": self.admin.csrf("/admin"), "decision": "valid", "note": "Tidak boleh", "verification_version": 0
        })[0], 404)
        outsider = Client(self.base)
        email = "outsider-admin-doc@example.test"
        self.assertEqual(outsider.request("/register", {
            "csrf": outsider.csrf("/register"), "name": "Wali Lain", "email": email,
            "password": "password-uji-awal-123", "password_confirmation": "password-uji-awal-123",
            "privacy": "1", "role": "central_admin"
        })[0], 303)
        self.assertEqual(self.login(outsider, email)[0], 303)
        self.assertEqual(outsider.request("/admin")[0], 403)
        self.assertEqual(outsider.request("/documents/" + document_id)[0], 404)

    def test_production_admin_and_document_access_disabled(self):
        app_id = self.submit_fixture()
        document_id = self.active_document(app_id)
        session_id = next(cookie.value for cookie in self.admin.cookies if cookie.name == "spmb_session")
        csrf = self.admin.csrf("/admin")
        environment = dict(os.environ, APP_ENV="production", APP_URL="https://example.test",
                           APP_STORAGE=str(self.storage), MAIL_TRANSPORT="mail")
        for path, method in [("/admin", "GET"), ("/admin/applications/" + app_id, "POST"),
                             ("/documents/" + document_id, "GET")]:
            code = """
                session_id($argv[1]);
                $_SERVER['REQUEST_METHOD'] = $argv[3]; $_SERVER['REQUEST_URI'] = $argv[2];
                $_POST = ['csrf' => $argv[4], 'decision' => 'valid', 'note' => 'Dokumen valid', 'verification_version' => '0'];
                register_shutdown_function(function () { echo '\\nSTATUS=' . http_response_code(); });
                require 'public/index.php';
            """
            result = subprocess.run([shutil.which("php"), "-r", code, session_id, path, method, csrf],
                                    cwd=ROOT, env=environment, capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("STATUS=403", result.stdout)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertEqual(db.execute("SELECT count(*) FROM application_verifications WHERE application_id=?", (app_id,)).fetchone()[0], 0)

    def test_z_master_data_covers_regional_catalogue(self):
        self.assertEqual(self.cli("sergai").returncode, 0)
        self.assertEqual(self.admin.request("/admin/master-data")[0], 303)
        status, body, _ = self.admin.request("/admin/master-data/periods")
        self.assertEqual(status, 200, body)
        self.assertIn('class="master-table"', body)
        self.assertIn("Berikutnya", body)
        body = self.admin.request("/admin/master-data/schools?q=10209337")[1]
        self.assertIn("10209337", body)
        self.assertIn("40 periode aktif", self.admin.request("/admin")[1])
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            archived_id = db.execute("SELECT id FROM applications WHERE status='submitted' LIMIT 1").fetchone()[0]
        self.assertEqual(self.admin.request("/admin/applications/" + archived_id)[0], 200)
