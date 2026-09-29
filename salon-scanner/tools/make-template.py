#!/usr/bin/env python3
"""
Builds scanner/template.js from the ticket artwork.

It finds every red tick box on the 300-dpi ticket image (by its white inside,
framed in red) and pairs them, top to bottom per column, with the services
listed below. Re-run it if the ticket design changes:

    pip install opencv-python-headless numpy
    python3 tools/make-template.py path/to/ticket.jpg

(Export the ticket PDF to JPEG first, e.g. `pdfimages -j Barber_Ticket_A6.pdf ticket`.)
"""
import json
import sys
from pathlib import Path

import cv2

# Boxes are sorted: left half of the ticket top→bottom, then right half top→bottom.
# (code, section, name, price in GBP or None for discounts)
SERVICES = [
    # Left half
    ("clipper-1", "Hair", "Clipper 1 no", 9.75),
    ("man-clipper-scissors", "Hair", "Man Clipper/Scissors", 13.75),
    ("man-scissors-cut", "Hair", "Man Scissors Cut", 15.75),
    ("skin-fade", "Hair", "Skin Fade", 15.75),
    ("wash", "Hair", "Wash", 2.00),
    ("man-booking", "Hair", "Man Booking", 19.75),
    ("man-wash", "Hair", "Man Wash", 9.75),
    ("beard-trim", "Hair", "Beard Trim", 5.75),
    ("neck-trim", "Hair", "Neck Trim", 4.75),
    ("line-up", "Hair", "Line Up", 2.00),
    ("beard-wet-shave", "Hair", "Beard Wet Shave/Foil Shaver", 9.75),
    ("head-wet-shave", "Hair", "Head Wet Shave/Foil Shaver", 12.75),
    ("hot-towel", "Hair", "Hot Towel", 2.00),
    ("toner", "Colour", "Toner", 19.75),
    ("colour-bleach-short", "Colour", "Colour/Bleach Short", 29.75),
    ("colour-bleach-medium", "Colour", "Colour/Bleach Medium", 39.75),
    ("colour-bleach-long", "Colour", "Colour/Bleach Long", 49.75),
    ("beard-colour", "Colour", "Beard Colour", 9.75),
    ("cap-highlights", "Colour", "Cap Highlights", 37.75),
    ("senior", "Discounts", "Senior (60+)", None),
    ("nhs-student", "Discounts", "NHS/Student", None),
    # Right half
    ("eyebrow-wax", "Grooming", "Eyebrow Wax", 9.75),
    ("eyebrow-threading", "Grooming", "Eyebrow Threading", 12.75),
    ("nose-wax", "Grooming", "Nose Wax", 4.75),
    ("ear-wax", "Grooming", "Ear Wax", 4.75),
    ("eyebrow-tint", "Grooming", "Eyebrow Tint", 7.75),
    ("mustache-tint", "Grooming", "Mustache Tint", 9.75),
    ("sides-threading", "Grooming", "Sides Threading", 9.75),
    ("full-face-brows-threading", "Grooming", "Full Face & Brows Threading", 32.75),
    ("forehead-threading", "Grooming", "Forehead Threading", 9.75),
    ("cheeks-wax", "Grooming", "Cheeks Wax", 4.75),
    ("perm-top-head", "Perm", "Perm Top Head", 50.00),
    ("perm-top-sides", "Perm", "Perm Top & Sides", 70.00),
    ("crazy-colours", "Colour", "Crazy Colours", 24.75),
    ("perm-medium-hair", "Perm", "Perm Medium Hair", 90.00),
    ("perm-long-hair", "Perm", "Perm Long Hair", 120.00),
    ("brazilian-top-head", "Brazilian/Botox", "Brazilian/Botox Top Head", 50.00),
    ("brazilian-top-sides", "Brazilian/Botox", "Brazilian/Botox Top & Sides", 70.00),
    ("brazilian-medium-hair", "Brazilian/Botox", "Brazilian/Botox Medium Hair", 98.00),
    ("six-free-cut", "Discounts", "6th Free Cut", None),
    ("brazilian-long-hair", "Brazilian/Botox", "Brazilian/Botox Long Hair", 120.00),
]

# Handwritten boxes (inside of the black frames), in template pixels.
FIELDS = [
    ("staff", "Stylist (C number)", [618, 185, 512, 81]),
    ("treatments", "How many treatments", [25, 1116, 232, 120]),
    ("tips", "Tips", [265, 1116, 232, 120]),
]


def find_boxes(img):
    h, w = img.shape[:2]
    hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)
    red = cv2.bitwise_or(cv2.inRange(hsv, (0, 120, 90), (10, 255, 255)),
                         cv2.inRange(hsv, (170, 120, 90), (180, 255, 255)))
    white = cv2.inRange(hsv, (0, 0, 225), (180, 40, 255))
    n, _, stats, _ = cv2.connectedComponentsWithStats(white, 8)
    boxes = []
    for i in range(1, n):
        x, y, bw, bh, area = stats[i]
        if not (45 <= bw <= 70 and 18 <= bh <= 34 and area > 0.85 * bw * bh):
            continue
        pad = 7
        ring = red[max(0, y - pad):y + bh + pad, max(0, x - pad):x + bw + pad]
        sides = (ring[:pad, :].mean(), ring[-pad:, :].mean(), ring[:, :pad].mean(), ring[:, -pad:].mean())
        if min(sides) < 60:
            continue
        boxes.append([int(x), int(y), int(bw), int(bh)])
    boxes.sort(key=lambda b: (b[0] > w * 0.55, b[1]))
    return boxes


def main():
    source = Path(sys.argv[1] if len(sys.argv) > 1 else "ticket.jpg")
    img = cv2.imread(str(source))
    if img is None:
        sys.exit(f"Can't read {source}")
    h, w = img.shape[:2]
    boxes = find_boxes(img)
    if len(boxes) != len(SERVICES):
        sys.exit(f"Found {len(boxes)} boxes but {len(SERVICES)} services are listed; check the artwork.")

    template = {
        "id": "barber-a6-v1",
        "name": "Barber ticket (A6)",
        "width": w,
        "height": h,
        "currency": "GBP",
        "boxes": [
            {"code": code, "section": section, "name": name, "price": price, "rect": box}
            for (code, section, name, price), box in zip(SERVICES, boxes)
        ],
        "fields": [{"key": key, "label": label, "rect": rect} for key, label, rect in FIELDS],
    }
    out = Path(__file__).resolve().parent.parent / "scanner" / "template.js"
    out.write_text(
        "// Generated by tools/make-template.py from the ticket artwork. Positions are in pixels\n"
        "// of the 300-dpi ticket image; prices can be edited here directly.\n"
        f"window.TICKET_TEMPLATE = {json.dumps(template, indent=2)};\n"
    )
    print(f"Wrote {out} with {len(boxes)} boxes")


if __name__ == "__main__":
    main()
