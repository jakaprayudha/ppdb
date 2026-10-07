"""Detailed reviews and scoped, immutable guardian correction revisions."""
import json
import os
import shutil
import sqlite3
import subprocess
import time
import unittest
import urllib.request

import operational_flow
import staff_flow
from admission_flow import PNG, PDF, AdmissionClient
from auth_flow import Client, ROOT
from review_helpers import review_payload


class CorrectionFlow(unittest.TestCase):
    setUpClass = classmethod(operational_flow.OperationalFlow.setUpClass.__func__)
    tearDownClass = classmethod(operational_flow.OperationalFlow.tearDownClass.__func__)
    stop_server = classmethod(operational_flow.OperationalFlow.stop_server.__func__)
    cli = classmethod(operational_flow.OperationalFlow.cli.__func__)
    admin_cli = classmethod(operational_flow.OperationalFlow.admin_cli.__func__)
    register = operational_flow.OperationalFlow.register
    login = operational_flow.OperationalFlow.login
    create_profile = operational_flow.OperationalFlow.create_profile
    application = operational_flow.OperationalFlow.application
    post_application = operational_flow.OperationalFlow.post_application
    upload = operational_flow.OperationalFlow.upload
    create_school = operational_flow.OperationalFlow.create_school
    school = operational_flow.OperationalFlow.school
    period_payload = operational_flow.OperationalFlow.period_payload
    version = operational_flow.OperationalFlow.version
    action = operational_flow.OperationalFlow.action
    invite = operational_flow.OperationalFlow.invite
    activate = operational_flow.OperationalFlow.activate
    payload = operational_flow.OperationalFlow.payload
    save_pack = operational_flow.OperationalFlow.save_pack
    create_pack = operational_flow.OperationalFlow.create_pack
    pack = operational_flow.OperationalFlow.pack
    decide = operational_flow.OperationalFlow.decide
    approver = operational_flow.OperationalFlow.approver
    publish = operational_flow.OperationalFlow.publish
    staff = staff_flow.StaffFlow.staff
    assign = staff_flow.StaffFlow.assign

    def setUp(self):
        operational_flow.OperationalFlow.setUp(self)
        pack = self.create_pack({"schedule[correction][start]": "2026-02-01 00:00:00"})
        self.publish(pack)
        self.assertEqual(self.action("periods", self.ops_period, "activate")[0], 303)
        profile = self.create_profile()
        path = "/applications/new?period=" + self.ops_period
        result = self.client.request(path, {"csrf": self.client.csrf(path), "profile_id": profile,
                                           "period_id": self.ops_period, "pathway": "domisili"})
        self.assertEqual(result[0], 303, result[1])
        self.app = result[2]["Location"].split("/")[-1]
        self.assertEqual(self.upload(self.app, kind="identitas")[0], 303)
        self.assertEqual(self.post_application(self.app, 4, "submit", {"declaration": "1"})[0], 303)
        self.admin_path = "/admin/applications/" + self.app

    def row(self, request):
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.row_factory = sqlite3.Row
            return dict(db.execute("SELECT * FROM correction_requests WHERE id=?", (request,)).fetchone())

    def review_version(self):
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            row = db.execute("SELECT version FROM detailed_reviews WHERE application_id=?", (self.app,)).fetchone()
            return row[0] if row else 0

    def review(self, action="verify", decision="valid", changes=None, actor=None, version=None):
        client = actor or self.admin
        return client.request(self.admin_path, {"csrf": client.csrf("/admin"), "action": action,
            "verification_version": self.review_version() if version is None else version,
            "decision": decision, "note": "Catatan pemeriksaan uji.",
            **review_payload(self, self.app, decision), **(changes or {})})

    def request(self, fields=True, docs=True, changes=None, actor=None):
        values = {"checklist[criterion_identity][status]": "needs_correction"}
        if fields:
            values["correction_fields[name]"] = "Sesuaikan nama dengan bukti identitas."
        if docs:
            values.update({"checklist[doc_identitas][status]": "needs_correction",
                           "correction_documents[identitas]": "Unggah bukti identitas yang jelas."})
        result = self.review("request-correction", "needs_correction", {**values, **(changes or {})}, actor)
        self.assertEqual(result[0], 303, result[1])
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            return db.execute("SELECT id FROM correction_requests WHERE application_id=? AND status='open'", (self.app,)).fetchone()[0]

    def post(self, request, action="save", fields=None, version=None, actor=None, extra=None):
        client = actor or self.client
        path = "/applications/" + self.app + "/corrections/" + request
        return client.request(path, {"csrf": client.csrf("/dashboard"), "action": action,
            "version": self.row(request)["version"] if version is None else version,
            **{f"fields[{key}]": value for key, value in (fields or {}).items()}, **(extra or {})})

    def replace(self, request, content=PDF, name="revisi.pdf", kind="identitas", version=None):
        path = "/applications/" + self.app + "/corrections/" + request
        return self.client.upload(path, {"csrf": self.client.csrf("/dashboard"), "action": "upload",
            "version": self.row(request)["version"] if version is None else version, "kind": kind}, name, content)

    def test_checklist_validation_complete_valid_and_optimistic_history(self):
        for query in ["?revision=invalid", "?revision[]=1"]:
            self.assertEqual(self.admin.request(self.admin_path + query)[0], 422)
        result = self.admin.request(self.admin_path, {"csrf": self.admin.csrf("/admin"), "action": "verify",
            "verification_version": 0, "decision": "valid", "note": "Semua diperiksa."})
        self.assertEqual(result[0], 422)
        for changes in [{"checklist[criterion_identity][status]": "pending"},
                        {"checklist[doc_identitas][status]": "not_applicable"},
                        {"checklist[criterion_identity][note]": "x"},
                        {"checklist[criterion_identity]": "invalid"},
                        {"checklist[extra][status]": "valid"}]:
            self.assertEqual(self.review(changes=changes)[0], 422)
        self.assertEqual(self.review("save-checklist", changes={"checklist[criterion_identity][status]": "pending"})[0], 303)
        stale = self.review(version=0)
        self.assertEqual(stale[0], 409)
        self.assertIn('name="verification_version" value="0"', stale[1])
        self.assertEqual(self.review("save-checklist", changes={"note": "x" * 2001})[0], 422)
        for decision in ["invalid", "needs_correction"]:
            self.assertEqual(self.review(decision=decision, changes=review_payload(self, self.app, "valid"))[0], 422)
        self.assertEqual(self.review()[0], 303)
        self.assertEqual(self.review("save-checklist")[0], 409)
        self.assertEqual(self.review(decision="invalid")[0], 303)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertEqual(db.execute("SELECT count(*) FROM detailed_review_history WHERE application_id=?", (self.app,)).fetchone()[0], 3)
            self.assertEqual(db.execute("SELECT count(*) FROM verification_history WHERE application_id=?", (self.app,)).fetchone()[0], 2)

    def test_restricted_fields_upload_submit_recheck_and_initial_integrity(self):
        before = self.application(self.app)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            original_doc = db.execute("SELECT id,storage_name,sha256 FROM application_documents WHERE application_id=? AND deleted_at IS NULL", (self.app,)).fetchone()
        request = self.request()
        self.assertEqual(self.post(request, fields={"name": "Nama Revisi", "phone": "081234567891"})[0], 422)
        self.assertEqual(self.post(request, fields={"name": "Nama Revisi"}, extra={"pathway": "afirmasi"})[0], 422)
        self.assertEqual(self.post(request, fields={"name": "Nama Revisi"}, version=999)[0], 409)
        self.assertEqual(self.post(request, fields={"name": "N"})[0], 422)
        status, body, _ = self.post(request, fields={"name": "N"})
        self.assertEqual(status, 422)
        self.assertIn('value="N"', body)
        self.assertIn("Lihat berkas saat ini", body)
        self.assertNotIn("Belum ada permintaan koreksi.", body)
        self.assertEqual(self.replace(request, kind="tidak-ada")[0], 422)
        self.assertEqual(self.post(request, "submit", extra={"declaration": "1"})[0], 422)
        self.assertEqual(self.post(request, fields={"name": "Nama Revisi"})[0], 303)
        self.assertEqual(self.replace(request)[0], 303)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            replacement = json.loads(self.row(request)["document_ids_json"])["identitas"]
        self.assertEqual(self.admin.request("/documents/" + replacement)[0], 404)
        status, body, _ = self.client.raw_request(urllib.request.Request(self.base + "/documents/" + replacement))
        self.assertEqual((status, body), (200, PDF))
        self.assertEqual(self.review()[0], 409)
        self.assertEqual(self.post(request, "submit")[0], 422)
        self.assertEqual(self.post(request, "submit", extra={"declaration": "1"})[0], 303)
        self.assertEqual(self.post(request, "submit", version=1, extra={"declaration": "1"})[0], 303)
        self.assertEqual(self.post(request, fields={"name": "Tidak boleh lagi"})[0], 409)
        self.assertEqual(self.application(self.app), before)
        self.assertEqual(self.admin.raw_request(urllib.request.Request(self.base + "/documents/" + replacement))[1], PDF)
        self.assertEqual(self.client.raw_request(urllib.request.Request(self.base + "/documents/" + original_doc[0]))[1], PNG)
        self.assertIn("Nama Revisi", self.admin.request(self.admin_path)[1])
        self.assertNotIn("Nama Revisi", self.admin.request(self.admin_path + "?revision=0")[1])
        queued = self.admin.request("/admin/applications?status=pending&q=Nama%20Revisi")[1]
        self.assertIn("/admin/applications/" + self.app, queued)
        self.assertIn("Revisi 1", queued)
        dashboard = self.client.request("/dashboard")[1]
        self.assertIn("Nama Revisi", dashboard)
        self.assertIn("Revisi 1", dashboard)
        self.assertEqual(self.review(version=1)[0], 409)
        self.assertEqual(self.review()[0], 303)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertEqual(db.execute("SELECT revision FROM application_revisions WHERE application_id=? ORDER BY revision", (self.app,)).fetchall(), [(0,), (1,)])
            self.assertEqual(db.execute("SELECT sha256 FROM application_documents WHERE id=?", (original_doc[0],)).fetchone()[0], original_doc[2])
        self.assertIn("Tanda terima", self.client.request("/applications/" + self.app + "/receipt")[1])
        self.assertIn("Revisi 1", self.client.request("/applications/" + self.app + "/corrections")[1])

    def test_multiple_cycles_and_archived_period_keep_snapshots(self):
        before = self.application(self.app)
        first = self.request(docs=False)
        self.assertEqual(self.post(first, fields={"name": "Nama Revisi Pertama"})[0], 303)
        self.assertEqual(self.post(first, "submit", extra={"declaration": "1"})[0], 303)
        second = self.request(docs=False)
        self.assertEqual(self.row(second)["base_revision"], 1)
        self.assertEqual(self.action("periods", self.ops_period, "archive")[0], 303)
        self.assertEqual(self.post(second, fields={"name": "Nama Revisi Kedua"})[0], 303)
        self.assertEqual(self.post(second, "submit", extra={"declaration": "1"})[0], 303)
        self.assertEqual(self.review()[0], 303)
        self.assertIn("Nama Revisi Pertama", self.admin.request(self.admin_path + "?revision=1")[1])
        self.assertIn("Nama Revisi Kedua", self.admin.request(self.admin_path)[1])
        self.assertEqual(self.application(self.app), before)
        self.assertEqual(self.admin.request(self.admin_path + "?revision=0", {
            "csrf": self.admin.csrf("/admin"), "action": "verify", "verification_version": self.review_version(),
            "decision": "valid", "note": "Dilarang arsip", **review_payload(self, self.app),
        })[0], 409)

    def test_window_boundaries_no_package_and_expired_decision(self):
        original_rules = self.application(self.app)["rule_snapshot_json"]
        now = int(time.time())
        def window(start, end):
            rules = json.loads(original_rules)
            for key, value in [("start", start), ("end", end)]:
                rules["operational"]["data"]["schedule"]["correction"][key] = time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime(value + 7 * 3600))
            with sqlite3.connect(self.storage / "app.sqlite") as db:
                db.execute("UPDATE applications SET rule_snapshot_json=? WHERE id=?", (json.dumps(rules), self.app))
        changes = {"checklist[criterion_identity][status]": "needs_correction",
                   "correction_fields[name]": "Perbaiki identitas sesuai bukti."}
        window(now + 3600, now + 7200)
        self.assertEqual(self.review("request-correction", "needs_correction", changes)[0], 409)
        window(now - 7200, now - 1)
        self.assertEqual(self.review("request-correction", "needs_correction", changes)[0], 409)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            rules = json.loads(original_rules)
            del rules["operational"]
            db.execute("UPDATE applications SET rule_snapshot_json=? WHERE id=?", (json.dumps(rules), self.app))
        self.assertEqual(self.review("request-correction", "needs_correction", changes)[0], 409)
        window(now - 3600, now + 3600)
        request = self.request(docs=False)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("UPDATE correction_requests SET deadline=? WHERE id=?", (int(time.time()), request))
        self.assertEqual(self.post(request, fields={"name": "Nama Lewat"})[0], 409)
        self.assertEqual(self.replace(request)[0], 409)
        self.assertEqual(self.post(request, "submit", extra={"declaration": "1"})[0], 409)
        self.assertEqual(self.review(decision="invalid")[0], 303)
        self.assertEqual(self.row(request)["status"], "expired")
        self.assertIn("Tenggat berakhir", self.client.request("/applications/" + self.app + "/corrections")[1])

    def test_scope_notifications_csrf_and_production(self):
        _, staff, reviewer, _, _ = self.staff("verifier", [self.school_id], "correction-staff")
        self.assertEqual(self.review(actor=staff)[0], 404)
        self.assertEqual(self.assign(self.admin, self.app, reviewer)[0], 303)
        request = self.request(actor=staff, docs=False)
        outsider = AdmissionClient(self.base)
        email = "correction-outsider@example.test"
        self.assertEqual(outsider.request("/register", {
            "csrf": outsider.csrf("/register"), "name": "Wali Di Luar Scope", "email": email,
            "password": "password-uji-awal-123", "password_confirmation": "password-uji-awal-123", "privacy": "1",
        })[0], 303)
        self.assertEqual(self.login(outsider, email)[0], 303)
        path = "/applications/" + self.app + "/corrections/" + request
        self.assertEqual(outsider.request(path)[0], 404)
        self.assertEqual(outsider.request(path, {"csrf": outsider.csrf("/dashboard"), "action": "save", "version": 1,
                                               "fields[name]": "Tidak berwenang"})[0], 404)
        self.assertEqual(self.client.request(path, {"csrf": "bad", "version": 1, "action": "save"})[0], 419)
        self.assertIn("Panitia meminta", self.client.request("/dashboard")[1])
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            notice = db.execute("SELECT id FROM participant_notices WHERE application_id=?", (self.app,)).fetchone()[0]
        self.assertEqual(outsider.request("/applications/notifications", {"csrf": outsider.csrf("/dashboard"), "notice_id": notice})[0], 404)
        self.assertEqual(self.client.request("/applications/notifications", {"csrf": self.client.csrf("/dashboard"), "notice_id": notice})[0], 303)
        self.assertEqual(self.client.request("/applications/notifications", {"csrf": "bad", "notice_id": notice})[0], 419)
        self.assertEqual(self.assign(self.admin, self.app, 0, 1)[0], 303)
        self.assertEqual(self.review(actor=staff)[0], 404)
        session = next(cookie.value for cookie in self.client.cookies if cookie.name == "spmb_session")
        code = """session_id($argv[1]); $_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REQUEST_URI']=$argv[3];
            $_POST=['csrf'=>$argv[2],'version'=>'1','action'=>'submit','declaration'=>'1'];
            register_shutdown_function(function(){echo '\\nSTATUS='.http_response_code();}); require 'public/index.php';"""
        environment = dict(os.environ, APP_ENV="production", APP_URL="https://example.test", APP_STORAGE=str(self.storage), MAIL_TRANSPORT="mail")
        result = subprocess.run([shutil.which("php"), "-r", code, session, self.client.csrf("/dashboard"), path],
                                cwd=ROOT, env=environment, capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("STATUS=403", result.stdout)

    def test_correction_selection_reasons_and_upload_integrity(self):
        for changes in [
            {"correction_fields[pathway]": "Pilihan tidak boleh berubah"},
            {"correction_fields[name]": "x"},
            {"checklist[criterion_identity][status]": "valid", "correction_fields[name]": "Nama sesuai bukti"},
            {"correction_documents[identitas]": "Unggah baru"},
            {"correction_fields[name][bad]": "Tidak valid"},
        ]:
            self.assertEqual(self.review("request-correction", "needs_correction", changes)[0], 422)
        request = self.request(fields=False)
        self.assertEqual(self.replace(request, b"not-png", "fake.png")[0], 422)
        self.assertEqual(self.replace(request, b"x" * (2 * 1024 * 1024 + 1), "large.pdf")[0], 413)
        self.assertEqual(self.replace(request)[0], 303)
        first = json.loads(self.row(request)["document_ids_json"])["identitas"]
        self.assertEqual(self.replace(request, PNG, "second.png")[0], 303)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            second = json.loads(self.row(request)["document_ids_json"])["identitas"]
            stored = db.execute("SELECT storage_name FROM application_documents WHERE id=?", (second,)).fetchone()[0]
        self.assertNotEqual(first, second)
        self.assertEqual(self.admin.request("/documents/" + first)[0], 404)
        (self.storage / "documents" / stored).write_bytes(b"tampered")
        result = self.post(request, "submit", extra={"declaration": "1"})
        self.assertEqual(result[0], 503)
        self.assertEqual(self.row(request)["status"], "open")


if __name__ == "__main__":
    unittest.main()
