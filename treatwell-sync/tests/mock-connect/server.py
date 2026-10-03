"""
A pretend Treatwell Connect for testing the extension (not the real thing: Treatwell's real
format is unknown here). It needs a login cookie AND a bearer token, like many web apps.

  python3 tests/mock-connect/server.py   → https on 127.0.0.1:443 (map connect.treatwell.co.uk to it)
                                            test controls on http://127.0.0.1:8444
"""
import datetime
import json
import os
import ssl
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

HERE = os.path.dirname(os.path.abspath(__file__))
TOKEN = "tok-" + os.urandom(8).hex()
SESSION = "sid-" + os.urandom(8).hex()
state = {"logged_in": True, "log": []}
lock = threading.Lock()


def today(offset=0):
    return (datetime.date.today() + datetime.timedelta(days=offset)).isoformat()


EMPLOYEES = [{"id": 11, "name": "Renato"}, {"id": 12, "firstName": "Mohammad", "lastName": "A"}, {"id": 13, "name": "Sofia"}]
appointments = {
    501: {"id": 501, "day": 0, "startTime": "10:30", "endTime": "11:15", "employeeId": 11, "offerName": "Skin Fade",
          "customer": {"firstName": "John", "lastName": "Smith", "phone": "07700 900123"}, "status": "CONFIRMED", "amount": 15.75},
    502: {"id": 502, "day": 0, "startTime": "09:00", "endTime": "09:30", "employeeId": 12, "offerName": "Beard Trim",
          "customer": {"firstName": "Ali", "lastName": "K"}, "status": "CONFIRMED", "amount": 8.0, "notesForVenue": "Running 5 min late"},
    503: {"id": 503, "day": 0, "startTime": "14:00", "endTime": "15:00", "employeeId": 13, "offerName": "Colour/Bleach Short Hair",
          "customer": {"firstName": "Emma", "lastName": "Jones"}, "status": "CONFIRMED", "amount": 40.0},
    601: {"id": 601, "day": 1, "startTime": "12:00", "endTime": "12:30", "employeeId": 11, "offerName": "Wash",
          "customer": {"firstName": "Sam", "lastName": "Lee"}, "status": "CONFIRMED", "amount": 3.0},
}


class Connect(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def send(self, code, body, ctype="application/json", extra=None):
        data = body if isinstance(body, bytes) else (json.dumps(body) if ctype == "application/json" else body).encode()
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(data)

    def authed(self):
        cookie_ok = ("sid=" + SESSION) in (self.headers.get("Cookie") or "")
        token_ok = self.headers.get("Authorization") == "Bearer " + TOKEN
        return state["logged_in"] and cookie_ok and token_ok

    def record(self):
        with lock:
            state["log"].append({"method": self.command, "path": self.path,
                                 "auth": bool(self.headers.get("Authorization")), "cookie": "sid=" in (self.headers.get("Cookie") or "")})

    def do_GET(self):
        url = urlparse(self.path)
        q = parse_qs(url.query)
        if url.path in ("/", "/calendar"):
            html = open(os.path.join(HERE, "calendar.html"), "rb").read()
            return self.send(200, html, "text/html; charset=utf-8", {"Set-Cookie": f"sid={SESSION}; Path=/; Secure; HttpOnly; SameSite=Lax"})
        if url.path.startswith("/api/"):
            self.record()
        if url.path == "/api/v1/session":
            if not state["logged_in"] or ("sid=" + SESSION) not in (self.headers.get("Cookie") or ""):
                return self.send(401, {"error": "login"})
            return self.send(200, {"token": TOKEN, "venue": {"id": 42, "name": "Test Barber"}, "user": {"firstName": "Reception"}})
        if url.path == "/api/v1/venue/42/employees":
            if not self.authed():
                return self.send(401, {"error": "login"})
            return self.send(200, {"employees": EMPLOYEES})
        if url.path == "/api/v1/venue/42/calendar":
            if not self.authed():
                return self.send(401, {"error": "login"})
            d_from, d_to = q.get("date-from", [today()])[0], q.get("date-to", [today()])[0]
            out = []
            for a in appointments.values():
                d = today(a["day"])
                if d_from <= d <= d_to:
                    item = {k: v for k, v in a.items() if k != "day"}
                    item["appointmentDate"] = d
                    out.append(item)
            return self.send(200, {"appointments": out,
                                   "workingHours": [{"employeeId": e["id"], "date": d_from, "startTime": "09:00", "endTime": "18:00"} for e in EMPLOYEES],
                                   "blockedTimes": [{"employeeId": 11, "date": d_from, "startTime": "13:00", "endTime": "13:30", "title": "Lunch"}]})
        if url.path == "/api/v1/venue/42/reviews":
            if not self.authed():
                return self.send(401, {"error": "login"})
            return self.send(200, {"reviews": [{"date": today(), "time": "10:00", "customer": {"name": "Happy Client"}, "service": "Skin Fade", "rating": 5}]})
        return self.send(404, {"error": "not found"})

    def do_POST(self):
        self.record()
        length = int(self.headers.get("Content-Length") or 0)
        self.rfile.read(length)
        return self.send(200, {"ok": True})


class Control(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        url = urlparse(self.path)
        q = parse_qs(url.query)
        with lock:
            if url.path == "/cancel":
                appointments[int(q["id"][0])]["status"] = "CANCELLED"
            elif url.path == "/delete":
                appointments.pop(int(q["id"][0]), None)
            elif url.path == "/add":
                appointments[504] = {"id": 504, "day": 0, "startTime": "16:30", "endTime": "17:00", "employeeId": 12, "offerName": "Hot Towel",
                                     "customer": {"firstName": "Lucy", "lastName": "Hall", "phone": "07123 456789"}, "status": "CONFIRMED", "amount": 6.5}
            elif url.path == "/logout":
                state["logged_in"] = False
            elif url.path == "/login":
                state["logged_in"] = True
            elif url.path == "/log":
                pass
            body = json.dumps(state["log"]).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(body)


def main():
    cert, key = os.path.join(HERE, ".cert.pem"), os.path.join(HERE, ".key.pem")
    if not os.path.exists(cert):
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "2", "-subj", "/CN=connect.treatwell.co.uk",
                        "-keyout", key, "-out", cert], check=True, capture_output=True)
    https = ThreadingHTTPServer(("127.0.0.1", 443), Connect)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(cert, key)
    https.socket = ctx.wrap_socket(https.socket, server_side=True)
    threading.Thread(target=ThreadingHTTPServer(("127.0.0.1", 8444), Control).serve_forever, daemon=True).start()
    print("mock connect ready", flush=True)
    https.serve_forever()


if __name__ == "__main__":
    main()
