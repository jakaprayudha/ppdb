"""Invitations, MFA, multi-school grants and application-level staff authorization."""

import hashlib
import os
import re
import shutil
import sqlite3
import subprocess
import time
import unittest
import urllib.request

import admin_flow as admin_helpers
import admission_flow as admission_helpers
from auth_flow import Client, ROOT
from staff_helpers import enroll_staff, mail_path, totp
from review_helpers import review_payload


class StaffFlow(unittest.TestCase):
    setUpClass = classmethod(admin_helpers.AdminFlow.setUpClass.__func__)
    tearDownClass = classmethod(admin_helpers.AdminFlow.tearDownClass.__func__)
    stop_server = classmethod(admin_helpers.AdminFlow.stop_server.__func__)
    cli = classmethod(admission_helpers.AdmissionFlow.cli.__func__)
    admin_cli = classmethod(admin_helpers.AdminFlow.admin_cli.__func__)
    def setUp(self):
        # Each scenario exercises its own limits; prior scenarios must not exhaust
        # the shared isolated server's login/MFA buckets.
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("DELETE FROM rate_limits")
        admin_helpers.AdminFlow.setUp(self)
    register = admin_helpers.AdminFlow.register
    login = admin_helpers.AdminFlow.login
    create_profile = admin_helpers.AdminFlow.create_profile
    create_application = admin_helpers.AdminFlow.create_application
    application = admin_helpers.AdminFlow.application
    upload = admin_helpers.AdminFlow.upload
    active_document = admin_helpers.AdminFlow.active_document
    post_application = admin_helpers.AdminFlow.post_application
    def submit_fixture(self):
        # Regional import archives the unrelated synthetic period; reopen it through
        # the real admin action before creating an isolated submitted fixture.
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            version = db.execute("SELECT version FROM period_management WHERE period_id=?", (self.period_id,)).fetchone()[0]
        path = "/admin/master-data/periods/" + self.period_id
        self.assertEqual(self.admin.request(path, {
            "csrf": self.admin.csrf("/admin"), "version": version, "action": "activate"
        })[0], 303)
        return admin_helpers.AdminFlow.submit_fixture(self)

    def schools(self):
        # Trigger the same additive linking as a normal web request.
        self.admin.request("/admin")
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            own = db.execute("SELECT school_id FROM period_school_links WHERE period_id=?", (self.period_id,)).fetchone()[0]
            others = [row[0] for row in db.execute("SELECT id FROM master_schools WHERE id<>?", (own,))]
        return own, others

    def invite(self, role, school_ids, suffix, client=None, **extra):
        email = f"staff-{hashlib.sha256(self.id().encode()).hexdigest()[:12]}-{suffix}@example.test"
        actor = client or self.admin
        data = {
            "csrf": actor.csrf("/admin"), "name": f"Petugas Uji {suffix}",
            "email": email, "role": role, "schools[]": school_ids,
            **extra
        }
        # urlencode lists require one key per selected index in this test client.
        del data["schools[]"]
        data.update({f"schools[{i}]": value for i, value in enumerate(school_ids)})
        result = actor.request("/admin/accounts", data)
        return email, result

    def activate(self, email):
        client = admission_helpers.AdmissionClient(self.base)
        path = mail_path(self, email, "/staff/accept")
        status, body, _ = client.request(path, {
            "csrf": client.csrf(path), "password": "staff-password-test-123",
            "password_confirmation": "staff-password-test-123", "privacy": "1"
        })
        self.assertEqual(status, 303, body)
        self.assertEqual(self.login(client, email, "staff-password-test-123")[0], 303)
        self.assertEqual(client.request("/admin")[0], 200)
        secret, recovery = enroll_staff(self, client, email, "staff-password-test-123")
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            user_id = db.execute("SELECT id FROM users WHERE email=?", (email,)).fetchone()[0]
        return client, user_id, secret, recovery

    def staff(self, role, school_ids, suffix):
        email, result = self.invite(role, school_ids, suffix)
        self.assertEqual(result[0], 303, result[1])
        return email, *self.activate(email)

    def assign(self, client, app, reviewer, version=0):
        path = "/admin/applications/" + app
        return client.request(path, {"csrf": client.csrf("/admin"), "action": "assign",
                                     "reviewer_id": reviewer, "assignment_version": version,
                                     "assignment_note": "Penugasan/pengalihan untuk pengujian."})

    def test_invitations_collision_cancellation_expiry_and_roles(self):
        own, _ = self.schools()
        email, result = self.invite("central_admin", [own], "invalid-role")
        self.assertEqual(result[0], 422)
        email, result = self.invite("school_admin", ["f" * 32], "invalid-school")
        self.assertEqual(result[0], 422)
        email, result = self.invite("school_admin", [], "no-school")
        self.assertEqual(result[0], 422)
        email, result = self.invite("school_admin", [own], "cancel", can_approve="1")
        self.assertEqual(result[0], 303, result[1])
        self.assertEqual(self.invite("school_admin", [own], "cancel")[1][0], 409)
        path = mail_path(self, email, "/staff/accept")
        token = path.split("=")[1]
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            invitation_id, stored_hash = db.execute("SELECT id,token_hash FROM staff_invitations WHERE email=?", (email,)).fetchone()
        self.assertNotEqual(stored_hash, token)
        cancel = f"/admin/accounts/invitations/{invitation_id}/cancel"
        self.assertEqual(self.admin.request(cancel, {"csrf": "wrong"})[0], 419)
        self.assertEqual(self.admin.request(cancel, {"csrf": self.admin.csrf("/admin")})[0], 303)
        self.assertEqual(Client(self.base).request(path)[0], 410)
        email, result = self.invite("school_admin", [own], "expire")
        self.assertEqual(result[0], 303, result[1])
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("UPDATE staff_invitations SET expires_at=? WHERE email=?", (int(time.time()) - 1, email))
        self.assertEqual(Client(self.base).request(mail_path(self, email, "/staff/accept"))[0], 410)
        guardian_email = self.application(self.submit_fixture())["user_id"]
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            guardian_email = db.execute("SELECT email FROM users WHERE id=?", (guardian_email,)).fetchone()[0]
        self.assertEqual(self.invite("school_admin", [own], "collision", email=guardian_email)[1][0], 409)

    def test_activation_email_proof_mfa_and_single_use_recovery(self):
        own, _ = self.schools()
        email, result = self.invite("school_admin", [own], "activate")
        self.assertEqual(result[0], 303, result[1])
        path = mail_path(self, email, "/staff/accept")
        visitor = Client(self.base)
        self.assertEqual(visitor.request(path)[0], 200)
        self.assertEqual(visitor.request(path, {"csrf": "bad"})[0], 419)
        client, user_id, secret, recovery = self.activate(email)
        self.assertEqual(visitor.request(path)[0], 410)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            security = db.execute("SELECT email_verified_at,mfa_secret FROM account_security WHERE user_id=?", (user_id,)).fetchone()
            self.assertTrue(security[0])
            self.assertNotIn(secret, security[1])
            self.assertNotEqual(db.execute("SELECT code_hash FROM mfa_recovery_codes WHERE user_id=? LIMIT 1", (user_id,)).fetchone()[0], recovery[0])
        second = Client(self.base)
        self.assertEqual(self.login(second, email, "staff-password-test-123")[0], 303)
        self.assertEqual(second.request("/admin/applications")[2]["Location"], "/account/security")
        # The enrollment counter is consumed; replaying it must not create an MFA session.
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            last_counter = db.execute("SELECT last_counter FROM account_security WHERE user_id=?", (user_id,)).fetchone()[0]
        self.assertEqual(second.request("/account/security", {
            "csrf": second.csrf("/account/security"), "action": "challenge", "code": totp(secret, last_counter)
        })[0], 422)
        self.assertEqual(second.request("/account/security", {
            "csrf": second.csrf("/account/security"), "action": "challenge", "code": recovery[0]
        })[0], 303)
        self.assertEqual(second.request("/admin")[0], 200)
        third = Client(self.base)
        self.assertEqual(self.login(third, email, "staff-password-test-123")[0], 303)
        self.assertEqual(third.request("/account/security", {
            "csrf": third.csrf("/account/security"), "action": "challenge", "code": recovery[0]
        })[0], 422)
        self.assertEqual(second.request("/account/security", {
            "csrf": second.csrf("/account/security"), "action": "rotate", "password": "staff-password-test-123",
            "confirm_rotate": "1"
        })[0], 303)
        self.assertEqual(client.request("/admin")[2]["Location"], "/login")
        self.assertEqual(second.request("/admin")[0], 200)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertEqual(db.execute("SELECT count(*) FROM mfa_recovery_codes WHERE user_id=?", (user_id,)).fetchone()[0], 0)
        new_secret, _ = enroll_staff(self, second, email, "staff-password-test-123")
        self.assertNotEqual(new_secret, secret)

    def test_school_and_application_scope_including_documents_and_post(self):
        own, others = self.schools()
        if not others:
            self.assertEqual(self.cli("sergai").returncode, 0)
            own, others = self.schools()
        app = self.submit_fixture()
        doc = self.active_document(app)
        _, allowed, _, _, _ = self.staff("school_admin", [own], "own")
        _, denied, _, _, _ = self.staff("school_admin", [others[0]], "outsider")
        self.assertEqual(allowed.request("/admin/applications/" + app)[0], 200)
        self.assertIn(app, allowed.request("/admin/applications")[1])
        for url in ["/admin/applications/" + app, "/documents/" + doc]:
            self.assertEqual(denied.request(url)[0], 404)
        self.assertNotIn(app, denied.request("/admin/applications")[1])
        self.assertNotIn(app, denied.request("/admin/applications?period=" + self.period_id)[1])
        self.assertNotIn(self.period_id, denied.request("/admin")[1])
        self.assertEqual(denied.request("/admin/applications/" + app, {
            "csrf": denied.csrf("/admin"), "verification_version": "0", "decision": "valid", "note": "Tidak berwenang."
        })[0], 404)
        for url in ["/admin/accounts", "/admin/master-data/periods", "/admin/audit"]:
            self.assertEqual(allowed.request(url)[0], 403)
        status, body, _ = allowed.raw_request(urllib.request.Request(self.base + "/documents/" + doc))
        self.assertEqual((status, body), (200, admission_helpers.PNG))
        before = self.application(app)
        self.assertEqual(allowed.request("/admin/applications/" + app, {
            "csrf": allowed.csrf("/admin"), "verification_version": "0", "decision": "valid", "note": "Dokumen sesuai.",
            **review_payload(self,app),
        })[0], 303)
        self.assertEqual(self.application(app), before)

    def test_verifier_assignment_grants_revocation_and_stale_versions(self):
        self.assertEqual(self.cli("sergai").returncode, 0)
        own, others = self.schools()
        app = self.submit_fixture()
        _, school_admin, _, _, _ = self.staff("school_admin", [own], "assigner")
        _, verifier, reviewer_id, _, recovery = self.staff("verifier", [own], "reviewer")
        _, _, outsider_id, _, _ = self.staff("verifier", [others[0]], "wrong-school")
        self.assertNotIn(app, verifier.request("/admin/applications")[1])
        self.assertEqual(verifier.request("/admin/applications/" + app)[0], 404)
        self.assertEqual(verifier.request("/documents/" + self.active_document(app))[0], 404)
        self.assertEqual(self.assign(school_admin, app, outsider_id)[0], 422)
        self.assertEqual(self.assign(school_admin, app, reviewer_id)[0], 303)
        self.assertEqual(self.assign(school_admin, app, reviewer_id)[0], 409)
        self.assertIn(app, verifier.request("/admin/applications")[1])
        self.assertEqual(verifier.request("/admin/applications/" + app)[0], 200)
        self.assertEqual(verifier.request("/admin/applications/" + app, {
            "csrf": verifier.csrf("/admin"), "action": "verify", "verification_version": "0",
            "decision": "valid", "note": "Berkas sesuai pemeriksaan.", **review_payload(self,app),
        })[0], 303)
        self.assertEqual(self.assign(verifier, app, reviewer_id, 1)[0], 403)
        self.assertEqual(self.assign(school_admin, app, 0, 1)[0], 303)
        self.assertEqual(verifier.request("/admin/applications/" + app)[0], 404)
        self.assertEqual(verifier.request("/admin/applications/" + app, {
            "csrf": verifier.csrf("/admin"), "verification_version": "1",
            "decision": "invalid", "note": "Akses telah dicabut."
        })[0], 404)
        self.assertEqual(self.assign(school_admin, app, reviewer_id, 2)[0], 303)
        path = f"/admin/accounts/{reviewer_id}"
        data = {"csrf": self.admin.csrf("/admin"), "version": "1", "role": "verifier", "schools[0]": own}
        self.assertEqual(self.admin.request(path, data)[0], 303)
        self.assertEqual(verifier.request("/admin")[2]["Location"], "/login")
        self.assertEqual(self.login(verifier, self.email_for(reviewer_id), "staff-password-test-123")[0], 422)
        self.assertEqual(self.admin.request(path, data)[0], 409)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertIsNone(db.execute("SELECT reviewer_id FROM verification_assignments WHERE application_id=?", (app,)).fetchone()[0])
        self.assertEqual(self.admin.request(path, {**data, "version": "2", "enabled": "1"})[0], 303)
        self.assertEqual(verifier.request("/admin")[2]["Location"], "/login")
        self.assertEqual(self.login(verifier, self.email_for(reviewer_id), "staff-password-test-123")[0], 303)
        self.assertEqual(verifier.request("/account/security", {
            "csrf": verifier.csrf("/account/security"), "action": "challenge", "code": recovery[0]
        })[0], 303)
        self.assertEqual(verifier.request("/admin/applications/" + app)[0], 404)

    def email_for(self, user_id):
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            return db.execute("SELECT email FROM users WHERE id=?", (user_id,)).fetchone()[0]

    def test_multi_school_role_change_and_no_guardian_promotion(self):
        self.assertEqual(self.cli("sergai").returncode, 0)
        own, others = self.schools()
        email, staff, user_id, _, recovery = self.staff("school_admin", [own, others[0]], "multi")
        body = staff.request("/admin/applications")[1]
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertEqual(db.execute("SELECT count(*) FROM staff_schools WHERE user_id=?", (user_id,)).fetchone()[0], 2)
            other_name = db.execute("SELECT name FROM master_schools WHERE id=?", (others[0],)).fetchone()[0]
        self.assertIn(other_name, body)
        path = f"/admin/accounts/{user_id}"
        self.assertEqual(self.admin.request(path, {
            "csrf": self.admin.csrf("/admin"), "version": 1, "role": "verifier", "enabled": "1",
            "schools[0]": own, "can_approve": "1"
        })[0], 303)
        self.assertEqual(staff.request("/admin")[2]["Location"], "/login")
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertEqual(db.execute("SELECT role,can_approve FROM staff_accounts WHERE user_id=?", (user_id,)).fetchone(), ("verifier", 1))
        # Cannot mutate or promote the guardian's base account using a staff edit URL.
        guardian = self.application(self.submit_fixture())["user_id"]
        self.assertEqual(self.admin.request(f"/admin/accounts/{guardian}", {
            "csrf": self.admin.csrf("/admin"), "version": "1", "role": "school_admin", "schools[0]": own
        })[0], 404)
        school_path = "/admin/master-data/schools/new"
        status, body, headers = self.admin.request(school_path, {
            "csrf": self.admin.csrf(school_path), "name": "Sekolah Grant Uji", "npsn": "99990888",
            "level": "SMP", "mode": "public_spmb", "province": "Provinsi Uji", "city": "Kabupaten Uji",
            "district": "Kecamatan Uji", "address": "Jalan Uji"
        })
        self.assertEqual(status, 303, body)
        unused_school = headers["Location"].split("/")[-1]
        self.assertEqual(self.admin.request(path, {
            "csrf": self.admin.csrf("/admin"), "version": "2", "role": "school_admin", "enabled": "1",
            "schools[0]": own, "schools[1]": unused_school
        })[0], 303)
        self.assertEqual(self.login(staff, email, "staff-password-test-123")[0], 303)
        self.assertEqual(staff.request("/account/security", {
            "csrf": staff.csrf("/account/security"), "action": "challenge", "code": recovery[0]
        })[0], 303)
        pending_email, result = self.invite("verifier", [unused_school], "deleted-school")
        self.assertEqual(result[0], 303, result[1])
        invitation = mail_path(self, pending_email, "/staff/accept")
        self.assertEqual(self.admin.request(f"/admin/master-data/schools/{unused_school}/delete", {
            "csrf": self.admin.csrf("/admin"), "version": "1", "confirm_delete": "1"
        })[0], 303)
        self.assertEqual(staff.request("/admin")[2]["Location"], "/login")
        self.assertEqual(Client(self.base).request(invitation)[0], 410)

    def test_email_verification_csrf_expiry_and_mfa_rate_limit(self):
        email = "security-guardian@example.test"
        guardian = Client(self.base)
        self.assertEqual(guardian.request("/register", {
            "csrf": guardian.csrf("/register"), "name": "Wali Uji Email", "email": email,
            "password": "password-uji-awal-123", "password_confirmation": "password-uji-awal-123", "privacy": "1"
        })[0], 303)
        self.assertEqual(self.login(guardian, email)[0], 303)
        self.assertEqual(guardian.request("/account/security", {"csrf": "bad", "action": "send-email"})[0], 419)
        self.assertEqual(guardian.request("/account/security", {
            "csrf": guardian.csrf("/account/security"), "action": "send-email"
        })[0], 303)
        path = mail_path(self, email, "/account/verify-email")
        self.assertEqual(guardian.request(path)[0], 200)
        self.assertEqual(guardian.request(path, {"csrf": guardian.csrf(path)})[0], 303)
        self.assertEqual(guardian.request(path, {"csrf": guardian.csrf(path)})[0], 410)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            uid = db.execute("SELECT id FROM users WHERE email=?", (email,)).fetchone()[0]
            token = "a" * 64
            db.execute("INSERT INTO email_verifications(token_hash,user_id,expires_at) VALUES(?,?,?)",
                       (hashlib.sha256(token.encode()).hexdigest(), uid, int(time.time()) - 1))
        path = "/account/verify-email?token=" + token
        self.assertEqual(guardian.request(path, {"csrf": guardian.csrf(path)})[0], 410)
        own, _ = self.schools()
        _, client, uid, _, _ = self.staff("school_admin", [own], "rate")
        client.request("/logout", {"csrf": client.csrf("/admin")})
        self.assertEqual(self.login(client, self.email_for(uid), "staff-password-test-123")[0], 303)
        # Identity-specific limiting is independent of other staff sessions.
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("DELETE FROM rate_limits WHERE bucket=?",
                       (hashlib.sha256(f"account-security:identity:{uid}".encode()).hexdigest(),))
        statuses = [client.request("/account/security", {
            "csrf": client.csrf("/account/security"), "action": "challenge", "code": "invalid"
        })[0] for _ in range(9)]
        self.assertEqual(statuses[-1], 429)
        self.assertTrue(all(status == 422 for status in statuses[:8]), statuses)

    def test_totp_matches_rfc_vectors_and_master_asset_is_served(self):
        result = subprocess.run([shutil.which("php"), "-r", """
            require 'app/account_security.php';
            $secret = encodeTotpSecret('12345678901234567890');
            foreach ([59,1111111109,1111111111,1234567890,2000000000] as $time) {
                echo totpCode($secret, intdiv($time,30)) . "\\n";
            }
        """], cwd=ROOT, capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.splitlines(), ["287082","081804","050471","005924","279037"])
        status, body, headers = Client(self.base).request("/assets/master.js?v=test")
        self.assertEqual(status, 200, body)
        self.assertIn("javascript", headers["Content-Type"])

    def test_mfa_optional_setup_cancel_disable_and_login(self):
        own, _ = self.schools()
        email, result = self.invite("school_admin", [own], "skip-testing")
        self.assertEqual(result[0], 303, result[1])
        client = Client(self.base)
        path = mail_path(self, email, "/staff/accept")
        self.assertEqual(client.request(path, {
            "csrf": client.csrf(path), "password": "staff-password-test-123",
            "password_confirmation": "staff-password-test-123", "privacy": "1"
        })[0], 303)
        self.assertEqual(self.login(client, email, "staff-password-test-123")[0], 303)
        body = client.request("/account/security")[1]
        self.assertIn("Atur 2FA", body)
        self.assertNotIn('id="mfa-secret"', body)
        self.assertNotIn("Lewati 2FA untuk pengujian", body)
        self.assertEqual(client.request("/account/security", {
            "csrf": "bad", "action": "start-setup"
        })[0], 419)
        self.assertEqual(client.request("/account/security", {
            "csrf": client.csrf("/account/security"), "action": "start-setup"
        })[0], 303)
        self.assertIn('id="mfa-secret"', client.request("/account/security")[1])
        self.assertEqual(client.request("/account/security", {
            "csrf": client.csrf("/account/security"), "action": "cancel-setup"
        })[0], 303)
        self.assertNotIn('id="mfa-secret"', client.request("/account/security")[1])
        self.assertEqual(client.request("/admin")[0], 200)
        self.assertEqual(client.request("/admin/accounts")[0], 403)
        self.assertEqual(client.request("/account/security", {
            "csrf": client.csrf("/account/security"), "action": "rotate", "confirm_rotate": "1",
            "password": "staff-password-test-123"
        })[0], 403)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            uid = db.execute("SELECT id FROM users WHERE email=?", (email,)).fetchone()[0]
            self.assertIsNone(db.execute("SELECT mfa_secret FROM account_security WHERE user_id=?", (uid,)).fetchone()[0])
        self.assertEqual(client.request("/logout", {"csrf": client.csrf("/admin")})[0], 303)
        self.assertEqual(self.login(client, email, "staff-password-test-123")[0], 303)
        self.assertEqual(client.request("/admin")[0], 200)
        second = Client(self.base)
        self.assertEqual(self.login(second, email, "staff-password-test-123")[0], 303)
        _, recovery = enroll_staff(self, client, email, "staff-password-test-123")
        self.assertEqual(second.request("/admin")[2]["Location"], "/login")
        self.assertEqual(client.request("/logout", {"csrf": client.csrf("/admin")})[0], 303)
        self.assertEqual(self.login(client, email, "staff-password-test-123")[0], 303)
        self.assertEqual(client.request("/admin")[2]["Location"], "/account/security")
        self.assertEqual(client.request("/account/security", {
            "csrf": client.csrf("/account/security"), "action": "skip-testing"
        })[0], 422)
        self.assertEqual(client.request("/account/security", {
            "csrf": client.csrf("/account/security"), "action": "disable", "confirm_rotate": "1",
            "password": "staff-password-test-123"
        })[0], 403)
        self.assertEqual(client.request("/account/security", {
            "csrf": client.csrf("/account/security"), "action": "challenge", "code": recovery[0]
        })[0], 303)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("DELETE FROM rate_limits")
        self.assertEqual(client.request("/account/security", {
            "csrf": client.csrf("/account/security"), "action": "disable", "confirm_rotate": "1",
            "password": "incorrect"
        })[0], 422)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("DELETE FROM rate_limits")
        self.assertEqual(client.request("/account/security", {
            "csrf": client.csrf("/account/security"), "action": "disable", "confirm_rotate": "1",
            "password": "staff-password-test-123"
        })[0], 303)
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            self.assertIsNone(db.execute("SELECT mfa_secret FROM account_security WHERE user_id=?", (uid,)).fetchone()[0])
            self.assertEqual(db.execute("SELECT count(*) FROM mfa_recovery_codes WHERE user_id=?", (uid,)).fetchone()[0], 0)
            self.assertEqual(db.execute("SELECT count(*) FROM audit_events WHERE user_id=? AND action='auth.mfa_disabled'", (uid,)).fetchone()[0], 1)
        self.assertEqual(client.request("/admin")[0], 200)
        self.assertEqual(client.request("/logout", {"csrf": client.csrf("/admin")})[0], 303)
        self.assertEqual(self.login(client, email, "staff-password-test-123")[0], 303)
        self.assertEqual(client.request("/admin")[0], 200)

    def test_email_still_required_and_old_skip_cannot_bypass_enabled_mfa(self):
        own, _ = self.schools()
        email, client, uid, _, _ = self.staff("school_admin", [own], "skip-guard")
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            db.execute("UPDATE account_security SET email_verified_at=NULL WHERE user_id=?", (uid,))
        self.assertNotIn("Lewati 2FA untuk pengujian", client.request("/account/security")[1])
        self.assertEqual(client.request("/account/security", {
            "csrf": client.csrf("/account/security"), "action": "start-setup"
        })[0], 403)
        self.assertEqual(client.request("/admin")[2]["Location"], "/account/security")
        session_id = next(cookie.value for cookie in self.admin.cookies if cookie.name == "spmb_session")
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            central_id = db.execute("SELECT user_id FROM admin_accounts LIMIT 1").fetchone()[0]
            db.execute("DELETE FROM rate_limits WHERE bucket=?",
                       (hashlib.sha256(f"account-security:identity:{central_id}".encode()).hexdigest(),))
        environment = dict(os.environ, APP_ENV="production", APP_URL="https://example.test",
                           APP_STORAGE=str(self.storage), MAIL_TRANSPORT="mail")
        code = """
            session_id($argv[1]);
            $_SERVER['REQUEST_METHOD']='POST'; $_SERVER['REQUEST_URI']='/account/security';
            $_POST=['csrf'=>$argv[2], 'action'=>'skip-testing'];
            register_shutdown_function(function(){ echo '\\nSTATUS=' . http_response_code(); });
            require 'public/index.php';
        """
        result = subprocess.run([shutil.which("php"), "-r", code, session_id, self.admin.csrf("/admin")],
                                cwd=ROOT, env=environment, capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("STATUS=422", result.stdout)
        self.assertNotIn("Lewati 2FA untuk pengujian", result.stdout)
        check = """
            require 'app/bootstrap.php';
            $_SESSION['mfa_testing_skipped']=true;
            unset($_SESSION['mfa_verified']);
            $user=['id'=>(int)$argv[1],'role'=>'central_admin'];
            echo staffPortalReady($db,$config,$user) ? 'ALLOWED' : 'BLOCKED';
        """
        result = subprocess.run([shutil.which("php"), "-r", check, str(central_id)],
                                cwd=ROOT, env=environment, capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, "BLOCKED")

    def test_guardian_can_opt_in_and_must_challenge_when_enabled(self):
        body = self.client.request("/account/security")[1]
        self.assertIn("Autentikasi dua faktor", body)
        self.assertNotIn('id="mfa-secret"', body)
        guardian_id = self.application(self.submit_fixture())["user_id"]
        with sqlite3.connect(self.storage / "app.sqlite") as db:
            email = db.execute("SELECT email FROM users WHERE id=?", (guardian_id,)).fetchone()[0]
        _, recovery = enroll_staff(self, self.client, email, "password-uji-awal-123")
        self.assertEqual(self.client.request("/logout", {"csrf": self.client.csrf("/dashboard")})[0], 303)
        self.assertEqual(self.login(self.client, email)[0], 303)
        for path in ["/dashboard", "/participants", "/admissions"]:
            self.assertEqual(self.client.request(path)[2]["Location"], "/account/security")
        self.assertEqual(self.client.request("/account/security", {
            "csrf": self.client.csrf("/account/security"), "action": "challenge", "code": recovery[0]
        })[0], 303)
        self.assertEqual(self.client.request("/dashboard")[0], 200)
