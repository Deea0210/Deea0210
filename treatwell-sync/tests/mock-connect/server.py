"""
A pretend Treatwell Connect for testing the extension. Its answers copy the layout of the real
Connect calendar (from a setup file, with made-up names): calendar.json with appointments,
appointmentGroups and blocks, appointmentStatusCode CR/CN/CP/NS/CC, employees.json, the
activity feed and the waiting list. Like the real one it needs the login cookie, and here also
the X-Requested-With header the page sends.

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
SESSION = "sid-" + os.urandom(8).hex()
state = {"logged_in": True, "log": []}
lock = threading.Lock()


def day(offset=0):
    return (datetime.date.today() + datetime.timedelta(days=offset)).isoformat()


EMPLOYEES = {110053: "S.Renato", 472945: "Zane", 491812: "Mohammad A", 529941: "Sofia"}


def appt(id_, offset, status, start, end, emp, offer, name, phone=None, email=None, amount=0.0, notes="", actor="SUPPLIER"):
    return {"id": id_, "day": offset, "appointmentStatusCode": status, "bookingActor": actor, "channelCode": "WHN_GB",
            "platform": "DESKTOP", "consumerName": name, "consumerFirstName": name.split()[0], "consumerPhone": phone,
            "consumerEmail": email, "created": "2026-09-01T10:00:00Z", "createdByName": "Reception", "amount": amount,
            "currencyCode": "GBP", "employeeId": emp, "employeeName": EMPLOYEES[emp], "startTime": start, "endTime": end,
            "notes": notes, "offerId": 1, "offerName": offer, "skus": [{"skuId": 2, "skuName": offer + " (standard)"}],
            "appointmentGroupIds": [], "walkIn": False, "consumerId": id_ * 7}


appointments = {a["id"]: a for a in [
    appt(501, 0, "CN", "10:30", "11:15", 110053, "Skin Fade", "John Smith", "+44 7700 900123", "john@example.com", 15.75),
    appt(502, 0, "CN", "09:00", "09:30", 491812, "Beard Trim", "Ali K", amount=8.0, notes="Running 5 min late"),
    appt(503, 0, "CN", "14:00", "15:00", 529941, "Colour/Bleach Short Hair", "Emma Jones", amount=40.0),
    appt(505, 0, "NS", "11:00", "11:30", 529941, "Wash & Blow Dry", "Nora West", amount=25.6),
    appt(506, 0, "CP", "09:30", "10:00", 110053, "Men - wash & Haircut", "Paul Kim", amount=19.8),
    appt(507, 0, "CN", "12:00", "12:35", 472945, "Conditioning (add-on)", "Valentina", "+44 7932 000000", amount=24.0),
    appt(508, 0, "CN", "12:35", "13:05", 472945, "Wash & Blow Dry", "Valentina", "+44 7932 000000", amount=32.0),
    appt(601, 1, "CN", "12:00", "12:30", 110053, "Wash", "Sam Lee", amount=3.0),
]}
GROUPS = [{"id": 9001, "name": "Conditioning + Wash & Blow Dry", "price": {"amount": 56.0, "currencyCode": "GBP"}, "startTime": "12:00",
           "endTime": "13:05", "customer": {"id": 3549, "name": "Valentina", "phone": "+44 7932 000000"}, "appointmentIds": [507, 508],
           "type": "PACKAGE", "bookingActor": "SUPPLIER", "day": 0}]


def calendar(d_from, d_to, codes):
    def visible(a):
        return d_from <= day(a["day"]) <= d_to and a["appointmentStatusCode"] in codes

    def out(a):
        item = {k: v for k, v in a.items() if k != "day"}
        item["appointmentDate"] = day(a["day"])
        return item

    groups = []
    for g in GROUPS:
        members = [appointments[i] for i in g["appointmentIds"] if i in appointments and visible(appointments[i])]
        if members:
            item = {k: v for k, v in g.items() if k != "day"}
            item.update(appointmentDate=day(g["day"]), appointmentStatusCode=members[0]["appointmentStatusCode"],
                        appointments=[out(m) for m in members])
            groups.append(item)
    return {
        "appointments": [out(a) for a in appointments.values() if visible(a)],
        "appointmentGroups": groups,
        "blocks": [{"availabilityRuleTypeCode": "B", "dateFrom": d_from, "dateTo": d_from, "employeeId": 110053, "itemDate": d_from,
                    "itemTimeFrom": "13:00", "itemTimeTo": "13:30", "name": "Lunch", "timeFrom": "13:00", "timeTo": "13:30", "type": "IL"}],
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
        return (state["logged_in"] and ("sid=" + SESSION) in (self.headers.get("Cookie") or "")
                and self.headers.get("X-Requested-With") == "XMLHttpRequest")

    def do_GET(self):
        url = urlparse(self.path)
        q = parse_qs(url.query)
        if url.path in ("/", "/calendar"):
            html = open(os.path.join(HERE, "calendar.html"), "rb").read()
            return self.send(200, html, "text/html; charset=utf-8", {"Set-Cookie": f"sid={SESSION}; Path=/; Secure; HttpOnly; SameSite=Lax"})
        if not url.path.startswith("/api/"):
            return self.send(404, {"error": "not found"})
        with lock:
            state["log"].append({"method": "GET", "path": self.path, "header": self.headers.get("X-Requested-With") == "XMLHttpRequest",
                                 "cookie": "sid=" in (self.headers.get("Cookie") or "")})
        if not self.authed():
            return self.send(401, {"error": "login"})
        if url.path == "/api/venue/42/employees.json":
            return self.send(200, {"employees": [{"active": True, "id": i, "name": n, "takesAppointments": True} for i, n in EMPLOYEES.items()]})
        if url.path == "/api/venue/42/calendar.json":
            codes = q.get("appointment-status-codes", ["CR,CN,NS,CP"])[0].split(",")
            return self.send(200, calendar(q.get("date-from", [day()])[0], q.get("date-to", [day()])[0], codes))
        if url.path == "/api/venue/42/activity/appointment-events.json":
            return self.send(200, {"appointmentEventDatas": [
                {"appointmentEventId": 1, "appointmentEventType": "CREATED", "appointmentId": 777, "occurredAt": day() + "T08:00:00Z",
                 "appointmentLocalDateTime": day(5) + "T15:00:00", "currentAppointmentStatus": "CONFIRMED",
                 "recipientName": "Booked Today", "employeeName": "Zane"}]})
        if url.path == "/api/calendar/venue/42/waiting-list-entry":
            return self.send(200, [{"id": 1, "date": day(), "startTime": "15:00", "customer": {"name": "Waiting Client"}, "offerName": "Wash"}])
        return self.send(404, {"error": "not found"})

    def do_POST(self):
        with lock:
            state["log"].append({"method": "POST", "path": self.path})
        self.rfile.read(int(self.headers.get("Content-Length") or 0))
        return self.send(200, {"ok": True})


class Control(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        url = urlparse(self.path)
        q = parse_qs(url.query)
        with lock:
            target = appointments.get(int(q["id"][0])) if "id" in q else None
            if url.path == "/status" and target:
                target["appointmentStatusCode"] = q["code"][0]
            elif url.path == "/delete":
                appointments.pop(int(q["id"][0]), None)
            elif url.path == "/add":
                appointments[504] = appt(504, 0, "CR", "16:30", "17:00", 491812, "Hot Towel", "Lucy Hall", "+44 7123 456789", amount=6.5,
                                         actor="CUSTOMER")
            elif url.path == "/logout":
                state["logged_in"] = False
            elif url.path == "/login":
                state["logged_in"] = True
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
