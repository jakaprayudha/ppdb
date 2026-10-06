"""Location integration tests use synthetic coordinates and a loopback-only service."""

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import threading
import unittest

import auth_flow as auth_helpers
from auth_flow import Client


class LocationService(BaseHTTPRequestHandler):
    def do_POST(self):
        self.server.requests.append(json.loads(self.rfile.read(int(self.headers["Content-Length"]))))
        self.send_response(self.server.status)
        self.send_header("Content-Type", "application/json")
        if self.server.status == 302:
            self.send_header("Location", "/must-not-follow")
        self.end_headers()
        self.wfile.write(self.server.body)

    def log_message(self, *args):
        pass


class LocationFlow(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.service = ThreadingHTTPServer(("127.0.0.1", 0), LocationService)
        cls.thread = threading.Thread(target=cls.service.serve_forever, daemon=True)
        cls.thread.start()
        cls.server_environment = {"GEOCODING_URL": f"http://127.0.0.1:{cls.service.server_port}/reverse"}
        try:
            auth_helpers.AuthFlow.setUpClass.__func__(cls)
        except Exception:
            cls.service.shutdown()
            cls.service.server_close()
            cls.thread.join()
            raise

    stop_server = classmethod(auth_helpers.AuthFlow.stop_server.__func__)
    register = auth_helpers.AuthFlow.register
    login = auth_helpers.AuthFlow.login

    @classmethod
    def tearDownClass(cls):
        cls.stop_server()
        cls.service.shutdown()
        cls.service.server_close()
        cls.thread.join()

    def setUp(self):
        self.client = Client(self.base)
        email = self._testMethodName[:50] + "@example.test"
        self.assertEqual(self.register(email)[0], 303)
        self.assertEqual(self.login(self.client, email)[0], 303)
        self.payload = {
            "csrf": self.client.csrf("/participants/new"),
            "location_consent": "1", "latitude": "3.5", "longitude": "99.1"
        }
        self.address = {
            "country_code": "id", "province": "Provinsi Uji", "city": "Kabupaten Uji",
            "district": "Kecamatan Uji", "village": "Desa Uji", "postal_code": "12345"
        }
        self.service.requests = []
        self.service.status = 200
        self.service.body = json.dumps(self.address).encode()

    def request(self, **changes):
        return self.client.request("/participants/location", {**self.payload, **changes})

    def test_complete_and_partial_address(self):
        status, body, headers = self.request()
        self.assertEqual(status, 200, body)
        self.assertIn("application/json", headers["Content-Type"])
        self.assertEqual(json.loads(body)["address"], {k: v for k, v in self.address.items() if k != "country_code"})
        self.assertEqual(self.service.requests, [{"latitude": 3.5, "longitude": 99.1}])
        self.assertNotIn("latitude", body)
        self.service.body = b'{"country_code":"id","province":"Provinsi Uji"}'
        status, body, _ = self.request()
        self.assertEqual(status, 200, body)
        self.assertEqual(json.loads(body)["address"]["postal_code"], "")

    def test_auth_method_consent_and_csrf(self):
        self.assertEqual(Client(self.base).request("/participants/location", self.payload)[0], 303)
        self.assertEqual(self.client.request("/participants/location")[0], 405)
        self.assertEqual(self.request(csrf="invalid")[0], 419)
        self.assertEqual(self.request(location_consent="0")[0], 422)
        self.assertEqual(self.service.requests, [])

    def test_coordinates_and_rate_limit(self):
        for changes in [{"latitude": "nan"}, {"latitude": "91"}, {"longitude": "-181"}, {"longitude": ""}]:
            self.assertEqual(self.request(**changes)[0], 422)
        self.assertEqual(self.service.requests, [])
        for _ in range(15):
            self.assertEqual(self.request()[0], 200)
        status, body, headers = self.request()
        self.assertEqual(status, 429, body)
        self.assertEqual(headers["Retry-After"], "900")
        self.assertEqual(len(self.service.requests), 15)

    def test_invalid_service_responses(self):
        for payload, expected in [
            (b"not json", 502), (b"[]", 502),
            (b'{"country_code":"sg","province":"Uji"}', 422),
            (b'{"country_code":"id"}', 422),
            (b'{"country_code":"id","postal_code":"123456"}', 502),
            (b'{"country_code":"id","province":[]}', 502),
            (b"x" * 32769, 502),
        ]:
            self.service.body = payload
            status, body, _ = self.request()
            self.assertEqual(status, expected, body)
            self.assertIn("error", json.loads(body))

    def test_upstream_error_and_redirect_not_followed(self):
        for status in [503, 302]:
            self.service.status = status
            result, body, _ = self.request()
            self.assertEqual(result, 502, body)
        self.assertEqual(len(self.service.requests), 2)
