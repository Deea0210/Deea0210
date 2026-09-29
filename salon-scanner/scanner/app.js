/* Ticket Scanner: camera → recognise ticks → review → send to the salon software. */
(() => {
    'use strict';

    const T = window.TICKET_TEMPLATE;
    const R = window.TicketRecognizer;
    const CFG = window.SCANNER_CONFIG || {};
    const $ = (id) => document.getElementById(id);

    const PREVIEW_SIDE = 960;   // resolution used while looking for the ticket
    const DETECT_SIDE = 1600;   // resolution used to line up the captured photo
    const MAX_PHOTO_SIDE = 4000;

    // ------------------------------------------------------------ settings

    const SETTINGS_KEY = 'ticketScanner.settings';
    const absolute = (url) => { try { return url ? new URL(url, location.href).href : ''; } catch (e) { return url; } };
    const defaults = { apiUrl: absolute(CFG.apiUrl), apiKey: '', device: CFG.deviceName || 'Reception phone' };
    function loadSettings() {
        try {
            return Object.assign({}, defaults, JSON.parse(localStorage.getItem(SETTINGS_KEY) || '{}'));
        } catch (e) {
            return Object.assign({}, defaults);
        }
    }
    function saveSettings(value) {
        try {
            localStorage.setItem(SETTINGS_KEY, JSON.stringify(value));
        } catch (e) {
            toast('Could not save settings on this phone');
        }
    }
    let settings = loadSettings();
    const isConfigured = () => Boolean(settings.apiUrl && settings.apiKey);

    // --------------------------------------------------------------- staff
    // Stylists write their number in the "C" box (C281 = Renato). The list comes from
    // the salon software (GET ?staff=1) and is kept on the phone for when it's offline.

    const STAFF_KEY = 'ticketScanner.staff';
    let staff = [];
    try {
        const cached = JSON.parse(localStorage.getItem(STAFF_KEY) || '[]');
        if (Array.isArray(cached)) staff = cached;
    } catch (e) { /* no cached list yet */ }

    /** "281", "c 281" and "C281" all become "C281"; the same rule runs on the server. */
    function normaliseStaffCode(value) {
        const code = String(value || '').toUpperCase().replace(/\s+/g, '');
        return /^\d+$/.test(code) ? 'C' + code : code;
    }
    const validStaffCode = (code) => /^[A-Z0-9-]{1,20}$/.test(code);
    function findStaff(value) {
        const code = normaliseStaffCode(value);
        return code ? staff.find(s => normaliseStaffCode(s.code) === code) || null : null;
    }

    /** Loads the staff list; returns how many stylists came back, or null if it couldn't. */
    async function refreshStaff(s) {
        s = s || settings;
        if (!s.apiUrl || !s.apiKey) return null;
        try {
            const url = s.apiUrl + (s.apiUrl.includes('?') ? '&' : '?') + 'staff=1';
            const res = await fetch(url, { headers: { Authorization: 'Bearer ' + s.apiKey }, cache: 'no-store' });
            if (!res.ok) return null; // keep the list we have
            const body = await res.json();
            if (!Array.isArray(body.staff)) return null;
            staff = body.staff
                .filter(x => x && x.code)
                .map(x => ({ code: String(x.code).trim(), name: String(x.name || '').trim() }));
            try { localStorage.setItem(STAFF_KEY, JSON.stringify(staff)); } catch (e) { /* kept in memory */ }
            return staff.length;
        } catch (e) {
            return null; // offline: keep the cached list
        }
    }

    // ------------------------------------------------------------- helpers

    function show(id) {
        for (const s of document.querySelectorAll('.screen')) s.hidden = s.id !== id;
        if (id === 'review') $('toast').hidden = true; // don't cover the ticket being checked
        if (id === 'camera') startCamera(); else stopCamera();
    }

    let toastTimer = 0;
    function toast(message, ms) {
        const el = $('toast');
        el.textContent = message;
        el.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { el.hidden = true; }, ms || 3000);
    }

    function hint(text, tone) {
        const el = $('hint');
        if (el.textContent !== text) el.textContent = text;
        el.className = 'hint' + (tone ? ' hint--' + tone : '');
    }

    function uuid() {
        if (crypto.randomUUID) return crypto.randomUUID();
        const b = crypto.getRandomValues(new Uint8Array(16));
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        const h = [...b].map(x => x.toString(16).padStart(2, '0')).join('');
        return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
    }

    function isoWithOffset(date) {
        const pad = (n) => String(Math.abs(n)).padStart(2, '0');
        const off = -date.getTimezoneOffset();
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
            + `${off >= 0 ? '+' : '-'}${pad(Math.trunc(off / 60))}:${pad(off % 60)}`;
    }

    const money = (v) => '£' + v.toFixed(2);

    function el(tag, attrs, ...children) {
        const node = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs || {})) {
            if (k === 'class') node.className = v;
            else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
            else if (v !== null && v !== undefined && v !== false) node.setAttribute(k, v === true ? '' : v);
        }
        for (const c of children) if (c !== null && c !== undefined) node.append(c);
        return node;
    }

    function toCanvas(imageData) {
        const c = document.createElement('canvas');
        c.width = imageData.width;
        c.height = imageData.height;
        c.getContext('2d').putImageData(new ImageData(imageData.data, imageData.width, imageData.height), 0, 0);
        return c;
    }

    function scaledImageData(source, width, height, maxSide) {
        const s = Math.min(1, maxSide / Math.max(width, height));
        const c = document.createElement('canvas');
        c.width = Math.round(width * s);
        c.height = Math.round(height * s);
        const ctx = c.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(source, 0, 0, c.width, c.height);
        return { data: ctx.getImageData(0, 0, c.width, c.height), scale: s, canvas: c };
    }

    // -------------------------------------------------------------- outbox
    // Tickets are kept on the phone (IndexedDB) until the salon software confirms them.

    const outbox = (() => {
        let memory = [];
        const dbp = new Promise((resolve) => {
            try {
                const req = indexedDB.open('ticket-scanner', 1);
                req.onupgradeneeded = () => req.result.createObjectStore('outbox', { keyPath: 'scanId' });
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => resolve(null);
            } catch (e) {
                resolve(null);
            }
        });
        const tx = async (mode, fn) => {
            const db = await dbp;
            if (!db) return fn(null);
            return new Promise((resolve, reject) => {
                const t = db.transaction('outbox', mode);
                const result = fn(t.objectStore('outbox'));
                t.oncomplete = () => resolve(result && result.result !== undefined ? result.result : result);
                t.onerror = () => reject(t.error);
            });
        };
        return {
            add: (item) => tx('readwrite', (s) => (s ? s.put(item) : memory.push(item))),
            all: () => tx('readonly', (s) => (s ? s.getAll() : memory.slice())),
            remove: (id) => tx('readwrite', (s) => (s ? s.delete(id) : (memory = memory.filter(i => i.scanId !== id)))),
        };
    })();

    let flushing = false;
    async function flushOutbox() {
        if (flushing || !isConfigured()) return updateOutboxChip();
        flushing = true;
        let lastError = '';
        try {
            const items = await outbox.all();
            for (const item of items) {
                let response;
                try {
                    response = await fetch(settings.apiUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + settings.apiKey },
                        body: JSON.stringify(item),
                    });
                } catch (e) {
                    lastError = 'offline';
                    break; // no connection: try again later
                }
                if (response.ok) {
                    await outbox.remove(item.scanId);
                } else if (response.status === 401 || response.status === 403) {
                    lastError = 'key';
                    break;
                } else {
                    lastError = 'server';
                    break;
                }
            }
        } finally {
            flushing = false;
            await updateOutboxChip(lastError);
        }
        return lastError;
    }

    async function updateOutboxChip(error) {
        const items = await outbox.all();
        const chip = $('outbox-chip');
        chip.hidden = items.length === 0;
        chip.className = 'chip';
        chip.textContent = items.length === 1 ? '1 waiting to send' : `${items.length} waiting to send`;
        if (error === 'key') chip.textContent = 'Check scanner key';
    }

    setInterval(flushOutbox, 20000);
    window.addEventListener('online', flushOutbox);

    // -------------------------------------------------------------- camera

    const video = $('video');
    const overlay = $('overlay');
    let stream = null;
    let track = null;
    let loopTimer = 0;
    let stable = 0;
    let lastCenter = null;
    let capturing = false;
    let wakeLock = null;

    async function startCamera() {
        if (stream) return scheduleLoop();
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            hint('Live camera needs a secure (https) page. Use "Choose photo".', 'warn');
            return;
        }
        hint('Starting camera…');
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 3840 }, height: { ideal: 2160 } },
            });
        } catch (e) {
            hint('Allow camera access, or use "Choose photo".', 'warn');
            return;
        }
        video.srcObject = stream;
        try { await video.play(); } catch (e) { /* autoplay is allowed for muted video */ }
        track = stream.getVideoTracks()[0];
        const caps = track.getCapabilities ? track.getCapabilities() : {};
        $('torch-btn').hidden = !caps.torch;
        try { await track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }); } catch (e) { /* optional */ }
        try { wakeLock = await navigator.wakeLock.request('screen'); } catch (e) { /* optional */ }
        stable = 0;
        lastCenter = null;
        scheduleLoop();
    }

    function stopCamera() {
        clearTimeout(loopTimer);
        if (stream) {
            stream.getTracks().forEach(t => t.stop());
            stream = null;
            track = null;
        }
        if (wakeLock) {
            wakeLock.release().catch(() => {});
            wakeLock = null;
        }
        clearOverlay();
    }

    function scheduleLoop() {
        clearTimeout(loopTimer);
        loopTimer = setTimeout(scanFrame, 180);
    }

    function scanFrame() {
        if ($('camera').hidden || !stream || capturing) return;
        if (video.readyState < 2 || !video.videoWidth) return scheduleLoop();

        const work = scaledImageData(video, video.videoWidth, video.videoHeight, PREVIEW_SIDE);
        let result;
        try {
            result = R.findTicket(work.data, T);
        } catch (e) {
            result = { found: false, reason: 'error' };
        }
        drawOverlay(result, work.scale);

        if (result.found) {
            const c = R.project(result.H, T.width / 2, T.height / 2);
            const moved = lastCenter ? Math.hypot(c[0] - lastCenter[0], c[1] - lastCenter[1]) / work.data.width : 1;
            stable = moved < 0.012 ? stable + 1 : 1;
            lastCenter = c;
            $('shutter').classList.add('shutter--ready');
            if (stable >= 3) {
                hint('Got it!', 'ok');
                captureFromVideo();
                return;
            }
            hint('Hold still…', 'ok');
        } else {
            stable = 0;
            lastCenter = null;
            $('shutter').classList.remove('shutter--ready');
            const messages = {
                'cut-off': 'Move back a little so the whole ticket fits',
                'weak-fit': 'Hold the phone steady above the ticket',
                'no-fit': 'Hold the phone steady above the ticket',
            };
            hint(messages[result.reason] || 'Point the camera at a ticket');
        }
        scheduleLoop();
    }

    function clearOverlay() {
        const ctx = overlay.getContext('2d');
        ctx.clearRect(0, 0, overlay.width, overlay.height);
    }

    /** Draws the found ticket and its boxes over the live video (object-fit: cover). */
    function drawOverlay(result, workScale) {
        const dpr = window.devicePixelRatio || 1;
        const cw = overlay.clientWidth, ch = overlay.clientHeight;
        if (overlay.width !== Math.round(cw * dpr)) overlay.width = Math.round(cw * dpr);
        if (overlay.height !== Math.round(ch * dpr)) overlay.height = Math.round(ch * dpr);
        const ctx = overlay.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, cw, ch);
        if (!result.H || !result.found) return;

        const vw = video.videoWidth, vh = video.videoHeight;
        const k = Math.max(cw / vw, ch / vh);
        const ox = (cw - vw * k) / 2, oy = (ch - vh * k) / 2;
        const toScreen = (p) => [ox + (p[0] / workScale) * k, oy + (p[1] / workScale) * k];

        ctx.lineWidth = 3;
        ctx.strokeStyle = '#4ade80';
        ctx.beginPath();
        R.ticketOutline(result.H, T).map(toScreen).forEach((p, i) => (i ? ctx.lineTo(p[0], p[1]) : ctx.moveTo(p[0], p[1])));
        ctx.closePath();
        ctx.stroke();
        ctx.lineWidth = 1.5;
        for (const outline of R.boxOutlines(result.H, T)) {
            ctx.beginPath();
            outline.map(toScreen).forEach((p, i) => (i ? ctx.lineTo(p[0], p[1]) : ctx.moveTo(p[0], p[1])));
            ctx.closePath();
            ctx.stroke();
        }
    }

    async function captureFromVideo() {
        if (capturing || !stream) return;
        capturing = true;
        const vw = video.videoWidth, vh = video.videoHeight;
        const full = scaledImageData(video, vw, vh, MAX_PHOTO_SIDE);
        show('busy');
        setTimeout(() => processPhoto(full.canvas), 30);
    }

    $('shutter').addEventListener('click', () => {
        if (stream && video.videoWidth) captureFromVideo();
        else $('file-input').click();
    });

    $('file-input').addEventListener('change', async (event) => {
        const file = event.target.files && event.target.files[0];
        event.target.value = '';
        if (!file) return;
        show('busy');
        try {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.src = url;
            await img.decode();
            URL.revokeObjectURL(url);
            const full = scaledImageData(img, img.naturalWidth, img.naturalHeight, MAX_PHOTO_SIDE);
            setTimeout(() => processPhoto(full.canvas), 30);
        } catch (e) {
            toast('Could not open that photo');
            show('camera');
        }
    });

    $('torch-btn').addEventListener('click', async () => {
        const btn = $('torch-btn');
        const on = btn.getAttribute('aria-pressed') !== 'true';
        try {
            await track.applyConstraints({ advanced: [{ torch: on }] });
            btn.setAttribute('aria-pressed', String(on));
        } catch (e) {
            toast('Torch not available');
        }
    });

    // ---------------------------------------------------------- recognise

    let scan = null; // the ticket being reviewed

    function processPhoto(canvas) {
        capturing = false;
        try {
            readPhoto(canvas);
        } catch (e) {
            console.error(e);
            toast('Something went wrong reading that photo. Please try again.', 4500);
            show('camera');
        }
    }

    function readPhoto(canvas) {
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        const full = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const work = scaledImageData(canvas, canvas.width, canvas.height, DETECT_SIDE);
        const found = R.findTicket(work.data, T);
        if (!found.found) {
            const why = {
                'cut-off': 'Part of the ticket was outside the photo. Move back a little and try again.',
            }[found.reason] || 'Couldn\'t find the ticket in that photo. Try again with the whole ticket in view.';
            toast(why, 4500);
            show('camera');
            return;
        }
        const H = R.scaleHomography(found.H, 1 / work.scale);
        const ticks = R.readTicks(full, H, T);

        const ticketImage = toCanvas(R.rectify(full, H, [0, 0, T.width, T.height], 1200));
        const fieldImages = {};
        for (const f of T.fields) {
            fieldImages[f.key] = toCanvas(R.rectify(full, H, f.rect, Math.min(700, Math.round(f.rect[2] * 1.3)))).toDataURL('image/jpeg', 0.85);
        }

        const detected = {};
        const selected = {};
        for (const t of ticks) {
            detected[t.code] = t;
            selected[t.code] = t.ticked;
        }
        const serviceCount = T.boxes.filter(b => b.price !== null && selected[b.code]).length;
        scan = {
            scanId: uuid(),
            scannedAt: new Date(),
            detected,
            selected,
            fields: { staff: '', treatments: serviceCount ? String(serviceCount) : '', tips: '' },
            fieldImages,
            photo: ticketImage.toDataURL('image/jpeg', 0.8),
            ticketCanvas: ticketImage,
            showAll: false,
        };
        renderReview();
        show('review');
    }

    // ------------------------------------------------------------- review

    function markedTicketImage() {
        const src = scan.ticketCanvas;
        const c = document.createElement('canvas');
        c.width = src.width;
        c.height = src.height;
        const ctx = c.getContext('2d');
        ctx.drawImage(src, 0, 0);
        const k = src.width / T.width;
        ctx.lineWidth = 4;
        for (const b of T.boxes) {
            const d = scan.detected[b.code];
            if (!scan.selected[b.code] && !d.uncertain) continue;
            ctx.strokeStyle = d.uncertain ? '#f59e0b' : '#16a34a';
            const [x, y, w, h] = b.rect;
            ctx.strokeRect((x - 8) * k, (y - 8) * k, (w + 16) * k, (h + 16) * k);
        }
        return c.toDataURL('image/jpeg', 0.85);
    }

    function serviceRow(box) {
        const d = scan.detected[box.code];
        const on = Boolean(scan.selected[box.code]);
        const check = d.uncertain && !scan.touched?.[box.code];
        const meta = check ? `${box.section} · Please check` : box.section;
        const toggle = el('button', {
            class: 'toggle', type: 'button', role: 'switch', 'aria-checked': String(on), 'aria-label': box.name,
            onclick: () => {
                scan.selected[box.code] = !scan.selected[box.code];
                scan.touched = Object.assign(scan.touched || {}, { [box.code]: true });
                renderReview();
            },
        });
        return el('li', { class: 'service-row' + (check ? ' service-row--check' : '') },
            el('span', { class: 'service-row__text' },
                el('span', { class: 'service-row__name' }, box.name),
                el('span', { class: 'service-row__meta' }, meta)),
            el('span', { class: 'service-row__price' }, box.price === null ? 'Discount' : money(box.price)),
            toggle);
    }

    function renderReview() {
        $('ticket-img').src = markedTicketImage();

        const list = $('ticked-list');
        list.replaceChildren(...T.boxes
            .filter(b => scan.selected[b.code] || scan.detected[b.code].uncertain)
            .map(serviceRow));

        const services = T.boxes.filter(b => scan.selected[b.code] && b.price !== null);
        $('ticked-count').textContent = services.length ? `(${services.length})` : '';
        $('total').textContent = money(services.reduce((sum, b) => sum + b.price, 0));

        const uncertain = T.boxes.filter(b => scan.detected[b.code].uncertain && !(scan.touched || {})[b.code]);
        const discounts = T.boxes.filter(b => scan.selected[b.code] && b.price === null).map(b => b.name);
        const notice = $('review-notice');
        const messages = [];
        if (uncertain.length) messages.push(`Please check the highlighted service${uncertain.length > 1 ? 's' : ''}: the tick is faint or outside the box.`);
        if (discounts.length) messages.push(`Discount ticked: ${discounts.join(', ')}.`);
        notice.hidden = messages.length === 0;
        notice.textContent = messages.join(' ');

        const all = $('all-services');
        all.hidden = !scan.showAll;
        if (scan.showAll) {
            const sections = [...new Set(T.boxes.map(b => b.section))];
            all.replaceChildren(...sections.flatMap(section => [
                el('h3', {}, section),
                el('ul', { class: 'service-list' }, ...T.boxes.filter(b => b.section === section).map(serviceRow)),
            ]));
        }
        $('add-service-btn').textContent = scan.showAll ? 'Hide full list' : '+ Add or remove a service';

        const fields = $('fields');
        fields.replaceChildren(...T.fields.map(f => (f.key === 'staff' ? staffCard(f) : el('div', { class: 'field-card' },
            el('img', { src: scan.fieldImages[f.key], alt: `Handwriting in the ${f.label} box` }),
            el('label', {},
                f.label,
                el('input', {
                    type: 'text',
                    inputmode: 'decimal',
                    value: scan.fields[f.key] || '',
                    placeholder: f.key === 'tips' ? '£0.00' : '',
                    oninput: (e) => { scan.fields[f.key] = e.target.value; scan.fieldsEdited = true; },
                }))))));
        updateStaffStatus();
    }

    /** The "C" box: the stylist's handwritten number, a button per stylist, and a box to type it. */
    function staffCard(f) {
        const input = el('input', {
            id: 'staff-input', type: 'text', inputmode: 'numeric', autocomplete: 'off', maxlength: '20',
            placeholder: 'e.g. 281', value: scan.fields.staff || '',
            oninput: (e) => { scan.fields.staff = e.target.value; updateStaffStatus(); },
        });
        const chips = staff.map(s => el('button', {
            class: 'staff-chip', type: 'button', 'data-code': s.code,
            onclick: () => {
                scan.fields.staff = s.code;
                input.value = s.code;
                updateStaffStatus();
            },
        }, el('b', {}, s.code), ' ', s.name));
        return el('div', { class: 'field-card field-card--staff', id: 'staff-card' },
            el('img', { src: scan.fieldImages[f.key], alt: 'Handwriting in the C box (stylist number)' }),
            el('span', { class: 'field-card__title' }, 'Stylist', el('span', { class: 'muted' }, ' · C number')),
            chips.length ? el('div', { class: 'staff-chips' }, ...chips) : null,
            el('label', {}, chips.length ? 'Not in the list? Type the number' : 'C number', input),
            el('p', { class: 'staff-status', id: 'staff-status', 'aria-live': 'polite' }));
    }

    function updateStaffStatus() {
        const status = $('staff-status');
        if (!status || !scan) return;
        const typed = String(scan.fields.staff || '').trim();
        const match = findStaff(typed);
        for (const chip of document.querySelectorAll('.staff-chip')) {
            chip.setAttribute('aria-pressed', String(Boolean(match) && chip.dataset.code === match.code));
        }
        let text = '', tone = '';
        if (match) {
            text = `✓ ${match.name || match.code}`;
            tone = 'ok';
        } else if (typed && !validStaffCode(normaliseStaffCode(typed))) {
            text = 'Use only the number, e.g. 281';
            tone = 'warn';
        } else if (typed && staff.length) {
            text = `${normaliseStaffCode(typed)} isn't in the staff list. Check the number.`;
            tone = 'warn';
        } else if (!typed && scan.askedStaff) {
            text = 'Choose the stylist, or tap Send again to send without one.';
            tone = 'warn';
        }
        status.textContent = text;
        status.className = 'staff-status' + (tone ? ' staff-status--' + tone : '');
        $('staff-card').classList.toggle('field-card--check', tone === 'warn');
    }

    $('add-service-btn').addEventListener('click', () => {
        scan.showAll = !scan.showAll;
        renderReview();
    });

    $('retake-btn').addEventListener('click', () => show('camera'));

    function buildPayload() {
        const services = T.boxes.map(b => {
            const d = scan.detected[b.code];
            return {
                code: b.code,
                name: b.name,
                section: b.section,
                price: b.price,
                ticked: Boolean(scan.selected[b.code]),
                confidence: d.confidence,
                changedByStaff: Boolean(scan.selected[b.code]) !== d.ticked,
            };
        });
        const chosen = services.filter(s => s.ticked && s.price !== null);
        const typed = String(scan.fields.staff || '').trim();
        const match = findStaff(typed);
        const stylist = match ? { code: match.code, name: match.name }
            : typed ? { code: normaliseStaffCode(typed), name: '' } : null;
        return {
            scanId: scan.scanId,
            template: T.id,
            scannedAt: isoWithOffset(scan.scannedAt),
            device: settings.device || 'Reception phone',
            services,
            fields: Object.assign(
                Object.fromEntries(Object.entries(scan.fields).map(([k, v]) => [k, String(v).trim()])),
                { staff: stylist ? stylist.code : '' }),
            staff: stylist,
            fieldImages: scan.fieldImages,
            correctedByStaff: services.some(s => s.changedByStaff),
            totals: { services: chosen.length, amount: Math.round(chosen.reduce((s, x) => s + x.price, 0) * 100) / 100, currency: T.currency },
            photo: scan.photo,
        };
    }

    $('send-btn').addEventListener('click', async () => {
        if (!isConfigured()) {
            // Trying it out before the salon software is connected: nothing to send to.
            toast('Test only, nothing was sent. Add your salon software in Settings to send tickets.', 4500);
            scan = null;
            show('camera');
            return;
        }
        const typedStaff = String(scan.fields.staff || '').trim();
        if (typedStaff && !validStaffCode(normaliseStaffCode(typedStaff))) {
            $('staff-card').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        if (!typedStaff && !scan.askedStaff) {
            scan.askedStaff = true; // ask once; a second tap sends without a stylist
            updateStaffStatus();
            $('staff-card').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        const btn = $('send-btn');
        btn.disabled = true;
        try {
            await outbox.add(buildPayload());
            const error = await flushOutbox();
            if (!error) toast('Sent to reception ✓');
            else if (error === 'key') toast('Saved. The scanner key was refused, so check Settings.', 5000);
            else toast('Saved on this phone. It will send automatically when the connection is back.', 5000);
            scan = null;
            show('camera');
        } finally {
            btn.disabled = false;
        }
    });

    // ------------------------------------------------------------ settings

    function openSettings() {
        const form = $('settings-form');
        form.apiUrl.value = settings.apiUrl;
        form.apiKey.value = settings.apiKey;
        form.device.value = settings.device;
        $('settings-status').hidden = true;
        $('try-btn').hidden = isConfigured();
        show('settings');
    }

    function readForm() {
        const form = $('settings-form');
        return {
            apiUrl: form.apiUrl.value.trim(),
            apiKey: form.apiKey.value.trim(),
            device: form.device.value.trim() || 'Reception phone',
        };
    }

    $('settings-btn').addEventListener('click', openSettings);
    $('outbox-chip').addEventListener('click', async () => {
        const error = await flushOutbox();
        if (error === 'key') openSettings();
        else toast(error ? 'Still offline. Will retry automatically.' : 'All sent ✓');
    });
    $('settings-back').addEventListener('click', () => show('camera'));
    $('try-btn').addEventListener('click', () => show('camera'));

    $('settings-form').addEventListener('submit', (event) => {
        event.preventDefault();
        settings = readForm();
        saveSettings(settings);
        toast('Settings saved');
        flushOutbox();
        refreshStaff();
        show('camera');
    });

    $('test-btn').addEventListener('click', async () => {
        const status = $('settings-status');
        const s = readForm();
        status.hidden = false;
        status.className = 'notice';
        status.textContent = 'Testing…';
        try {
            const url = s.apiUrl + (s.apiUrl.includes('?') ? '&' : '?') + 'ping=1';
            const res = await fetch(url, { headers: { Authorization: 'Bearer ' + s.apiKey } });
            if (res.ok) {
                const count = await refreshStaff(s);
                status.className = 'notice notice--ok';
                status.textContent = 'Connected. The salon software accepted the key. ' + (count
                    ? `${count} stylist${count === 1 ? '' : 's'} loaded.`
                    : 'No staff list yet, so reception will type the C number.');
            } else {
                status.className = 'notice notice--error';
                status.textContent = res.status === 401 ? 'Connected, but the key was refused.' : `The server answered with an error (${res.status}).`;
            }
        } catch (e) {
            status.className = 'notice notice--error';
            status.textContent = 'Could not reach that address. Check it and the internet connection.';
        }
    });

    // --------------------------------------------------------------- start

    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register('sw.js').catch(() => {});
    }
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stopCamera();
        else if (!$('camera').hidden) startCamera();
    });

    updateOutboxChip();
    window.addEventListener('online', () => refreshStaff());
    if (isConfigured()) {
        show('camera');
        flushOutbox();
        refreshStaff();
    } else {
        openSettings();
    }
})();
