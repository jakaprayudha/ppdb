"""Master data CRUD, pagination, archival and immutable usage integration tests."""

import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import unittest

import admin_flow as admin_helpers
import admission_flow as admission_helpers
from auth_flow import ROOT


class MasterFlow(unittest.TestCase):
    setUpClass = classmethod(admin_helpers.AdminFlow.setUpClass.__func__)
    tearDownClass = classmethod(admin_helpers.AdminFlow.tearDownClass.__func__)
    stop_server = classmethod(admin_helpers.AdminFlow.stop_server.__func__)
    cli = classmethod(admission_helpers.AdmissionFlow.cli.__func__)
    admin_cli = classmethod(admin_helpers.AdminFlow.admin_cli.__func__)
    register = admin_helpers.AdminFlow.register
    login = admin_helpers.AdminFlow.login
    create_profile = admin_helpers.AdminFlow.create_profile
    application = admin_helpers.AdminFlow.application

    def setUp(self):
        admin_helpers.AdminFlow.setUp(self)
        self.school_data = {"npsn": "99990001", "name": "SMP Sekolah CRUD Uji", "level": "SMP",
                            "mode": "public_spmb", "province": "Sumatera Utara",
                            "city": "Kabupaten Serdang Bedagai", "district": "Sei Rampah",
                            "address": "Jalan Sekolah Uji"}

    def create_school(self, npsn=None):
        path = "/admin/master-data/schools/new"
        data = {**self.school_data, "npsn": npsn or self.school_data["npsn"]}
        status, body, headers = self.admin.request(path, {"csrf": self.admin.csrf(path), **data})
        self.assertEqual(status, 303, body)
        return headers["Location"].split("/")[-1]

    def school(self, school_id):
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.row_factory = sqlite3.Row
            return dict(db.execute("SELECT * FROM master_schools WHERE id=?", (school_id,)).fetchone())

    def period_payload(self, school_id, code):
        return {
            "school_id": school_id, "code": code, "organizer": "Pengelola Uji",
            "academic_year": "2026/2027", "timezone": "Asia/Jakarta",
            "opens_at": "2026-01-01 00:00:00", "closes_at": "2030-01-01 00:00:00",
            "privacy_notice": "Data uji saja, bukan penerimaan nyata.", "help_contact": "Kontak pengelola uji",
            "rule_reference": "Ketentuan uji belum disahkan.", "admission_mode": "public_spmb",
            "pathways[0][code]": "domisili", "pathways[0][name]": "Domisili", "pathways[0][description]": "Deskripsi jalur uji",
            "pathways[0][documents][0][code]": "identitas", "pathways[0][documents][0][label]": "Kartu keluarga",
            "pathways[0][documents][0][required]": "1",
        }

    def create_period(self, school_id, code):
        path = "/admin/master-data/periods/new"
        payload = self.period_payload(school_id, code)
        status, body, headers = self.admin.request(path, {"csrf": self.admin.csrf(path), **payload})
        self.assertEqual(status, 303, body)
        return headers["Location"].split("/")[-1], payload

    def version(self, period_id):
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            return db.execute("SELECT version FROM period_management WHERE period_id=?", (period_id,)).fetchone()[0]

    def action(self, kind, item_id, action, version=None, confirm="1", csrf=None):
        path = f"/admin/master-data/{kind}/{item_id}" + ("/delete" if action == "delete" else "")
        if version is None:
            version = self.school(item_id)["version"] if kind == "schools" else self.version(item_id)
        return self.admin.request(path, {
            "csrf": csrf if csrf is not None else self.admin.csrf("/admin"),
            "version": version, "action": action, "confirm_delete": confirm
        })

    def test_school_crud_validation_and_stale_versions(self):
        school_id = self.create_school("99990011")
        path = f"/admin/master-data/schools/{school_id}/edit"
        payload = {**self.school_data, "npsn": "99990011", "version": 1, "csrf": self.admin.csrf(path)}
        self.assertEqual(self.admin.request(path, {**payload, "csrf": "bad"})[0], 419)
        self.assertEqual(self.admin.request(path, {**payload, "npsn": "abc"})[0], 422)
        self.assertEqual(self.admin.request(path, {**payload, "name": "Sekolah Diperbarui"})[0], 303)
        self.assertEqual(self.school(school_id)["name"], "Sekolah Diperbarui")
        self.assertEqual(self.admin.request(path, payload)[0], 409)
        self.assertEqual(self.action("schools", school_id, "delete", confirm="0")[0], 422)
        self.assertEqual(self.action("schools", school_id, "delete")[0], 303)
        self.assertEqual(self.admin.request(path)[0], 404)

    def test_period_editor_crud_and_school_propagation(self):
        school_id = self.create_school("99990012")
        period_id, payload = self.create_period(school_id, "crud-period-12")
        self.assertEqual(self.client.request("/applications/new?period=" + period_id)[0], 409)
        self.assertEqual(self.action("schools", school_id, "delete")[0], 409)
        path = f"/admin/master-data/periods/{period_id}/edit"
        update = {**payload, "csrf": self.admin.csrf(path), "version": self.version(period_id)}
        self.assertEqual(self.admin.request(path, {**update, "closes_at": "2020-01-01 00:00:00"})[0], 422)
        self.assertEqual(self.admin.request(path, {**update, "pathways[0][code]": "reguler"})[0], 422)
        self.assertEqual(self.admin.request(path, {**update, "pathways[0][documents][0][label]": "Bukti identitas diperbarui"})[0], 303)
        self.assertEqual(self.admin.request(path, update)[0], 409)
        school_path = f"/admin/master-data/schools/{school_id}/edit"
        self.assertEqual(self.admin.request(school_path, {
            "csrf": self.admin.csrf(school_path), **self.school_data, "npsn": "99990012", "name": "Nama Sekolah Baru", "version": self.school(school_id)["version"]
        })[0], 303)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            name, config = db.execute("SELECT school,config_json FROM admission_periods WHERE id=?", (period_id,)).fetchone()
        self.assertEqual(name, "Nama Sekolah Baru")
        self.assertEqual(json.loads(config)["school"], name)
        self.assertEqual(self.action("periods", period_id, "activate")[0], 303)
        self.assertEqual(self.client.request("/applications/new?period=" + period_id)[0], 200)
        self.assertEqual(self.action("periods", period_id, "delete")[0], 303)
        self.assertEqual(self.admin.request(path)[0], 404)
        self.assertEqual(self.action("schools", school_id, "delete")[0], 303)

    def test_cli_import_links_to_existing_school_without_duplicate_npsn(self):
        school_id = self.create_school("99990015")
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            rules = json.loads(db.execute("SELECT config_json FROM admission_periods WHERE id=?", (self.period_id,)).fetchone()[0])
        rules.update(code="cli-school-link-test", npsn="99990015", school=self.school_data["name"])
        path = Path(self.temp.name) / "link-school-period.json"
        path.write_text(json.dumps(rules))
        result = self.cli("import", str(path))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.admin.request("/admin/master-data/periods")[0], 200)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            period_id, linked = db.execute("SELECT p.id,l.school_id FROM admission_periods p JOIN period_school_links l ON l.period_id=p.id WHERE p.code=?", (rules["code"],)).fetchone()
        self.assertEqual(linked, school_id)
        self.assertEqual(self.action("periods", period_id, "delete")[0], 303)
        self.assertEqual(self.action("schools", school_id, "delete")[0], 303)

    def test_used_records_are_locked_and_archiving_preserves_registrations(self):
        school_id = self.create_school("99990013")
        period_id, payload = self.create_period(school_id, "crud-period-13")
        self.assertEqual(self.action("periods", period_id, "activate")[0], 303)
        profile = self.create_profile()
        path = "/applications/new?period=" + period_id
        status, body, headers = self.client.request(path, {
            "csrf": self.client.csrf(path), "profile_id": profile, "period_id": period_id, "pathway": "domisili"
        })
        self.assertEqual(status, 303, body)
        app_id = headers["Location"].split("/")[-1]
        before = self.application(app_id)
        school_path = f"/admin/master-data/schools/{school_id}/edit"
        self.assertEqual(self.admin.request(school_path, {
            "csrf": self.admin.csrf(school_path), **self.school_data, "npsn": "99990013", "version": self.school(school_id)["version"]
        })[0], 409)
        period_path = f"/admin/master-data/periods/{period_id}/edit"
        self.assertEqual(self.admin.request(period_path, {
            "csrf": self.admin.csrf(period_path), **payload, "version": self.version(period_id)
        })[0], 409)
        self.assertEqual(self.action("periods", period_id, "delete")[0], 409)
        self.assertEqual(self.action("schools", school_id, "archive")[0], 303)
        self.assertEqual(self.action("periods", period_id, "activate")[0], 409)
        self.assertEqual(self.client.request("/applications/" + app_id)[0], 200)
        self.assertEqual(self.application(app_id), before)
        self.assertEqual(self.action("schools", school_id, "activate")[0], 303)
        self.assertEqual(self.client.request(path)[0], 409)
        self.assertEqual(self.action("periods", period_id, "activate")[0], 303)
        self.assertEqual(self.admin.request("/admin/master-data/periods/new?copy=" + period_id)[0], 200)
        cancel = "/applications/" + app_id + "/cancel"
        self.assertEqual(self.client.request(cancel, {
            "csrf": self.client.csrf(cancel), "version": before["version"], "confirm_cancel": "1"
        })[0], 303)
        self.assertEqual(self.action("periods", period_id, "delete")[0], 409)
        self.assertEqual(self.admin.request(period_path, {
            "csrf": self.admin.csrf(period_path), **payload, "version": self.version(period_id)
        })[0], 409)

    def test_master_access_and_duplicate_school(self):
        for path in ["/admin/master-data/schools/new", "/admin/master-data/periods/new"]:
            self.assertEqual(self.client.request(path)[0], 403)
            self.assertEqual(self.client.request(path, {"csrf": self.client.csrf("/participants/new")})[0], 403)
        school_id = self.create_school("99990014")
        path = "/admin/master-data/schools/new"
        self.assertEqual(self.admin.request(path, {"csrf": self.admin.csrf(path), **self.school_data, "npsn": "99990014"})[0], 422)
        self.assertEqual(self.action("schools", school_id, "archive", version=99)[0], 409)
        self.assertEqual(self.action("schools", school_id, "archive", csrf="invalid")[0], 419)
        session_id = next(cookie.value for cookie in self.admin.cookies if cookie.name == "spmb_session")
        environment = dict(os.environ, APP_ENV="production", APP_URL="https://example.test",
                           APP_STORAGE=str(self.storage), MAIL_TRANSPORT="mail")
        code = """session_id($argv[1]); $_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REQUEST_URI']='/admin/master-data/schools/new';
            $_POST=['csrf'=>$argv[2]]; register_shutdown_function(function(){echo '\\nSTATUS='.http_response_code();}); require 'public/index.php';"""
        result = subprocess.run([shutil.which("php"), "-r", code, session_id, self.admin.csrf(path)], cwd=ROOT,
                                env=environment, capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("STATUS=403", result.stdout)

    def test_table_icons_switch_and_filter_preservation(self):
        school_id = self.create_school("99990016")
        path = "/admin/master-data/schools?q=99990016&page=1"
        status, body, _ = self.admin.request(path)
        self.assertEqual(status, 200)
        self.assertNotIn("master-tabs", body)
        self.assertNotIn("Lingkungan pengembangan.", body)
        for label in ["Detail", "Edit", "Hapus"]:
            self.assertIn(f'aria-label="{label}" title="{label}"><svg', body)
        self.assertIn('role="switch" aria-checked="true"', body)
        action = f"/admin/master-data/schools/{school_id}?q=99990016&page=1&return_list=1"
        status, body, headers = self.admin.request(action, {
            "csrf": self.admin.csrf("/admin"), "version": self.school(school_id)["version"], "action": "archive"
        })
        self.assertEqual(status, 303, body)
        self.assertEqual(headers["Location"], path)
        self.assertIn('role="switch" aria-checked="false"', self.admin.request(path)[1])
        self.assertEqual(self.school(school_id)["enabled"], 0)
        self.assertEqual(self.action("schools", school_id, "activate")[0], 303)
        self.assertEqual(self.action("schools", school_id, "delete")[0], 303)

    def test_z_pagination_and_search_of_40_schools(self):
        self.assertEqual(self.cli("sergai").returncode, 0)
        ids = []
        for page in range(1, 6):
            status, body, _ = self.admin.request(f"/admin/master-data/schools?page={page}")
            self.assertEqual(status, 200, body)
            ids.extend(re.findall(r'/admin/master-data/schools/([a-f0-9]{32})" aria-label="Detail"', body))
        self.assertEqual(len(ids), len(set(ids)))
        self.assertGreaterEqual(len(ids), 40)
        for kind in ["periods", "schools"]:
            body = self.admin.request("/admin/master-data/" + kind)[1]
            self.assertIn('class="master-table"', body)
            self.assertIn("Berikutnya", body)
            self.assertEqual(self.admin.request("/admin/master-data/" + kind + "?page=bad")[0], 422)
        body = self.admin.request("/admin/master-data/schools?q=10209337")[1]
        self.assertIn("SMP NEGERI 1 SEI RAMPAH", body)
        self.assertIn("1 data ditemukan", body)
        body = self.admin.request("/admin/master-data/schools?q=absent-school")[1]
        self.assertIn("Tidak ada data yang cocok", body)
