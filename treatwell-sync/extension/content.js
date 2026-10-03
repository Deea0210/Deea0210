/*
 * Runs in the Treatwell Connect tab, next to the page. Receives the JSON the page loads
 * (from page-hook.js), finds the bookings in it and hands them to the extension.
 */
(() => {
    'use strict';
    const X = window.TwExtract;
    const CHANNEL = 'tw-bookings-sync';
    const MAX_CAPTURES = 60;
    // Answers from these pages are never treated as bookings (reviews, reports, …).
    const NOT_BOOKINGS_URL = /review|rating|notification|message|report|statistic|analytics|marketing|invoice|payment|transaction|sale|voucher|setting|translation|i18n|feature|activity|appointment-events|waiting-list|waitlist|point-of-sale/i;

    const captures = new Map();           // request → latest answer { url, method, at, json, count, fromSource }
    const lookups = { staff: {}, service: {}, customer: {} };
    let source = null;                    // the request the calendar uses for its bookings
    const waiting = new Map();            // replay id → callback
    let sendTimer = 0;

    const samePath = (a, b) => {
        try {
            const x = new URL(a), y = new URL(b);
            return x.origin === y.origin && x.pathname.replace(/\d{4}-\d{2}-\d{2}/g, '') === y.pathname.replace(/\d{4}-\d{2}-\d{2}/g, '');
        } catch (e) {
            return false;
        }
    };
    const isSource = (c) => Boolean(source) && c.method === source.method && samePath(c.url, source.url)
        && (c.method === 'GET' || operation(c.requestBody) === operation(source.requestBody));
    const operation = (body) => {
        const m = /"operationName"\s*:\s*"([^"]+)"/.exec(body || '') || /query\s+(\w+)/.exec(body || '');
        return m ? m[1] : '';
    };

    window.addEventListener('message', (event) => {
        if (event.source !== window || !event.data || event.data[CHANNEL] !== 'capture') return;
        try {
            onCapture(event.data);
        } catch (e) {
            console.warn('[Treatwell Bookings] could not read an answer:', e);
        }
    });

    function onCapture(c) {
        const done = c.replayId ? waiting.get(c.replayId) : null;
        if (done) waiting.delete(c.replayId);
        if (c.error || !c.body || c.status >= 400) {
            if (done) done({ ok: false, reason: c.status === 401 || c.status === 403 ? 'logged-out' : 'error', status: c.status || 0 });
            return;
        }
        let json;
        try {
            json = JSON.parse(c.body);
        } catch (e) {
            if (done) done({ ok: false, reason: 'error' });
            return;
        }
        X.collectLookups(json, lookups);
        const bookings = NOT_BOOKINGS_URL.test(new URL(c.url).pathname) ? [] : X.findBookings(json, lookups);

        const key = c.method + ' ' + c.url + ' ' + (c.requestBody || '');
        captures.delete(key);
        captures.set(key, { url: c.url, method: c.method, requestBody: c.requestBody || null, at: Date.now(), json, count: bookings.length });
        while (captures.size > MAX_CAPTURES) captures.delete(captures.keys().next().value);

        // The calendar's own list of bookings: the readable request that returns the most of them.
        if (bookings.length && c.replayable && (!source || isSource(c) || bookings.length > source.count)) {
            source = { url: c.url, method: c.method, headers: c.headers || {}, requestBody: c.requestBody || null, count: bookings.length };
        }
        if (done) {
            send();
            done({ ok: true, count: bookings.length });
        } else {
            clearTimeout(sendTimer);
            sendTimer = setTimeout(send, 400);
        }
    }

    /**
     * All bookings seen since the page opened. Days the calendar list covers are "complete":
     * there, bookings that only appear in older answers (e.g. since cancelled) are dropped.
     */
    function snapshot() {
        const coveredAt = {};
        for (const c of captures.values()) {
            if (!isSource(c)) continue;
            const days = X.daysCovered(c.url + ' ' + (c.requestBody || ''));
            const found = X.findBookings(c.json, lookups);
            for (const day of days.concat(found.map(b => b.date))) coveredAt[day] = Math.max(coveredAt[day] || 0, c.at);
        }
        const all = [];
        for (const c of captures.values()) {
            if (!c.count) continue;
            const fromSource = isSource(c);
            for (const b of X.findBookings(c.json, lookups)) {
                if (!fromSource && coveredAt[b.date] && c.at < coveredAt[b.date]) continue;
                all.push(b);
            }
        }
        return { bookings: X.dedupe(all), complete: Object.keys(coveredAt) };
    }

    function send() {
        if (!chrome.runtime || !chrome.runtime.id) return; // extension was reloaded: this tab needs a reload too
        const snap = snapshot();
        chrome.runtime.sendMessage({
            type: 'bookings', bookings: snap.bookings, complete: snap.complete, at: Date.now(), hasSource: Boolean(source),
        }).catch(() => {});
    }

    function refresh(day) {
        if (!source) return Promise.resolve({ ok: false, reason: 'no-source' });
        const id = Math.random().toString(36).slice(2);
        return new Promise((resolve) => {
            const timer = setTimeout(() => {
                waiting.delete(id);
                resolve({ ok: false, reason: 'timeout' });
            }, 20000);
            waiting.set(id, (result) => {
                clearTimeout(timer);
                resolve(result);
            });
            window.postMessage({
                [CHANNEL]: 'replay', replayId: id,
                url: X.forDay(source.url, day),
                headers: source.headers,
                requestBody: source.requestBody ? X.forDay(source.requestBody, day) : null,
            }, location.origin);
        });
    }

    /** What the page loads, with names, phone numbers and other personal details removed. */
    function sample() {
        const snap = snapshot();
        return {
            createdAt: new Date().toISOString(),
            extension: chrome.runtime.getManifest().version,
            page: location.origin + location.pathname,
            bookingsSource: source ? { method: source.method, url: X.redactUrl(source.url), headerNames: Object.keys(source.headers) } : null,
            bookingsFound: snap.bookings.length,
            bookingsAsRead: X.redact(snap.bookings.slice(0, 5)),
            answers: [...captures.values()].map(c => ({
                method: c.method,
                url: X.redactUrl(c.url),
                bookingsFound: c.count,
                body: X.redact(c.json),
            })),
        };
    }

    chrome.runtime.onMessage.addListener((msg, sender, reply) => {
        if (msg.type === 'refresh') {
            refresh(msg.day).then(reply);
            return true;
        }
        if (msg.type === 'sample') reply(sample());
        if (msg.type === 'ping') reply({ ok: true, hasSource: Boolean(source), answers: captures.size });
        return false;
    });
})();
