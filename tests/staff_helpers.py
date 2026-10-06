"""Real email-token and MFA enrollment helpers for isolated HTTP tests."""

import base64
import hashlib
import hmac
import re
import sqlite3
import struct
import time


def totp(secret, counter=None):
    counter = int(time.time() // 30) if counter is None else counter
    key = base64.b32decode(secret)
    digest = hmac.new(key, struct.pack(">Q", counter), hashlib.sha1).digest()
    offset = digest[-1] & 15
    return f"{(struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7fffffff) % 1000000:06d}"


def mail_path(test, email, route):
    messages = [path for path in test.storage.joinpath("mail").glob("*.eml")
                if f"To: {email}\n" in path.read_text() and route in path.read_text()]
    message = max(messages, key=lambda path: path.stat().st_mtime_ns).read_text()
    return re.search(re.escape(route) + r"\?token=[a-f0-9]{64}", message).group(0)


def enroll_staff(test, client, email, password):
    status, body, _ = client.request("/account/security")
    test.assertEqual(status, 200, body)
    if 'value="send-email"' in body:
        status, body, _ = client.request("/account/security", {
            "csrf": client.csrf("/account/security"), "action": "send-email"
        })
        test.assertEqual(status, 303, body)
        path = mail_path(test, email, "/account/verify-email")
        status, body, _ = client.request(path, {"csrf": client.csrf(path)})
        test.assertEqual(status, 303, body)
        body = client.request("/account/security")[1]
    if 'id="mfa-secret"' not in body:
        status, body, _ = client.request("/account/security", {
            "csrf": client.csrf("/account/security"), "action": "start-setup"
        })
        test.assertEqual(status, 303, body)
        body = client.request("/account/security")[1]
    secret = re.search(r'id="mfa-secret" value="([A-Z2-7]+)"', body).group(1)
    status, body, _ = client.request("/account/security", {
        "csrf": client.csrf("/account/security"), "action": "enroll",
        "password": password, "code": totp(secret)
    })
    test.assertEqual(status, 303, body)
    body = client.request("/account/security")[1]
    codes = re.findall(r"<code>([a-f0-9]{16})</code>", body)
    test.assertEqual(len(codes), 8, body)
    return secret, codes


def complete_central_security(test):
    cache = type(test).central_security
    # Setup is not a rate-limit scenario: isolate the shared central account's
    # bucket so opt-in enrollment and previous tests do not exhaust later setup.
    with sqlite3.connect(test.storage / "app.sqlite") as db:
        uid = db.execute("SELECT user_id FROM admin_accounts LIMIT 1").fetchone()[0]
        db.execute("DELETE FROM rate_limits WHERE bucket=?",
                   (hashlib.sha256(f"account-security:identity:{uid}".encode()).hexdigest(),))
    if not cache:
        cache["secret"], cache["codes"] = enroll_staff(test, test.admin, "admin.pusat@example.test", test.admin_password)
    else:
        code = cache["codes"].pop() if cache["codes"] else totp(cache["secret"], int(time.time() // 30) + 1)
        status, body, _ = test.admin.request("/account/security", {
            "csrf": test.admin.csrf("/account/security"), "action": "challenge", "code": code
        })
        test.assertEqual(status, 303, body)
