/*
 * Ticket recognizer (no dependencies, runs in the phone's browser).
 *
 * 1. findTicket(): finds the red tick boxes in a photo and uses them as
 *    landmarks to work out exactly where the ticket is (a "homography" that maps
 *    ticket positions to photo positions). Works at any angle, including sideways
 *    or upside down, and with the ticket at a slant.
 * 2. readTicks(): looks inside every box and measures how much pen ink it holds.
 * 3. rectify(): cuts out a straightened part of the ticket (e.g. the Tips box).
 */
(function (root) {
    'use strict';

    // ---------------------------------------------------------------- maths

    function mul3(a, b) {
        const r = new Array(9);
        for (let i = 0; i < 3; i++) {
            for (let j = 0; j < 3; j++) {
                r[i * 3 + j] = a[i * 3] * b[j] + a[i * 3 + 1] * b[3 + j] + a[i * 3 + 2] * b[6 + j];
            }
        }
        return r;
    }

    function inv3(m) {
        const [a, b, c, d, e, f, g, h, i] = m;
        const A = e * i - f * h, B = -(d * i - f * g), C = d * h - e * g;
        const det = a * A + b * B + c * C;
        if (Math.abs(det) < 1e-15) return null;
        return [
            A / det, -(b * i - c * h) / det, (b * f - c * e) / det,
            B / det, (a * i - c * g) / det, -(a * f - c * d) / det,
            C / det, -(a * h - b * g) / det, (a * e - b * d) / det,
        ];
    }

    function project(H, x, y) {
        const w = H[6] * x + H[7] * y + H[8];
        return [(H[0] * x + H[1] * y + H[2]) / w, (H[3] * x + H[4] * y + H[5]) / w];
    }

    function solveLinear(A, b) {
        const n = b.length;
        const M = A.map((row, i) => row.concat([b[i]]));
        for (let c = 0; c < n; c++) {
            let p = c;
            for (let r = c + 1; r < n; r++) if (Math.abs(M[r][c]) > Math.abs(M[p][c])) p = r;
            if (Math.abs(M[p][c]) < 1e-12) return null;
            const tmp = M[c]; M[c] = M[p]; M[p] = tmp;
            for (let r = c + 1; r < n; r++) {
                const f = M[r][c] / M[c][c];
                if (f !== 0) for (let k = c; k <= n; k++) M[r][k] -= f * M[c][k];
            }
        }
        const x = new Array(n);
        for (let r = n - 1; r >= 0; r--) {
            let s = M[r][n];
            for (let k = r + 1; k < n; k++) s -= M[r][k] * x[k];
            x[r] = s / M[r][r];
        }
        return x;
    }

    function normalizer(points) {
        let mx = 0, my = 0;
        for (const p of points) { mx += p[0]; my += p[1]; }
        mx /= points.length; my /= points.length;
        let d = 0;
        for (const p of points) d += Math.hypot(p[0] - mx, p[1] - my);
        d /= points.length;
        const s = d > 1e-9 ? Math.SQRT2 / d : 1;
        return [s, 0, -s * mx, 0, s, -s * my, 0, 0, 1];
    }

    /** Least-squares homography mapping src[i] → dst[i] (n ≥ 4), with Hartley normalisation. */
    function homography(src, dst) {
        const Ts = normalizer(src), Td = normalizer(dst);
        const TdInv = inv3(Td);
        if (!TdInv) return null;
        const AtA = Array.from({ length: 8 }, () => new Array(8).fill(0));
        const Atb = new Array(8).fill(0);
        for (let i = 0; i < src.length; i++) {
            const [x, y] = project(Ts, src[i][0], src[i][1]);
            const [u, v] = project(Td, dst[i][0], dst[i][1]);
            const rows = [[[x, y, 1, 0, 0, 0, -u * x, -u * y], u], [[0, 0, 0, x, y, 1, -v * x, -v * y], v]];
            for (const [row, rhs] of rows) {
                for (let a = 0; a < 8; a++) {
                    Atb[a] += row[a] * rhs;
                    for (let c = 0; c < 8; c++) AtA[a][c] += row[a] * row[c];
                }
            }
        }
        const h = solveLinear(AtA, Atb);
        if (!h) return null;
        const H = mul3(TdInv, mul3([...h, 1], Ts));
        const s = H[8];
        return Math.abs(s) > 1e-12 ? H.map(v => v / s) : H;
    }

    function scaleHomography(H, factor) {
        return mul3([factor, 0, 0, 0, factor, 0, 0, 0, 1], H);
    }

    // ------------------------------------------------------ box detection

    function isRed(r, g, b) {
        return r > 70 && r - g > 38 && r - b > 28 && r > g * 1.45;
    }

    /**
     * White balance: per-channel gains that make the brightest paper neutral white,
     * so warm (orange/pink) or cool salon lighting doesn't turn paper "red".
     */
    function whiteBalance(img) {
        const d = img.data, n = img.width * img.height;
        const step = Math.max(1, Math.floor(n / 60000));
        const hist = [new Uint32Array(256), new Uint32Array(256), new Uint32Array(256)];
        let count = 0;
        for (let i = 0; i < n; i += step) {
            const p = i * 4;
            hist[0][d[p]]++; hist[1][d[p + 1]]++; hist[2][d[p + 2]]++;
            count++;
        }
        return hist.map(h => {
            let acc = 0, v = 255;
            for (; v > 0; v--) { acc += h[v]; if (acc >= count * 0.03) break; }
            return Math.min(1.8, Math.max(0.7, 235 / Math.max(v, 1)));
        });
    }

    /**
     * Finds candidate tick-box insides: blobs of non-red pixels completely enclosed
     * by red, shaped like the ticket's boxes (about 2.5 : 1), in any orientation.
     */
    function detectCandidates(img) {
        const W = img.width, Hh = img.height, data = img.data;
        const N = W * Hh;
        const [gr, gg, gb] = whiteBalance(img);
        const labels = new Int32Array(N);
        const parent = new Int32Array(Math.floor(N / 2) + 2);
        let next = 1;
        const find = (x) => {
            while (parent[x] !== x) { parent[x] = parent[parent[x]]; x = parent[x]; }
            return x;
        };

        // Pass 1: provisional labels for non-red pixels (4-connectivity).
        for (let y = 0; y < Hh; y++) {
            for (let x = 0; x < W; x++) {
                const i = y * W + x, p = i * 4;
                if (isRed(data[p] * gr, data[p + 1] * gg, data[p + 2] * gb)) continue;
                const left = x > 0 ? labels[i - 1] : 0;
                const up = y > 0 ? labels[i - W] : 0;
                if (left && up) {
                    const a = find(left), b = find(up);
                    labels[i] = a < b ? a : b;
                    if (a !== b) parent[a > b ? a : b] = a < b ? a : b;
                } else if (left || up) {
                    labels[i] = left || up;
                } else {
                    if (next >= parent.length) return [];
                    parent[next] = next;
                    labels[i] = next++;
                }
            }
        }

        // Pass 2: statistics per component.
        const stats = new Map();
        for (let y = 0; y < Hh; y++) {
            for (let x = 0; x < W; x++) {
                const i = y * W + x;
                if (!labels[i]) continue;
                const l = find(labels[i]);
                let s = stats.get(l);
                if (!s) {
                    s = { n: 0, sx: 0, sy: 0, sxx: 0, syy: 0, sxy: 0, lum: 0, border: false };
                    stats.set(l, s);
                }
                const p = i * 4;
                s.n++; s.sx += x; s.sy += y; s.sxx += x * x; s.syy += y * y; s.sxy += x * y;
                s.lum += 0.299 * data[p] * gr + 0.587 * data[p + 1] * gg + 0.114 * data[p + 2] * gb;
                if (x === 0 || y === 0 || x === W - 1 || y === Hh - 1) s.border = true;
            }
        }

        const maxArea = N * 0.004;
        const out = [];
        for (const s of stats.values()) {
            if (s.border || s.n < 20 || s.n > maxArea || s.lum / s.n < 120) continue;
            const cx = s.sx / s.n, cy = s.sy / s.n;
            const vxx = s.sxx / s.n - cx * cx, vyy = s.syy / s.n - cy * cy, vxy = s.sxy / s.n - cx * cy;
            const tr = vxx + vyy, det = vxx * vyy - vxy * vxy;
            const disc = Math.sqrt(Math.max(0, tr * tr / 4 - det));
            const l1 = tr / 2 + disc, l2 = tr / 2 - disc;
            if (l2 <= 0) continue;
            const len = Math.sqrt(12 * l1), wid = Math.sqrt(12 * l2);
            const aspect = len / wid, fill = s.n / (len * wid);
            if (wid < 3 || aspect < 1.5 || aspect > 4.5 || fill < 0.7 || fill > 1.3) continue;
            const angle = 0.5 * Math.atan2(2 * vxy, vxx - vyy); // direction of the long side
            out.push({ x: cx, y: cy, area: s.n, len, wid, angle });
        }
        return filterConsistent(out);
    }

    function median(values) {
        const v = values.slice().sort((a, b) => a - b);
        return v.length ? v[Math.floor(v.length / 2)] : 0;
    }

    /** Keeps candidates of a similar size and orientation (the real boxes agree; stray blobs don't). */
    function filterConsistent(cands) {
        if (cands.length < 8) return cands;
        const medArea = median(cands.map(c => c.area));
        let sized = cands.filter(c => c.area > medArea * 0.35 && c.area < medArea * 2.8);
        // Circular median of orientation (angles are defined modulo 180°).
        let sx = 0, sy = 0;
        for (const c of sized) { sx += Math.cos(2 * c.angle); sy += Math.sin(2 * c.angle); }
        const mean = 0.5 * Math.atan2(sy, sx);
        sized = sized.filter(c => {
            let d = Math.abs(c.angle - mean) % Math.PI;
            if (d > Math.PI / 2) d = Math.PI - d;
            return d < (25 * Math.PI) / 180;
        });
        return sized;
    }

    // ------------------------------------------------------ ticket finding

    function templateGeometry(tpl) {
        if (tpl._geo) return tpl._geo;
        const centers = tpl.boxes.map(b => [b.rect[0] + b.rect[2] / 2, b.rect[1] + b.rect[3] / 2]);
        const xs = centers.map(c => c[0]);
        const leftX = median(xs.filter(x => x < tpl.width / 2));
        const rightX = median(xs.filter(x => x >= tpl.width / 2));
        const colL = [], colR = [];
        centers.forEach((c, i) => {
            if (Math.abs(c[0] - leftX) < 8) colL.push(i);
            else if (Math.abs(c[0] - rightX) < 8) colR.push(i);
        });
        colL.sort((a, b) => centers[a][1] - centers[b][1]);
        colR.sort((a, b) => centers[a][1] - centers[b][1]);
        const boxW = median(tpl.boxes.map(b => b.rect[2]));
        const boxH = median(tpl.boxes.map(b => b.rect[3]));
        tpl._geo = { centers, colL, colR, boxW, boxH };
        return tpl._geo;
    }

    /** Finds the two longest straight rows of candidates (the ticket's two columns of boxes). */
    function findColumns(cands) {
        const medLen = median(cands.map(c => c.len));
        const medWid = median(cands.map(c => c.wid));
        let sx = 0, sy = 0;
        for (const c of cands) { sx += Math.cos(2 * c.angle); sy += Math.sin(2 * c.angle); }
        const boxAngle = 0.5 * Math.atan2(sy, sx);
        const ux = Math.cos(boxAngle), uy = Math.sin(boxAngle);
        const tol = Math.max(1.5, medWid * 0.55);

        const bestLine = (pool) => {
            let best = null;
            for (let i = 0; i < pool.length; i++) {
                for (let j = i + 1; j < pool.length; j++) {
                    const a = pool[i], b = pool[j];
                    const dx = b.x - a.x, dy = b.y - a.y, d = Math.hypot(dx, dy);
                    if (d < medLen * 1.5) continue;
                    const nx = dx / d, ny = dy / d;
                    if (Math.abs(nx * ux + ny * uy) > 0.5) continue; // columns run across the boxes
                    const inl = [];
                    for (const c of pool) {
                        const dist = Math.abs((c.x - a.x) * ny - (c.y - a.y) * nx);
                        if (dist < tol) inl.push(c);
                    }
                    if (!best || inl.length > best.inliers.length) best = { inliers: inl, nx, ny };
                }
            }
            return best;
        };

        const first = bestLine(cands);
        if (!first || first.inliers.length < 5) return null;
        const rest = cands.filter(c => !first.inliers.includes(c));
        const second = bestLine(rest);
        if (!second || second.inliers.length < 5) return null;
        return [first, second].map(line => {
            const pts = line.inliers.slice().sort((p, q) => (p.x * line.nx + p.y * line.ny) - (q.x * line.nx + q.y * line.ny));
            return pts;
        });
    }

    function scoreHomography(H, geo, cands, tol2) {
        let matched = 0, err = 0;
        const pairs = [];
        for (let t = 0; t < geo.centers.length; t++) {
            const [px, py] = project(H, geo.centers[t][0], geo.centers[t][1]);
            let best = -1, bestD = tol2;
            for (let k = 0; k < cands.length; k++) {
                const dx = cands[k].x - px, dy = cands[k].y - py, d2 = dx * dx + dy * dy;
                if (d2 < bestD) { bestD = d2; best = k; }
            }
            if (best >= 0) {
                matched++;
                err += bestD;
                pairs.push([t, best]);
            }
        }
        return { matched, err, pairs };
    }

    function plausible(H, tpl, img) {
        // Ticket must not be mirrored and must land inside a sensible area of the photo.
        const corners = [[0, 0], [tpl.width, 0], [tpl.width, tpl.height], [0, tpl.height]].map(p => project(H, p[0], p[1]));
        let area = 0, sign = 0;
        for (let i = 0; i < 4; i++) {
            const a = corners[i], b = corners[(i + 1) % 4], c = corners[(i + 2) % 4];
            const cross = (b[0] - a[0]) * (c[1] - b[1]) - (b[1] - a[1]) * (c[0] - b[0]);
            const s = Math.sign(cross);
            if (sign === 0) sign = s;
            else if (s !== sign) return false; // not convex
            area += a[0] * b[1] - b[0] * a[1];
        }
        area /= 2;
        const imgArea = img.width * img.height;
        return area > 0 && area > imgArea * 0.08 && area < imgArea * 4;
    }

    /**
     * Checks a fit against the photo itself: every box must be inside the picture,
     * and a red frame must really surround (nearly) every box position.
     */
    function verifyFit(img, H, tpl) {
        const [gr, gg, gb] = whiteBalance(img);
        const W = img.width, Hh = img.height, d = img.data;
        let framed = 0, outside = 0;
        for (const box of tpl.boxes) {
            const [x, y, w, h] = box.rect;
            const pts = [];
            for (let k = 0; k <= 10; k++) {
                pts.push([x + (w * k) / 10, y - 4], [x + (w * k) / 10, y + h + 4]);
            }
            for (let k = 1; k < 4; k++) {
                pts.push([x - 4, y + (h * k) / 4], [x + w + 4, y + (h * k) / 4]);
            }
            let red = 0, out = false;
            for (const [tx, ty] of pts) {
                const [ix, iy] = project(H, tx, ty);
                const px = Math.round(ix), py = Math.round(iy);
                if (px < 0 || py < 0 || px >= W || py >= Hh) { out = true; continue; }
                const i = (py * W + px) * 4;
                if (isRed(d[i] * gr, d[i + 1] * gg, d[i + 2] * gb)) red++;
            }
            if (out) outside++;
            if (red >= pts.length * 0.5) framed++;
        }
        return { framed, outside, total: tpl.boxes.length };
    }

    /**
     * Locates the ticket. Returns { H, matched, total, rms } where H maps
     * template pixels to image pixels, or null if the ticket wasn't found.
     */
    function findTicket(img, tpl) {
        const geo = templateGeometry(tpl);
        const cands = detectCandidates(img);
        if (cands.length < 10) return { found: false, reason: 'few-boxes', candidates: cands.length };
        const cols = findColumns(cands);
        if (!cols) return { found: false, reason: 'no-columns', candidates: cands.length };

        const medLen = median(cands.map(c => c.len));
        // Rows of boxes are less than two box-heights apart, so match within ~half a box height:
        // a fit that is one row off then scores badly instead of almost as well as the right one.
        const tol = Math.max(2, median(cands.map(c => c.wid)) * 0.6);
        const tol2 = tol * tol;
        const K = 4;
        const hypotheses = [];

        for (const [seqX, seqY] of [[geo.colL, geo.colR], [geo.colR, geo.colL]]) {
            for (const flipA of [false, true]) {
                const A = flipA ? cols[0].slice().reverse() : cols[0];
                if (A.length > seqX.length) continue;
                for (const flipB of [false, true]) {
                    const B = flipB ? cols[1].slice().reverse() : cols[1];
                    if (B.length > seqY.length) continue;
                    for (let a1 = 0; a1 < K; a1++) {
                        for (let a2 = 0; a2 < K; a2++) {
                            if (a1 + a2 > seqX.length - A.length) continue;
                            for (let b1 = 0; b1 < K; b1++) {
                                for (let b2 = 0; b2 < K; b2++) {
                                    if (b1 + b2 > seqY.length - B.length) continue;
                                    const src = [
                                        geo.centers[seqX[a1]], geo.centers[seqX[seqX.length - 1 - a2]],
                                        geo.centers[seqY[b1]], geo.centers[seqY[seqY.length - 1 - b2]],
                                    ];
                                    const dst = [
                                        [A[0].x, A[0].y], [A[A.length - 1].x, A[A.length - 1].y],
                                        [B[0].x, B[0].y], [B[B.length - 1].x, B[B.length - 1].y],
                                    ];
                                    const H = homography(src, dst);
                                    if (!H || !plausible(H, tpl, img)) continue;
                                    const s = scoreHomography(H, geo, cands, tol2);
                                    if (s.matched >= 12) hypotheses.push({ H, ...s });
                                }
                            }
                        }
                    }
                }
            }
        }
        if (!hypotheses.length) return { found: false, reason: 'no-fit', candidates: cands.length };
        hypotheses.sort((a, b) => b.matched - a.matched || a.err - b.err);

        let best = null;
        const tried = [];
        for (const hyp of hypotheses) {
            if (tried.length >= 6) break;
            // Skip near-duplicates of a fit already tried.
            const c = project(hyp.H, tpl.width / 2, tpl.height / 2);
            if (tried.some(t => Math.hypot(t[0] - c[0], t[1] - c[1]) < tol && t[2] === hyp.matched)) continue;
            tried.push([c[0], c[1], hyp.matched]);

            // Refine with every matched box, twice.
            let H = hyp.H, fit = hyp;
            for (let round = 0; round < 2; round++) {
                const src = fit.pairs.map(([t]) => geo.centers[t]);
                const dst = fit.pairs.map(([, k]) => [cands[k].x, cands[k].y]);
                const refined = homography(src, dst);
                if (!refined) break;
                const s = scoreHomography(refined, geo, cands, tol2);
                if (s.matched < fit.matched) break;
                H = refined;
                fit = s;
            }
            const check = verifyFit(img, H, tpl);
            const candidate = { H, fit, check };
            if (!best || check.framed > best.check.framed || (check.framed === best.check.framed && fit.matched > best.fit.matched)) {
                best = candidate;
            }
        }

        const { H, fit, check } = best;
        const rms = Math.sqrt(fit.err / Math.max(1, fit.matched));
        let reason = 'ok';
        if (check.outside > 0) reason = 'cut-off';
        else if (check.framed < check.total * 0.85) reason = 'weak-fit';
        return {
            found: reason === 'ok',
            reason,
            H,
            matched: fit.matched,
            framed: check.framed,
            outside: check.outside,
            total: geo.centers.length,
            rms,
            candidates: cands.length,
            boxLengthPx: medLen,
        };
    }

    // ------------------------------------------------------- tick reading

    function sampler(img) {
        const W = img.width, Hh = img.height, d = img.data;
        return (x, y) => {
            x = Math.min(Math.max(x, 0), W - 1.001);
            y = Math.min(Math.max(y, 0), Hh - 1.001);
            const x0 = x | 0, y0 = y | 0, fx = x - x0, fy = y - y0;
            const i00 = (y0 * W + x0) * 4, i10 = i00 + 4, i01 = i00 + W * 4, i11 = i01 + 4;
            const out = [0, 0, 0];
            for (let c = 0; c < 3; c++) {
                const top = d[i00 + c] * (1 - fx) + d[i10 + c] * fx;
                const bottom = d[i01 + c] * (1 - fx) + d[i11 + c] * fx;
                out[c] = top * (1 - fy) + bottom * fy;
            }
            return out;
        };
    }

    const lum = (p) => 0.299 * p[0] + 0.587 * p[1] + 0.114 * p[2];

    function percentile(values, q) {
        const v = values.slice().sort((a, b) => a - b);
        return v[Math.min(v.length - 1, Math.floor(q * v.length))];
    }

    /**
     * Measures pen ink inside every box. Returns one entry per template box:
     * { code, ratio, ticked, confidence, uncertain }.
     */
    function readTicks(img, H, tpl, opts) {
        const o = Object.assign({ threshold: 0.022, lowBand: 0.01, highBand: 0.06 }, opts || {});
        const gains = whiteBalance(img);
        const raw = sampler(img);
        const sample = (x, y) => {
            const p = raw(x, y);
            return [p[0] * gains[0], p[1] * gains[1], p[2] * gains[2]];
        };
        const cols = 26, rows = 12;
        const perBox = tpl.boxes.map((box) => {
            const [bx, by, bw, bh] = box.rect;
            const inset = { x: bx + bw * 0.16, y: by + bh * 0.22, w: bw * 0.68, h: bh * 0.56 };
            const pixels = [];
            for (let r = 0; r < rows; r++) {
                for (let c = 0; c < cols; c++) {
                    const tx = inset.x + ((c + 0.5) / cols) * inset.w;
                    const ty = inset.y + ((r + 0.5) / rows) * inset.h;
                    const [ix, iy] = project(H, tx, ty);
                    pixels.push(sample(ix, iy));
                }
            }
            const lums = pixels.map(lum);
            return { box, pixels, lums, white: percentile(lums, 0.85) };
        });

        // Paper brightness: a box's own brightest pixels, but never much darker than its neighbours'
        // (so a fully scribbled box still counts as ink).
        const centers = tpl.boxes.map(b => [b.rect[0] + b.rect[2] / 2, b.rect[1] + b.rect[3] / 2]);
        return perBox.map((pb, i) => {
            const neighbours = perBox
                .map((q, j) => ({ j, d: Math.hypot(centers[j][0] - centers[i][0], centers[j][1] - centers[i][1]) }))
                .filter(n => n.j !== i)
                .sort((a, b) => a.d - b.d)
                .slice(0, 6)
                .map(n => perBox[n.j].white);
            const white = Math.max(pb.white, median(neighbours) * 0.92, 30);
            let ink = 0;
            for (let k = 0; k < pb.pixels.length; k++) {
                const [r, g, b] = pb.pixels[k];
                const l = pb.lums[k];
                const dark = l < white * 0.68;
                const blueInk = b - r > 30 && b - g > 10 && l < white * 0.9;
                if (dark || blueInk) ink++;
            }
            const ratio = ink / pb.pixels.length;
            const ticked = ratio >= o.threshold;
            const uncertain = ratio >= o.lowBand && ratio < o.highBand;
            const margin = ticked ? (ratio - o.threshold) / (o.highBand - o.threshold) : (o.threshold - ratio) / (o.threshold - o.lowBand);
            return {
                code: pb.box.code,
                ratio: Math.round(ratio * 1000) / 1000,
                ticked,
                uncertain,
                confidence: Math.round(Math.min(1, Math.max(0.5, 0.5 + margin / 2)) * 100) / 100,
                paper: Math.round(white),
            };
        });
    }

    // ------------------------------------------------------------ cropping

    /** Straightened copy of a template rectangle, as ImageData-like {width, height, data}. */
    function rectify(img, H, rect, outWidth) {
        const [rx, ry, rw, rh] = rect;
        const scale = outWidth / rw;
        const outW = Math.round(rw * scale), outH = Math.round(rh * scale);
        const out = new Uint8ClampedArray(outW * outH * 4);
        const sample = sampler(img);
        for (let v = 0; v < outH; v++) {
            for (let u = 0; u < outW; u++) {
                const [ix, iy] = project(H, rx + (u + 0.5) / scale, ry + (v + 0.5) / scale);
                const p = sample(ix, iy);
                const o = (v * outW + u) * 4;
                out[o] = p[0]; out[o + 1] = p[1]; out[o + 2] = p[2]; out[o + 3] = 255;
            }
        }
        return { width: outW, height: outH, data: out };
    }

    /** Outline of every box in image coordinates (for drawing the live overlay). */
    function boxOutlines(H, tpl) {
        return tpl.boxes.map(b => {
            const [x, y, w, h] = b.rect;
            return [[x, y], [x + w, y], [x + w, y + h], [x, y + h]].map(p => project(H, p[0], p[1]));
        });
    }

    function ticketOutline(H, tpl) {
        return [[0, 0], [tpl.width, 0], [tpl.width, tpl.height], [0, tpl.height]].map(p => project(H, p[0], p[1]));
    }

    root.TicketRecognizer = {
        detectCandidates,
        findTicket,
        readTicks,
        rectify,
        boxOutlines,
        ticketOutline,
        scaleHomography,
        homography,
        project,
    };
})(typeof window !== 'undefined' ? window : globalThis);
