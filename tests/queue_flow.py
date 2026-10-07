"""Scoped work queues, assignment conflicts, snapshot deadlines and duplicate review."""

import concurrent.futures
import hashlib
import json
import os
import shutil
import sqlite3
import subprocess
import time
import unittest
import urllib.request
import uuid
from urllib.parse import urlencode

import admin_flow
import staff_flow
from auth_flow import Client, ROOT
from review_helpers import review_payload


class QueueFlow(unittest.TestCase):
    setUpClass = classmethod(admin_flow.AdminFlow.setUpClass.__func__)
    tearDownClass = classmethod(admin_flow.AdminFlow.tearDownClass.__func__)
    stop_server = classmethod(admin_flow.AdminFlow.stop_server.__func__)
    cli = classmethod(admin_flow.AdminFlow.cli.__func__)
    admin_cli = classmethod(admin_flow.AdminFlow.admin_cli.__func__)
    register = admin_flow.AdminFlow.register
    login = admin_flow.AdminFlow.login
    create_profile = admin_flow.AdminFlow.create_profile
    create_application = admin_flow.AdminFlow.create_application
    application = admin_flow.AdminFlow.application
    upload = admin_flow.AdminFlow.upload
    active_document = admin_flow.AdminFlow.active_document
    post_application = admin_flow.AdminFlow.post_application
    submit_fixture = staff_flow.StaffFlow.submit_fixture
    schools = staff_flow.StaffFlow.schools
    invite = staff_flow.StaffFlow.invite
    activate = staff_flow.StaffFlow.activate
    staff = staff_flow.StaffFlow.staff
    assign = staff_flow.StaffFlow.assign
    email_for = staff_flow.StaffFlow.email_for

    def setUp(self):
        staff_flow.StaffFlow.setUp(self)
        suffix = hashlib.sha256(self.id().encode()).hexdigest()[:10]
        self.participant["name"] = "Peserta Antrean " + suffix
        self.app = self.submit_fixture()
        self.own, _ = self.schools()

    def clone(self, changes=None, period=None, status="submitted", submitted=None):
        source = self.application(self.app)
        identity = {**json.loads(source["data_json"]), **(changes or {})}
        app, profile = uuid.uuid4().hex, uuid.uuid4().hex
        now = int(time.time())
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("INSERT INTO participant_profiles(id,user_id,data_json,created_at,updated_at) VALUES(?,?,?,?,?)",
                       (profile, source["user_id"], json.dumps(identity), now, now))
            db.execute("""INSERT INTO applications(id,user_id,profile_id,period_id,pathway,data_json,status,
                registration_number,rule_snapshot_json,created_at,updated_at,submitted_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?)""",
                       (app, source["user_id"], profile, period or source["period_id"], source["pathway"],
                        json.dumps(identity), status, "QUEUE-" + app, source["rule_snapshot_json"], now, now,
                        submitted if submitted is not None else now))
            docs = db.execute("SELECT kind,original_name,storage_name,mime_type,size,sha256 FROM application_documents WHERE application_id=? AND deleted_at IS NULL",
                              (self.app,)).fetchall()
            for kind, name, stored, mime, size, digest in docs:
                doc = uuid.uuid4().hex
                replacement = doc + "." + stored.split(".")[-1]
                shutil.copyfile(self.storage / "documents" / stored, self.storage / "documents" / replacement)
                db.execute("INSERT INTO application_documents(id,application_id,kind,original_name,storage_name,mime_type,size,sha256,uploaded_at) VALUES(?,?,?,?,?,?,?,?,?)",
                           (doc, app, kind, name, replacement, mime, size, digest, now))
        return app

    def claim(self, client, app=None, version=0, csrf=None):
        return client.request("/admin/queue/" + (app or self.app) + "/claim", {
            "csrf": csrf if csrf is not None else client.csrf("/admin"),
            "assignment_version": version,
        })

    def table(self, body):
        return body.split("<tbody>", 1)[1].split("</tbody>", 1)[0]

    def test_claim_minimum_visibility_race_and_documents(self):
        _, first, first_id, _, _ = self.staff("verifier", [self.own], "first")
        _, second, second_id, _, _ = self.staff("verifier", [self.own], "second")
        document = self.active_document(self.app)
        available = first.request("/admin/queue?queue=available")
        self.assertEqual(available[0], 200, available[1])
        self.assertIn("/admin/queue/" + self.app + "/claim", available[1])
        self.assertNotIn(self.participant["name"], available[1])
        self.assertNotIn(self.email, available[1])
        self.assertNotIn("/documents/", available[1])
        self.assertEqual(first.request("/admin/applications/" + self.app)[0], 404)
        self.assertEqual(first.request("/documents/" + document)[0], 404)
        self.assertEqual(self.claim(first, csrf="invalid")[0], 419)
        tokens = [first.csrf("/admin"), second.csrf("/admin")]
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
            futures = [executor.submit(self.claim, client, csrf=token)
                       for client, token in zip([first, second], tokens)]
            responses = [future.result() for future in futures]
        self.assertEqual(sorted(response[0] for response in responses), [303, 409])
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            winner_id = db.execute("SELECT reviewer_id FROM verification_assignments WHERE application_id=?", (self.app,)).fetchone()[0]
            self.assertIn(winner_id, [first_id, second_id])
            self.assertEqual(db.execute("SELECT count(*) FROM assignment_history WHERE application_id=?", (self.app,)).fetchone()[0], 1)
        winner, loser = (first, second) if winner_id == first_id else (second, first)
        workload = self.admin.request("/admin/applications?" + urlencode({
            "reviewer": winner_id, "q": self.application(self.app)["registration_number"],
        }))
        self.assertEqual(workload[0], 200, workload[1])
        self.assertIn("1 pendaftaran ditemukan", workload[1])
        self.assertRegex(workload[1], r"<span>Sedang ditangani</span><strong>1</strong>")
        self.assertRegex(workload[1], r"<span>Belum ditugaskan</span><strong>0</strong>")
        self.assertEqual(winner.request("/admin/applications/" + self.app)[0], 200)
        self.assertEqual(winner.raw_request(urllib.request.Request(self.base + "/documents/" + document))[0], 200)
        self.assertEqual(loser.request("/documents/" + document)[0], 404)
        self.assertNotIn("/admin/queue/" + self.app + "/claim", loser.request("/admin/queue?queue=available")[1])
        self.assertEqual(self.claim(winner, version=1)[0], 409)

    def test_claim_scope_drafts_completed_and_role_guards(self):
        self.assertEqual(self.cli("sergai").returncode, 0)
        own, others = self.schools()
        _, verifier, _, _, _ = self.staff("verifier", [own], "own")
        _, outsider, _, _, _ = self.staff("verifier", [others[0]], "outside")
        self.assertEqual(self.claim(outsider)[0], 404)
        self.assertEqual(self.claim(self.admin)[0], 403)
        self.assertEqual(self.claim(self.client, csrf=self.client.csrf("/dashboard"))[0], 403)
        draft = self.clone(status="draft")
        self.assertEqual(self.claim(verifier, app=draft)[0], 404)
        self.assertEqual(self.admin.request("/admin/applications/" + draft)[0], 404)
        completed = self.clone()
        result = self.admin.request("/admin/applications/" + completed, {
            "csrf": self.admin.csrf("/admin"), "verification_version": 0,
            "decision": "valid", "note": "Dokumen uji diperiksa.", **review_payload(self,completed),
        })
        self.assertEqual(result[0], 303, result[1])
        self.assertEqual(self.claim(verifier, app=completed)[0], 409)
        self.assertNotIn("/admin/queue/" + completed + "/claim", verifier.request("/admin/queue?queue=available")[1])
        path = "/admin/queue/" + self.app + "/claim"
        self.assertEqual(verifier.request(path)[0], 405)
        self.assertEqual(verifier.request("/admin/queue?queue=available&q=peserta")[0], 422)
        self.assertEqual(verifier.request("/admin/queue?queue=available&duplicate=potential")[0], 422)
        self.assertEqual(verifier.request("/admin/applications?reviewer=1")[0], 422)

    def test_reassignment_release_notes_history_and_revocation(self):
        _, verifier, reviewer, _, _ = self.staff("verifier", [self.own], "reviewer")
        _, other, other_id, _, _ = self.staff("verifier", [self.own], "other")
        self.assertEqual(self.claim(verifier)[0], 303)
        path = "/admin/applications/" + self.app
        values = {"csrf": self.admin.csrf("/admin"), "action": "assign",
                  "assignment_version": 1, "reviewer_id": other_id}
        invalid = self.admin.request(path, values)
        self.assertEqual(invalid[0], 422)
        self.assertIn(f'<option value="{other_id}" selected>', invalid[1])
        note = "Pengambilalihan tugas <script>uji</script>."
        self.assertEqual(self.admin.request(path, {**values, "assignment_note": note})[0], 303)
        self.assertEqual(self.admin.request(path, {**values, "assignment_note": note})[0], 409)
        self.assertEqual(verifier.request(path)[0], 404)
        self.assertEqual(other.request(path)[0], 200)
        self.assertEqual(self.assign(self.admin, self.app, 0, 2)[0], 303)
        self.assertEqual(other.request(path)[0], 404)
        self.assertEqual(self.claim(verifier, version=3)[0], 303)
        result = self.admin.request(f"/admin/accounts/{reviewer}", {
            "csrf": self.admin.csrf("/admin"), "version": 1, "role": "verifier",
            "schools[0]": self.own,
        })
        self.assertEqual(result[0], 303, result[1])
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            history = db.execute("SELECT action FROM assignment_history WHERE application_id=? ORDER BY id", (self.app,)).fetchall()
            self.assertEqual([row[0] for row in history], ["claim", "reassign", "release", "claim", "access_revoked"])
            self.assertEqual(db.execute("SELECT reviewer_id,version FROM verification_assignments WHERE application_id=?", (self.app,)).fetchone(), (None, 5))
        body = self.admin.request(path)[1]
        self.assertIn("&lt;script&gt;uji&lt;/script&gt;", body)
        self.assertNotIn("<script>uji</script>", body)
        self.assertNotIn("Pengambilalihan", self.client.request("/applications/" + self.app)[1])

    def test_duplicate_matches_scopes_years_and_no_automatic_decision(self):
        name_peer = self.clone({"name": "  " + self.participant["name"].upper() + "  "})
        unrelated = self.clone({"name": "Nama Tidak Sama", "birth_date": "2013-01-01"})
        draft = self.clone(status="draft")
        body = self.admin.request("/admin/applications?duplicate=potential&q=" + urlencode({"q": self.participant["name"]})[2:])[1]
        self.assertIn("/admin/applications/" + self.app, body)
        self.assertIn("/admin/applications/" + name_peer, body)
        self.assertNotIn("/admin/applications/" + unrelated, body)
        self.assertNotIn("/admin/applications/" + draft, body)
        details = self.admin.request("/admin/applications/" + self.app)[1]
        self.assertIn("Nama dan tanggal lahir sama", details)
        _, verifier, reviewer, _, _ = self.staff("verifier", [self.own], "dupe")
        self.assertEqual(self.assign(self.admin, self.app, reviewer)[0], 303)
        self.assertNotIn("/admin/applications/" + name_peer, verifier.request("/admin/applications/" + self.app)[1])
        self.assertNotIn(self.app, self.table(verifier.request("/admin/applications?duplicate=potential")[1]))
        self.assertEqual(self.assign(self.admin, name_peer, reviewer)[0], 303)
        self.assertIn("/admin/applications/" + name_peer, verifier.request("/admin/applications/" + self.app)[1])
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertEqual(db.execute("SELECT count(*) FROM application_verifications WHERE application_id IN (?,?)", (self.app, name_peer)).fetchone()[0], 0)
            identity = json.loads(self.application(unrelated)["data_json"])
            identity["nisn"] = "1234567890"
            for app in [self.app, unrelated]:
                data = json.loads(self.application(app)["data_json"])
                data["nisn"] = identity["nisn"]
                db.execute("UPDATE applications SET data_json=? WHERE id=?", (json.dumps(data), app))
        self.assertIn("NISN sama", self.admin.request("/admin/applications/" + self.app)[1])
        self.assertEqual(self.cli("sergai").returncode, 0)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            other_period = db.execute("SELECT id FROM admission_periods WHERE id<>? LIMIT 1", (self.period_id,)).fetchone()[0]
            db.execute("UPDATE admission_periods SET academic_year='2030/2031' WHERE id=?", (other_period,))
        next_year = self.clone(period=other_period)
        self.assertNotIn("/admin/applications/" + next_year, self.admin.request("/admin/applications/" + self.app)[1])

    def test_snapshot_deadlines_summary_pending_only_and_no_fallback(self):
        past, future = self.clone({"name": "Antrean Lewat"}), self.clone({"name": "Antrean Masa Depan"})
        done = self.clone({"name": "Antrean Selesai"})
        now = int(time.time())
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            for app, deadline in [(past, now - 3600), (future, now + 3600), (done, now - 3600)]:
                snapshot = json.loads(self.application(app)["rule_snapshot_json"])
                snapshot["operational"] = {"data": {"timezone": "Asia/Jakarta", "schedule": {"verification": {
                    "end": time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime(deadline + 7 * 3600)),
                }}}}
                db.execute("UPDATE applications SET rule_snapshot_json=? WHERE id=?", (json.dumps(snapshot), app))
            db.execute("INSERT INTO application_verifications(application_id,status,note,reviewer_id,updated_at) VALUES(?,?,?,?,?)",
                       (done, "valid", "Selesai", self.application(done)["user_id"], now))
        response = self.admin.request("/admin/applications?queue=overdue")
        self.assertEqual(response[0], 200, response[1])
        table = self.table(response[1])
        self.assertIn("/admin/applications/" + past, table)
        for app in [self.app, future, done]:
            self.assertNotIn("/admin/applications/" + app, table)
        self.assertRegex(response[1], r"<span>Terlambat</span><strong>1</strong>")
        self.assertIn("Tenggat belum diatur", self.admin.request("/admin/applications?q=" + self.application(self.app)["registration_number"])[1])

    def test_duplicate_cross_school_scope_and_unicode_names(self):
        self.assertEqual(self.cli("sergai").returncode, 0)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            period, school = db.execute("SELECT p.id,l.school_id FROM admission_periods p JOIN period_school_links l ON l.period_id=p.id WHERE p.id<>? LIMIT 1",
                                        (self.period_id,)).fetchone()
            data = json.loads(self.application(self.app)["data_json"])
            data["name"] = "École Antrean Unicode"
            db.execute("UPDATE applications SET data_json=? WHERE id=?", (json.dumps(data), self.app))
        peer = self.clone({"name": "  école   antrean unicode  "}, period=period)
        _, school_admin, _, _, _ = self.staff("school_admin", [self.own], "one-school")
        _, multi, _, _, _ = self.staff("school_admin", [self.own, school], "two-schools")
        self.assertNotIn("/admin/applications/" + self.app,
                         self.table(school_admin.request("/admin/applications?duplicate=potential")[1]))
        self.assertNotIn("/admin/applications/" + peer, school_admin.request("/admin/applications/" + self.app)[1])
        multi_body = multi.request("/admin/applications?duplicate=potential")[1]
        self.assertIn("/admin/applications/" + self.app, multi_body)
        self.assertIn("/admin/applications/" + peer, multi_body)
        self.assertIn("Nama dan tanggal lahir sama", multi.request("/admin/applications/" + self.app)[1])
        self.assertEqual(school_admin.request("/admin/applications?period=" + period)[0], 422)
        _, outside, outside_id, _, _ = self.staff("verifier", [school], "other-school")
        self.assertEqual(school_admin.request("/admin/applications?reviewer=" + str(outside_id))[0], 422)
        self.assertNotIn("Petugas Uji other-school", school_admin.request("/admin/applications")[1])
        self.assertNotIn("/admin/queue/" + self.app + "/claim", outside.request("/admin/queue?queue=available")[1])

    def test_exact_pagination_search_sort_filters_and_validation(self):
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            data = json.loads(self.application(self.app)["data_json"])
            data["name"] = "Pagination-Uji " + data["name"]
            db.execute("UPDATE applications SET data_json=? WHERE id=?", (json.dumps(data), self.app))
        self.clone({"name": "Pagination-Uji Aaa Peserta <script>uji</script>"}, submitted=1)
        self.clone({"name": "Pagination-Uji Zzz Peserta"}, submitted=2)
        for index in range(24):
            self.clone({"name": f"Pagination-Uji Peserta Baris {index:02d}"}, submitted=100 + index)
        query = {"q": "Pagination-Uji", "period": self.period_id, "year": "2026/2027", "pathway": self.application(self.app)["pathway"], "sort": "oldest"}
        first = self.admin.request("/admin/applications?" + urlencode(query))[1]
        self.assertEqual(self.table(first).count("<tr>"), 25)
        self.assertIn("Aaa Peserta &lt;script&gt;uji&lt;/script&gt;", first)
        self.assertLess(first.index("Aaa Peserta"), first.index("Zzz Peserta"))
        second = self.admin.request("/admin/applications?" + urlencode({**query, "page": "2"}))[1]
        self.assertEqual(self.table(second).count("<tr>"), 2)
        self.assertIn("sort=oldest", second)
        self.assertIn("pathway=", second)
        filtered = self.admin.request("/admin/applications?" + urlencode({"q": "Aaa Peserta", "sort": "name"}))[1]
        self.assertIn("1 pendaftaran ditemukan", filtered)
        for query in ["sort=invalid","queue=invalid","year=invalid","pathway=invalid","reviewer=999999",
                      "period=" + "f" * 32,"q[]=x","page=0","duplicate=invalid"]:
            self.assertEqual(self.admin.request("/admin/applications?" + query)[0], 422, query)

    def test_production_queue_gate_and_methods(self):
        self.assertEqual(Client(self.base).request("/admin/queue")[0], 303)
        self.assertEqual(self.client.request("/admin/queue")[0], 403)
        self.assertEqual(self.admin.request("/admin/queue", {})[0], 405)
        session = next(cookie.value for cookie in self.admin.cookies if cookie.name == "spmb_session")
        environment = dict(os.environ, APP_ENV="production", APP_URL="https://example.test",
                           APP_STORAGE=str(self.storage), MAIL_TRANSPORT="mail")
        code = """session_id($argv[1]); $_SERVER['REQUEST_METHOD']=$argv[4]; $_SERVER['REQUEST_URI']=$argv[3];
            $_POST=['csrf'=>$argv[2],'assignment_version'=>'0'];
            register_shutdown_function(function(){echo '\\nSTATUS='.http_response_code();}); require 'public/index.php';"""
        for method, path in [("GET", "/admin/queue"), ("POST", "/admin/queue/" + self.app + "/claim")]:
            result = subprocess.run([shutil.which("php"), "-r", code, session, self.admin.csrf("/admin"), path, method],
                                    cwd=ROOT, env=environment, capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("STATUS=403", result.stdout)


if __name__ == "__main__":
    unittest.main()
