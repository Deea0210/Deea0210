/*
 * Runs inside the Treatwell Connect page itself (the page's own JavaScript world).
 *
 * Connect's calendar loads its bookings from Treatwell's servers as JSON. This passes a copy
 * of each JSON answer to content.js, and when asked, repeats the request the calendar made
 * (same address, same login, only ever a read) so the bookings can refresh on their own.
 * Nothing here changes what the page does or shows.
 */
(() => {
    'use strict';
    if (window.__twBookingsSync) return;
    window.__twBookingsSync = true;

    const CHANNEL = 'tw-bookings-sync';
    const MAX_BODY = 5 * 1024 * 1024;
    const nativeFetch = window.fetch;

    const post = (data) => window.postMessage(Object.assign({ [CHANNEL]: 'capture' }, data), location.origin);
    const absolute = (url) => {
        try { return new URL(url, location.href).href; } catch (e) { return String(url); }
    };
    // Only Treatwell's own servers (the page also talks to analytics, chat widgets, …).
    const isTreatwell = (url) => {
        try {
            const host = new URL(url).hostname;
            return host === location.hostname || /(^|\.)treatwell\.[a-z.]+$/.test(host);
        } catch (e) {
            return false;
        }
    };

    function headersToObject(h) {
        const out = {};
        if (!h) return out;
        try {
            if (h instanceof Headers) h.forEach((v, k) => { out[k] = v; });
            else if (Array.isArray(h)) h.forEach(([k, v]) => { out[k] = v; });
            else Object.keys(h).forEach((k) => { out[k] = String(h[k]); });
        } catch (e) { /* unusual headers object */ }
        return out;
    }

    /** A request is safe to repeat only if it reads: GET, or a GraphQL query (never a mutation). */
    function replayableBody(method, body) {
        if (method === 'GET') return null;
        if (method !== 'POST' || typeof body !== 'string' || body.length > 20000) return undefined;
        try {
            const parsed = JSON.parse(body);
            const queries = Array.isArray(parsed) ? parsed : [parsed];
            const readOnly = queries.every(q => q && typeof q.query === 'string' && !/^\s*mutation\b/.test(q.query) && !/\bmutation\s*[({]/.test(q.query));
            return readOnly ? body : undefined;
        } catch (e) {
            return undefined;
        }
    }

    function report(url, method, status, contentType, textPromise, request) {
        if (!/json|graphql/i.test(contentType || '')) return;
        textPromise.then((body) => {
            if (!body || body.length > MAX_BODY || !/^\s*[[{]/.test(body)) return;
            post(Object.assign({ url, method, status, body }, request));
        }).catch(() => {});
    }

    // ---- fetch
    window.fetch = function (input, init) {
        const promise = nativeFetch.apply(this, arguments);
        try {
            const isRequest = typeof Request !== 'undefined' && input instanceof Request;
            const url = absolute(isRequest ? input.url : input);
            if (isTreatwell(url)) {
                const method = String((init && init.method) || (isRequest ? input.method : 'GET')).toUpperCase();
                const headers = Object.assign(headersToObject(isRequest ? input.headers : null), headersToObject(init && init.headers));
                const body = replayableBody(method, init && init.body);
                promise.then((response) => {
                    report(url, method, response.status, response.headers.get('content-type'), response.clone().text(),
                        body === undefined ? { replayable: false } : { replayable: true, headers, requestBody: body });
                }).catch(() => {});
            }
        } catch (e) { /* never get in the page's way */ }
        return promise;
    };

    // ---- XMLHttpRequest
    const XHR = XMLHttpRequest.prototype;
    const nativeOpen = XHR.open, nativeSetHeader = XHR.setRequestHeader, nativeSend = XHR.send;
    XHR.open = function (method, url) {
        try { this.__tw = { method: String(method || 'GET').toUpperCase(), url: absolute(url), headers: {} }; } catch (e) { /* ignore */ }
        return nativeOpen.apply(this, arguments);
    };
    XHR.setRequestHeader = function (name, value) {
        try { if (this.__tw) this.__tw.headers[name] = String(value); } catch (e) { /* ignore */ }
        return nativeSetHeader.apply(this, arguments);
    };
    XHR.send = function (body) {
        try {
            const info = this.__tw;
            if (info && isTreatwell(info.url)) {
                const replay = replayableBody(info.method, body);
                this.addEventListener('load', () => {
                    try {
                        let text;
                        if (this.responseType === '' || this.responseType === 'text') text = this.responseText;
                        else if (this.responseType === 'json') text = JSON.stringify(this.response);
                        else return;
                        report(info.url, info.method, this.status, this.getResponseHeader('content-type'), Promise.resolve(text),
                            replay === undefined ? { replayable: false } : { replayable: true, headers: info.headers, requestBody: replay });
                    } catch (e) { /* ignore */ }
                });
            }
        } catch (e) { /* ignore */ }
        return nativeSend.apply(this, arguments);
    };

    // ---- repeat a read request when content.js asks (automatic refresh)
    window.addEventListener('message', (event) => {
        const msg = event.data;
        if (event.source !== window || !msg || msg[CHANNEL] !== 'replay') return;
        const method = msg.requestBody ? 'POST' : 'GET';
        if (!isTreatwell(msg.url) || (method === 'POST' && replayableBody('POST', msg.requestBody) === undefined)) {
            post({ replayId: msg.replayId, error: 'not allowed' });
            return;
        }
        nativeFetch(msg.url, { method, headers: msg.headers || {}, body: msg.requestBody || undefined, credentials: 'include', cache: 'no-store' })
            .then(response => response.text().then(body => post({
                replayId: msg.replayId, url: msg.url, method, status: response.status, body,
                replayable: true, headers: msg.headers, requestBody: msg.requestBody || null,
            })))
            .catch(error => post({ replayId: msg.replayId, error: String(error) }));
    });
})();
