"""Operational rule packs: real HTTP workflow, validation, grants and snapshots."""

import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import unittest
import urllib.request

import admin_flow
import admission_flow
import master_flow
import staff_flow
from auth_flow import Client


class OperationalFlow(unittest.TestCase):
    setUpClass = classmethod(admin_flow.AdminFlow.setUpClass.__func__)
    tearDownClass = classmethod(admin_flow.AdminFlow.tearDownClass.__func__)
    stop_server = classmethod(admin_flow.AdminFlow.stop_server.__func__)
    cli = classmethod(admission_flow.AdmissionFlow.cli.__func__)
    admin_cli = classmethod(admin_flow.AdminFlow.admin_cli.__func__)
    register = admin_flow.AdminFlow.register
    login = admin_flow.AdminFlow.login
    create_profile = admin_flow.AdminFlow.create_profile
    application = admin_flow.AdminFlow.application
    post_application = admin_flow.AdminFlow.post_application
    upload = admin_flow.AdminFlow.upload
    create_school = master_flow.MasterFlow.create_school
    school = master_flow.MasterFlow.school
    period_payload = master_flow.MasterFlow.period_payload
    version = master_flow.MasterFlow.version
    action = master_flow.MasterFlow.action
    invite = staff_flow.StaffFlow.invite
    activate = staff_flow.StaffFlow.activate

    def setUp(self):
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("DELETE FROM rate_limits")
        master_flow.MasterFlow.setUp(self)
        suffix = hashlib.sha256(self.id().encode()).hexdigest()[:10]
        self.school_data["name"] = "SMP Operasional " + suffix
        self.school_id = self.create_school(str(90000000 + int(suffix, 16) % 9999999))
        path = "/admin/master-data/periods/new"
        self.period_data = self.period_payload(self.school_id, "ops-" + suffix)
        self.period_data["closes_at"] = "2027-01-01 00:00:00"
        for i, code in enumerate(["afirmasi", "prestasi", "mutasi"], 1):
            self.period_data.update({
                f"pathways[{i}][code]": code, f"pathways[{i}][name]": code.title(),
                f"pathways[{i}][description]": "Jalur uji operasional",
                f"pathways[{i}][documents][0][code]": "identitas",
                f"pathways[{i}][documents][0][label]": "Kartu keluarga",
                f"pathways[{i}][documents][0][required]": "1",
            })
        result = self.admin.request(path, {"csrf": self.admin.csrf(path), **self.period_data})
        self.assertEqual(result[0], 303, result[1])
        self.ops_period = result[2]["Location"].split("/")[-1]
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.year_id, starts, year_version = db.execute(
                "SELECT id,starts_on,version FROM academic_years WHERE label='2026/2027'"
            ).fetchone()
        if not starts:
            path = "/admin/master-data/years/" + self.year_id + "/edit"
            result = self.admin.request(path, {
                "csrf": self.admin.csrf(path), "version": year_version, "label": "2026/2027",
                "starts_on": "2026-07-01", "ends_on": "2027-06-30",
            })
            self.assertEqual(result[0], 303, result[1])

    def payload(self):
        result = {
            "period_id": self.ops_period, "year_id": self.year_id, "timezone": "Asia/Jakarta",
            "capacity": "100", "class_limit": "25", "reason": "Paket uji, bukan Juknis resmi.",
            "quotas[domisili]": "50", "quotas[afirmasi]": "20",
            "quotas[prestasi]": "25", "quotas[mutasi]": "5",
            "juknis[number]": "UJI-01", "juknis[issuer]": "Panitia uji",
            "juknis[version]": "1", "juknis[url]": "https://example.test/juknis-uji",
            "juknis[date]": "2025-12-01", "juknis[effective_from]": "2026-01-01",
            "juknis[effective_until]": "2027-06-30",
            "juknis[notes]": "Prioritas uji; kursi sisa dimasukkan eksplisit; tanpa pembulatan otomatis.",
        }
        for i in range(4):
            result.update({f"classes[{i}][name]": f"VII-{i + 1}", f"classes[{i}][seats]": "25"})
        dates = {
            "registration": ("2026-01-01 00:00:00", "2027-01-01 00:00:00"),
            "verification": ("2026-02-01 00:00:00", "2027-01-03 00:00:00"),
            "correction": ("2026-12-01 00:00:00", "2027-01-02 00:00:00"),
            "selection": ("2027-01-03 00:00:00", "2027-01-04 00:00:00"),
            "announcement": ("2027-01-04 00:00:00", "2027-01-05 00:00:00"),
            "appeal": ("2027-01-05 00:00:00", "2027-01-06 00:00:00"),
            "reenrollment": ("2027-01-06 00:00:00", "2027-01-07 00:00:00"),
        }
        for stage, (start, end) in dates.items():
            result.update({f"schedule[{stage}][start]": start, f"schedule[{stage}][end]": end})
        return result

    def save_pack(self, changes=None, pack=None):
        path = "/admin/master-data/rules/" + (pack + "/edit" if pack else "new")
        values = {**self.payload(), **(changes or {})}
        if pack:
            values["version"] = self.pack(pack)["version"]
        return self.admin.request(path, {"csrf": self.admin.csrf("/admin"), **values})

    def create_pack(self, changes=None):
        result = self.save_pack(changes)
        self.assertEqual(result[0], 303, result[1])
        return result[2]["Location"].split("/")[-1]

    def pack(self, pack):
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.row_factory = sqlite3.Row
            return dict(db.execute("SELECT * FROM operational_rule_packs WHERE id=?", (pack,)).fetchone())

    def decide(self, pack, action, actor=None, version=None, note="Catatan keputusan uji."):
        actor = actor or self.admin
        base = "/admin/master-data/rules/" if actor is self.admin else "/admin/rule-approvals/"
        return actor.request(base + pack, {
            "csrf": actor.csrf("/admin"), "action": action, "note": note,
            "version": self.pack(pack)["version"] if version is None else version,
        })

    def approver(self, schools=None, can_approve="1", suffix="approver"):
        email, result = self.invite("school_admin", schools or [self.school_id], suffix, can_approve=can_approve)
        self.assertEqual(result[0], 303, result[1])
        client, user_id, _, _ = self.activate(email)
        return client, user_id

    def publish(self, pack):
        approver, user_id = self.approver()
        for action, actor in [("submit", self.admin), ("approve", approver), ("publish", self.admin)]:
            result = self.decide(pack, action, actor)
            self.assertEqual(result[0], 303, result[1])
        return approver, user_id

    def test_year_crud_status_validation_and_locks(self):
        base = "/admin/master-data/years"
        data = {"label": "2034/2035", "starts_on": "2034-07-01", "ends_on": "2035-06-30"}
        for changes in [{"label": "2034/2036"}, {"starts_on": "2034-02-30"}, {"ends_on": "2034-12-31"}]:
            self.assertEqual(self.admin.request(base + "/new", {"csrf": self.admin.csrf("/admin"), **data, **changes})[0], 422)
        result = self.admin.request(base + "/new", {"csrf": self.admin.csrf("/admin"), **data})
        self.assertEqual(result[0], 303, result[1])
        year = result[2]["Location"].split("/")[-1]
        path = base + "/" + year
        self.assertEqual(self.admin.request(path + "/edit", {"csrf": self.admin.csrf("/admin"), "version": 1, **data, "starts_on": "2034-07-02"})[0], 303)
        self.assertEqual(self.admin.request(path, {"csrf": self.admin.csrf("/admin"), "version": 1, "action": "archive"})[0], 409)
        self.assertEqual(self.admin.request(path, {"csrf": self.admin.csrf("/admin"), "version": 2, "action": "archive"})[0], 303)
        self.assertEqual(self.admin.request(path + "/delete", {"csrf": self.admin.csrf("/admin"), "version": 3, "confirm_delete": "0"})[0], 422)
        self.assertEqual(self.admin.request(path + "/delete", {"csrf": self.admin.csrf("/admin"), "version": 3, "confirm_delete": "1"})[0], 303)
        self.assertEqual(self.admin.request(path)[0], 404)
        pack = self.create_pack()
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            version = db.execute("SELECT version FROM academic_years WHERE id=?", (self.year_id,)).fetchone()[0]
        path = base + "/" + self.year_id
        self.assertEqual(self.admin.request(path + "/edit", {"csrf": self.admin.csrf("/admin"), "version": version,
            "label": "2026/2027", "starts_on": "2026-07-02", "ends_on": "2027-06-30"})[0], 409)
        self.assertEqual(self.admin.request(path + "/delete", {"csrf": self.admin.csrf("/admin"), "version": version, "confirm_delete": "1"})[0], 409)
        self.assertEqual(self.admin.request(path, {"csrf": self.admin.csrf("/admin"), "version": version, "action": "archive"})[0], 303)
        self.assertEqual(self.decide(pack, "submit")[0], 409)
        self.assertEqual(self.admin.request(path, {"csrf": self.admin.csrf("/admin"), "version": version + 1, "action": "activate"})[0], 303)

    def test_exact_capacity_quota_thresholds_and_malformed_inputs(self):
        failures = [
            {"capacity": "101"}, {"capacity": "0"}, {"class_limit": "24"}, {"classes[1][name]": "vii-1"},
            {"classes[0][seats]": "25.5"}, {"quotas[mutasi]": "4"},
            {"quotas[mutasi]": "6", "quotas[domisili]": "49"},
            {"quotas[afirmasi]": "19", "quotas[domisili]": "51"},
            {"quotas[prestasi]": "24", "quotas[domisili]": "51"},
            {"quotas[tambahan]": "0"}, {"classes[0]": "invalid"},
            {"schedule[selection]": "invalid"}, {"juknis[notes][array]": "invalid"},
            {"capacity": "101", "class_limit": "26", "classes[0][seats]": "26", "quotas[domisili]": "51"},
        ]
        for changes in failures:
            with self.subTest(changes=changes):
                result = self.save_pack(changes)
                self.assertEqual(result[0], 422, result[1])
                self.assertNotIn("Layanan belum tersedia", result[1])
        pack = self.create_pack()
        payload = json.loads(self.pack(pack)["payload_json"])
        self.assertEqual(sum(row["seats"] for row in payload["classes"]), 100)
        self.assertEqual(sum(payload["quotas"].values()), 100)
        self.assertEqual(self.save_pack()[0], 409)
        result = self.save_pack({"capacity": "101", "class_limit": "26", "classes[0][seats]": "26",
            "quotas[domisili]": "45", "quotas[afirmasi]": "25", "quotas[prestasi]": "26"}, pack=pack)
        self.assertEqual(result[0], 303, result[1])
        self.assertEqual(json.loads(self.pack(pack)["payload_json"])["capacity"], 101)

    def test_schedule_overlap_sequence_juknis_and_source_freshness(self):
        for changes in [
            {"schedule[registration][start]": "2026-01-02 00:00:00"},
            {"schedule[verification][end]": "2026-12-31 00:00:00"},
            {"schedule[correction][end]": "2027-01-04 00:00:00"},
            {"schedule[selection][start]": "2027-01-02 00:00:00"},
            {"schedule[appeal][start]": "2027-01-04 00:00:00"},
            {"schedule[reenrollment][end]": "2027-07-01 00:00:00"},
            {"juknis[url]": "http://example.test"},
            {"juknis[url]": "https://user:pass@example.test"},
            {"juknis[effective_until]": "2027-01-06"},
            {"juknis[date]": "2026-01-02"},
        ]:
            with self.subTest(changes=changes):
                self.assertEqual(self.save_pack(changes)[0], 422)
        pack = self.create_pack()
        path = "/admin/master-data/periods/" + self.ops_period + "/edit"
        result = self.admin.request(path, {"csrf": self.admin.csrf("/admin"), "version": self.version(self.ops_period),
            **self.period_data, "rule_reference": "Ketentuan uji diubah."})
        self.assertEqual(result[0], 303, result[1])
        self.assertEqual(self.decide(pack, "submit")[0], 409)
        self.school_id = self.create_school("89990002")
        result = self.admin.request(path, {"csrf": self.admin.csrf("/admin"), "version": self.version(self.ops_period),
            **self.period_data, "school_id": self.school_id})
        self.assertEqual(result[0], 303, result[1])
        self.assertEqual(self.save_pack(pack=pack)[0], 303)
        self.assertEqual(self.pack(pack)["school_id"], self.school_id)
        self.assertEqual(self.decide(pack, "submit")[0], 303)
        self.assertEqual(self.admin.request(path, {"csrf": self.admin.csrf("/admin"), "version": self.version(self.ops_period), **self.period_data})[0], 409)
        school_path = "/admin/master-data/schools/" + self.school_id + "/edit"
        before = self.school(self.school_id)
        result = self.admin.request(school_path, {"csrf": self.admin.csrf("/admin"), "version": before["version"], **self.school_data, "npsn": before["npsn"], "name": "Nama berubah"})
        self.assertEqual(result[0], 409)
        self.assertEqual(self.school(self.school_id)["name"], before["name"])
        self.assertEqual(self.action("periods", self.ops_period, "delete")[0], 409)

    def test_return_edit_approve_publish_activation_and_immutable_snapshot(self):
        self.assertEqual(self.action("periods", self.ops_period, "activate")[0], 303)
        pack = self.create_pack()
        self.assertEqual(self.action("periods", self.ops_period, "activate")[0], 409)
        approver, _ = self.approver()
        self.assertEqual(self.decide(pack, "publish")[0], 409)
        self.assertEqual(self.decide(pack, "submit", note="")[0], 422)
        self.assertEqual(self.decide(pack, "submit")[0], 303)
        self.assertEqual(self.decide(pack, "approve", version=1)[0], 409)
        self.assertEqual(self.decide(pack, "approve")[0], 403)
        self.assertEqual(self.decide(pack, "return", approver, note="Perjelas Juknis.")[0], 303)
        self.assertEqual(self.pack(pack)["status"], "returned")
        self.assertEqual(self.save_pack({"reason": "Perbaikan Juknis."}, pack=pack)[0], 303)
        self.assertEqual(self.decide(pack, "submit")[0], 303)
        self.assertEqual(self.decide(pack, "approve", approver)[0], 303)
        self.assertEqual(self.decide(pack, "publish")[0], 303)
        self.assertEqual(self.pack(pack)["status"], "published")
        self.assertEqual(self.client.request("/applications/new?period=" + self.ops_period)[0], 409)
        self.assertEqual(self.action("periods", self.ops_period, "activate")[0], 303)
        self.assertEqual(self.client.request("/applications/new?period=" + self.ops_period)[0], 200)
        profile = self.create_profile()
        path = "/applications/new?period=" + self.ops_period
        result = self.client.request(path, {"csrf": self.client.csrf(path), "profile_id": profile, "period_id": self.ops_period, "pathway": "domisili"})
        self.assertEqual(result[0], 303, result[1])
        app = result[2]["Location"].split("/")[-1]
        self.assertEqual(self.upload(app, kind="identitas")[0], 303)
        self.assertEqual(self.post_application(app, 4, "submit", {"declaration": "1"})[0], 303)
        before = self.application(app)
        snapshot = json.loads(before["rule_snapshot_json"])["operational"]
        self.assertEqual(snapshot["pack_id"], pack)
        self.assertEqual(snapshot["hash"], self.pack(pack)["payload_hash"])
        self.assertEqual(snapshot["data"]["capacity"], 100)
        clone = {**self.period_data, "code": "ops-clone-" + pack[:8], "csrf": self.admin.csrf("/admin")}
        result = self.admin.request("/admin/master-data/periods/new?copy=" + self.ops_period, clone)
        self.assertEqual(result[0], 303, result[1])
        clone_id = result[2]["Location"].split("/")[-1]
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            clone_config = json.loads(db.execute("SELECT config_json FROM admission_periods WHERE id=?", (clone_id,)).fetchone()[0])
        self.assertNotIn("operational", clone_config)
        self.assertEqual(self.save_pack()[0], 409)
        self.assertEqual(self.action("periods", self.ops_period, "archive")[0], 303)
        self.assertEqual(self.application(app)["rule_snapshot_json"], before["rule_snapshot_json"])
        self.assertEqual(self.client.request("/applications/" + app + "/receipt")[0], 200)
        status, body, _ = approver.request("/admin/rule-approvals/" + pack)
        self.assertEqual(status, 200, body)
        self.assertIn("Perjelas Juknis.", body)

    def test_approver_scope_revocation_and_reapproval(self):
        pack = self.create_pack()
        approver, user_id = self.approver()
        nonapprover, _ = self.approver(can_approve="0", suffix="no-grant")
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            other = db.execute("SELECT id FROM master_schools WHERE id<>? LIMIT 1", (self.school_id,)).fetchone()[0]
        outsider, _ = self.approver(schools=[other], suffix="outsider")
        self.assertEqual(nonapprover.request("/admin/rule-approvals")[0], 403)
        self.assertEqual(outsider.request("/admin/rule-approvals/" + pack)[0], 404)
        self.assertNotIn(self.ops_period, outsider.request("/admin/rule-approvals")[1])
        self.assertEqual(approver.request("/admin/master-data/rules")[0], 403)
        self.assertEqual(approver.request("/admin/rule-approvals/new")[0], 403)
        self.assertEqual(self.decide(pack, "submit")[0], 303)
        self.assertEqual(self.decide(pack, "approve", approver)[0], 303)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("UPDATE staff_accounts SET can_approve=0 WHERE user_id=?", (user_id,))
        self.assertEqual(self.decide(pack, "publish")[0], 409)
        self.assertEqual(self.decide(pack, "resubmit")[0], 303)
        self.assertIsNone(self.pack(pack)["approved_by"])
        self.assertEqual(self.decide(pack, "approve", approver)[0], 403)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("UPDATE staff_accounts SET can_approve=1 WHERE user_id=?", (user_id,))
        self.assertEqual(self.decide(pack, "approve", approver)[0], 303)
        self.assertEqual(self.decide(pack, "publish")[0], 303)

    def test_revision_publication_integrity_and_availability_gate(self):
        pack = self.create_pack()
        approver, _ = self.publish(pack)
        self.assertEqual(self.action("periods", self.ops_period, "activate")[0], 303)
        second = self.create_pack({"reason": "Versi kedua, kapasitas tetap."})
        self.assertEqual(self.pack(second)["revision"], 2)
        self.assertEqual(self.client.request("/applications/new?period=" + self.ops_period)[0], 200)
        self.assertEqual(self.decide(second, "submit")[0], 303)
        self.assertEqual(self.decide(second, "approve", approver)[0], 303)
        self.assertEqual(self.decide(second, "publish")[0], 303)
        self.assertEqual(self.pack(pack)["status"], "superseded")
        self.assertEqual(self.client.request("/applications/new?period=" + self.ops_period)[0], 409)
        self.assertEqual(self.action("periods", self.ops_period, "activate")[0], 303)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            config = json.loads(db.execute("SELECT config_json FROM admission_periods WHERE id=?", (self.ops_period,)).fetchone()[0])
            config["operational"]["data"]["capacity"] = 999
            db.execute("UPDATE admission_periods SET config_json=? WHERE id=?", (json.dumps(config), self.ops_period))
        self.assertEqual(self.client.request("/applications/new?period=" + self.ops_period)[0], 409)
        self.assertNotIn(self.school_data["name"], self.client.request("/admissions")[1])
        self.assertEqual(self.action("periods", self.ops_period, "activate")[0], 409)

    def test_routes_csrf_filters_pagination_and_pilot_legacy(self):
        for path in ["/admin/master-data/years", "/admin/master-data/rules", "/admin/rule-approvals"]:
            self.assertEqual(Client(self.base).request(path)[0], 303)
            self.assertEqual(self.client.request(path)[0], 403)
        base = "/admin/master-data/rules"
        self.assertEqual(self.admin.request(base, {})[0], 405)
        self.assertEqual(self.admin.request(base + "?page=bad")[0], 422)
        self.assertEqual(self.admin.request(base + "?status=invalid")[0], 422)
        self.assertEqual(self.admin.request(base + "/new", {"csrf": "bad", **self.payload()})[0], 419)
        self.assertEqual(self.admin.request(base + "/new?period=" + self.ops_period)[0], 200)
        result = self.save_pack({"action": "add-class"})
        self.assertEqual(result[0], 200, result[1])
        self.assertIn('name="classes[4][name]"', result[1])
        result = self.save_pack({"remove_class": "0"})
        self.assertEqual(result[0], 200, result[1])
        self.assertNotIn('name="classes[3][name]"', result[1])
        pack = self.create_pack()
        self.assertEqual(self.admin.request(base + "/" + pack + "/delete")[0], 403)
        result = self.admin.raw_request(urllib.request.Request(self.base + base + "/" + pack, method="PUT"))
        self.assertEqual(result[0], 405)
        status, body, _ = self.admin.request(base + "?q=" + self.period_data["code"])
        self.assertEqual(status, 200, body)
        self.assertIn("1 data ditemukan", body)
        self.assertIn(pack, body)
        self.assertNotIn(pack, self.admin.request(base + "?q=no-such-school")[1])
        self.assertEqual(self.admin.request(base + "?page=2")[0], 200)
        self.assertEqual(self.client.request("/applications/new?period=" + self.period_id)[0], 200)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("UPDATE admission_period_availability SET enabled=1 WHERE period_id=?", (self.ops_period,))
        self.assertEqual(self.client.request("/applications/new?period=" + self.ops_period)[0], 409)
        self.assertNotIn(self.school_data["name"], self.client.request("/admissions")[1])
        self.assertIn('aria-checked="false"', self.admin.request("/admin/master-data/periods?q=" + self.period_data["code"])[1])
        self.assertIn(self.period_data["code"] + "\t" + self.school_data["name"] + "\tDiarsipkan", self.cli("list").stdout)
        session_id = next(cookie.value for cookie in self.admin.cookies if cookie.name == "spmb_session")
        environment = dict(os.environ, APP_ENV="production", APP_URL="https://example.test",
                           APP_STORAGE=str(self.storage), MAIL_TRANSPORT="mail")
        code = """session_id($argv[1]); $_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REQUEST_URI']=$argv[3];
            $_POST=['csrf'=>$argv[2]]; register_shutdown_function(function(){echo '\\nSTATUS='.http_response_code();}); require 'public/index.php';"""
        for path in [base + "/new", "/admin/master-data/years/new", "/admin/rule-approvals"]:
            result = subprocess.run([shutil.which("php"), "-r", code, session_id, self.admin.csrf("/admin"), path],
                cwd=admin_flow.ROOT, env=environment, capture_output=True, text=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("STATUS=403", result.stdout)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            rules = json.loads(db.execute("SELECT config_json FROM admission_periods WHERE id=?", (self.ops_period,)).fetchone()[0])
        rules.update(code="forged-operational", operational={"hash": "forged"})
        import_path = Path(self.temp.name) / "forged-period.json"
        import_path.write_text(json.dumps(rules))
        result = self.cli("import", str(import_path))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("persetujuan", result.stderr)

    def test_private_mode_does_not_apply_public_percentage_thresholds(self):
        # Set up a separate private school/period through the same public admin forms.
        self.school_data["mode"] = "private_independent"
        school = self.create_school("89990001")
        self.ops_period_data = self.period_payload(school, "ops-private")
        self.ops_period_data.update(admission_mode="private_independent", closes_at="2027-01-01 00:00:00")
        self.ops_period_data["pathways[0][code]"] = "reguler"
        path = "/admin/master-data/periods/new"
        result = self.admin.request(path, {"csrf": self.admin.csrf("/admin"), **self.ops_period_data})
        self.assertEqual(result[0], 303, result[1])
        self.ops_period = result[2]["Location"].split("/")[-1]
        payload = self.payload()
        for key in list(payload):
            if key.startswith("quotas["):
                del payload[key]
        payload["quotas[reguler]"] = "100"
        result = self.admin.request("/admin/master-data/rules/new", {"csrf": self.admin.csrf("/admin"), **payload})
        self.assertEqual(result[0], 303, result[1])

    def test_public_sd_sma_seat_thresholds(self):
        scenarios = [
            ("SD", {"domisili": 70, "afirmasi": 25, "mutasi": 5}, [
                {"domisili": 69, "afirmasi": 26, "mutasi": 5},
                {"domisili": 81, "afirmasi": 14, "mutasi": 5},
                {"domisili": 70, "afirmasi": 24, "mutasi": 6},
            ]),
            ("SMA", {"domisili": 35, "afirmasi": 30, "prestasi": 30, "mutasi": 5}, [
                {"domisili": 29, "afirmasi": 36, "prestasi": 30, "mutasi": 5},
                {"domisili": 36, "afirmasi": 29, "prestasi": 30, "mutasi": 5},
                {"domisili": 36, "afirmasi": 30, "prestasi": 29, "mutasi": 5},
                {"domisili": 34, "afirmasi": 30, "prestasi": 30, "mutasi": 6},
            ]),
        ]
        for index, (level, valid, invalid) in enumerate(scenarios):
            with self.subTest(level=level):
                self.school_data.update(level=level, name=level + " Operasional Uji")
                school = self.create_school(str(89990100 + index))
                period = self.period_payload(school, "ops-threshold-" + level.lower())
                period["closes_at"] = "2027-01-01 00:00:00"
                for route_index, code in enumerate(valid):
                    period.update({
                        f"pathways[{route_index}][code]": code,
                        f"pathways[{route_index}][name]": code.title(),
                        f"pathways[{route_index}][description]": "Jalur uji",
                        f"pathways[{route_index}][documents][0][code]": "identitas",
                        f"pathways[{route_index}][documents][0][label]": "Kartu keluarga",
                        f"pathways[{route_index}][documents][0][required]": "1",
                    })
                result = self.admin.request("/admin/master-data/periods/new", {
                    "csrf": self.admin.csrf("/admin"), **period,
                })
                self.assertEqual(result[0], 303, result[1])
                self.ops_period = result[2]["Location"].split("/")[-1]
                payload = {key: value for key, value in self.payload().items()
                           if not key.startswith("quotas[")}
                path = "/admin/master-data/rules/new"
                for seats in invalid:
                    self.assertEqual(sum(seats.values()), 100)
                    result = self.admin.request(path, {
                        "csrf": self.admin.csrf("/admin"), **payload,
                        **{f"quotas[{code}]": str(count) for code, count in seats.items()},
                    })
                    self.assertEqual(result[0], 422, result[1])
                result = self.admin.request(path, {
                    "csrf": self.admin.csrf("/admin"), **payload,
                    **{f"quotas[{code}]": str(count) for code, count in valid.items()},
                })
                self.assertEqual(result[0], 303, result[1])

    def test_year_pagination_exact_ten_rows_and_search(self):
        base = "/admin/master-data/years"
        for year in range(2040, 2051):
            result = self.admin.request(base + "/new", {
                "csrf": self.admin.csrf("/admin"), "label": f"{year}/{year + 1}",
                "starts_on": f"{year}-07-01", "ends_on": f"{year + 1}-06-30",
            })
            self.assertEqual(result[0], 303, result[1])
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            years = db.execute("SELECT id FROM academic_years ORDER BY label DESC,id").fetchall()
        first = self.admin.request(base)[1]
        second = self.admin.request(base + "?page=2")[1]
        self.assertEqual(first.count('class="master-row-actions"'), 10)
        self.assertEqual(second.count('class="master-row-actions"'), len(years) - 10)
        for index, (year_id,) in enumerate(years):
            self.assertEqual(base + "/" + year_id in first, index < 10)
            self.assertEqual(base + "/" + year_id in second, index >= 10)
        filtered = self.admin.request(base + "?q=2050%2F2051")[1]
        self.assertIn("1 data ditemukan", filtered)
        self.assertEqual(filtered.count('class="master-row-actions"'), 1)


if __name__ == "__main__":
    unittest.main()
