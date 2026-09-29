#!/usr/bin/env python3
"""
Makes realistic "phone photos" of filled-in tickets for testing the recognizer:
random ticks (blue/black/red pen, ticks, crosses, scribbles, some spilling out of
the box), handwriting in the C / Treatments / Tips boxes, then perspective, any
rotation, uneven lighting, colour casts, blur, noise and JPEG compression.

    python3 tools/make-test-photos.py ticket.jpg out_dir 200 [seed] [hard]
Writes out_dir/NNN.jpg and out_dir/truth.json. "hard" makes the ticket smaller in
the frame, more slanted, blurrier and with stronger colour casts.
"""
import json
import math
import random
import sys
from pathlib import Path

import cv2
import numpy as np

TEMPLATE = Path(__file__).resolve().parent.parent / "scanner" / "template.js"


def load_template():
    text = TEMPLATE.read_text()
    return json.loads(text[text.index("{"): text.rindex("}") + 1])


def draw_tick(img, rect, rng):
    x, y, w, h = rect
    cx, cy = x + w / 2 + rng.uniform(-0.2, 0.2) * w, y + h / 2 + rng.uniform(-0.25, 0.25) * h
    size = rng.uniform(0.7, 1.5)
    colour = rng.choice([(150, 50, 20), (120, 40, 30), (40, 35, 35), (25, 25, 25), (40, 40, 190)])  # BGR: blue, dark blue, black, black, red
    thick = rng.randint(3, 7)
    style = rng.choice(["check", "check", "check", "cross", "scribble", "line"])
    s = h * size
    if style == "check":
        pts = [(cx - 0.6 * s, cy), (cx - 0.1 * s, cy + 0.5 * s), (cx + 0.9 * s, cy - 0.8 * s)]
    elif style == "cross":
        cv2.line(img, (int(cx - 0.6 * s), int(cy - 0.5 * s)), (int(cx + 0.6 * s), int(cy + 0.5 * s)), colour, thick, cv2.LINE_AA)
        pts = [(cx - 0.6 * s, cy + 0.5 * s), (cx + 0.6 * s, cy - 0.5 * s)]
    elif style == "scribble":
        pts = [(cx + rng.uniform(-0.8, 0.8) * s * 1.2, cy + rng.uniform(-0.4, 0.4) * s) for _ in range(7)]
    else:
        pts = [(cx - 0.9 * s, cy + 0.2 * s), (cx + 0.9 * s, cy - 0.2 * s)]
    pts = np.array([(int(px), int(py)) for px, py in pts], np.int32)
    cv2.polylines(img, [pts], False, colour, thick, cv2.LINE_AA)


def draw_handwriting(img, rect, text, rng):
    x, y, w, h = rect
    colour = rng.choice([(150, 50, 20), (30, 30, 30)])
    scale = h / 45 * rng.uniform(0.9, 1.3)
    cv2.putText(img, text, (int(x + w * rng.uniform(0.2, 0.4)), int(y + h * 0.78)),
                cv2.FONT_HERSHEY_SCRIPT_SIMPLEX, scale, colour, max(2, int(scale * 2)), cv2.LINE_AA)


def photo(ticket, tpl, rng, index, hard=False):
    img = ticket.copy()
    ticked = sorted(rng.sample(range(len(tpl["boxes"])), rng.choice([0, 1, 1, 2, 2, 3, 3, 4, 5, 6])))
    for i in ticked:
        draw_tick(img, tpl["boxes"][i]["rect"], rng)
    fields = {f["key"]: f["rect"] for f in tpl["fields"]}
    draw_handwriting(img, fields["staff"], str(rng.randint(100, 999)), rng)
    draw_handwriting(img, fields["treatments"], str(len(ticked)), rng)
    draw_handwriting(img, fields["tips"], str(rng.choice([0, 2, 3, 5, 10])), rng)

    # Phone frame: landscape or portrait, ticket at a random angle and slant.
    portrait = rng.random() < 0.35
    W, H = (2400, 3200) if portrait else (3200, 2400)
    th, tw = img.shape[:2]
    rot = rng.uniform(-25, 25) + rng.choice([0, 0, 0, 90, 180, -90])
    fill = rng.uniform(0.5, 0.95) if hard else rng.uniform(0.62, 0.92)
    ang = math.radians(rot)
    # size so the rotated ticket fits the frame at "fill"
    bw = abs(tw * math.cos(ang)) + abs(th * math.sin(ang))
    bh = abs(tw * math.sin(ang)) + abs(th * math.cos(ang))
    k = fill * min(W / bw, H / bh)
    cx, cy = W / 2 + rng.uniform(-0.05, 0.05) * W, H / 2 + rng.uniform(-0.05, 0.05) * H
    corners = []
    for px, py in [(0, 0), (tw, 0), (tw, th), (0, th)]:
        dx, dy = (px - tw / 2) * k, (py - th / 2) * k
        rx = dx * math.cos(ang) - dy * math.sin(ang)
        ry = dx * math.sin(ang) + dy * math.cos(ang)
        j = (0.09 if hard else 0.05) * k * tw
        corners.append((cx + rx + rng.uniform(-j, j), cy + ry + rng.uniform(-j, j)))
    M = cv2.getPerspectiveTransform(np.float32([(0, 0), (tw, 0), (tw, th), (0, th)]), np.float32(corners))

    bg = np.full((H, W, 3), rng.choice([(70, 90, 110), (200, 205, 210), (40, 45, 50), (120, 140, 170), (230, 230, 225)]), np.uint8)
    bg = cv2.add(bg, np.random.default_rng(index).integers(0, 25, (H, W, 3), dtype=np.uint8))
    out = cv2.warpPerspective(img, M, (W, H), dst=bg, borderMode=cv2.BORDER_TRANSPARENT, flags=cv2.INTER_LINEAR)

    # Lighting: gradient + soft shadow + colour cast.
    yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
    g = rng.uniform(0.55, 0.8) + rng.uniform(0.2, 0.45) * (xx * rng.uniform(-1, 1) / W + yy * rng.uniform(-1, 1) / H + 1) / 2
    if rng.random() < 0.5:
        sx, sy, r = rng.uniform(0, W), rng.uniform(0, H), rng.uniform(0.2, 0.5) * W
        g *= 1 - 0.35 * np.exp(-(((xx - sx) ** 2 + (yy - sy) ** 2) / (2 * r * r)))
    c = 0.25 if hard else 0.15
    cast = np.array([rng.uniform(1 - c, 1 + c), rng.uniform(1 - c / 2, 1 + c / 2), rng.uniform(1 - c, 1 + c)], np.float32)
    out = np.clip(out.astype(np.float32) * g[..., None] * cast * rng.uniform(0.95, 1.15), 0, 255).astype(np.uint8)
    blur = rng.choice([1, 2, 3, 4]) if hard else rng.choice([0, 0, 1, 1, 2])
    if blur:
        out = cv2.GaussianBlur(out, (0, 0), blur * 0.7)
    noise = np.random.default_rng(index + 7).normal(0, rng.uniform(2, 7), out.shape)
    out = np.clip(out + noise, 0, 255).astype(np.uint8)
    ok, enc = cv2.imencode(".jpg", out, [cv2.IMWRITE_JPEG_QUALITY, rng.randint(72, 92)])
    return enc.tobytes(), [tpl["boxes"][i]["code"] for i in ticked], {"rotation": round(rot, 1), "portrait": portrait, "fill": round(fill, 2), "blur": blur}


def main():
    ticket = cv2.imread(sys.argv[1])
    out_dir = Path(sys.argv[2])
    count = int(sys.argv[3]) if len(sys.argv) > 3 else 100
    out_dir.mkdir(parents=True, exist_ok=True)
    tpl = load_template()
    seed = int(sys.argv[4]) if len(sys.argv) > 4 else 42
    hard = len(sys.argv) > 5 and sys.argv[5] == "hard"
    rng = random.Random(seed)
    truth = {}
    for i in range(count):
        data, codes, meta = photo(ticket, tpl, rng, i + seed * 1000, hard)
        name = f"{i:03d}.jpg"
        (out_dir / name).write_bytes(data)
        truth[name] = {"ticked": codes, **meta}
    (out_dir / "truth.json").write_text(json.dumps(truth, indent=1))
    print(f"Wrote {count} photos to {out_dir}")


if __name__ == "__main__":
    main()
