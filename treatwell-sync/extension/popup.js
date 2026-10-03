/* The list of today's bookings (toolbar popup, or a full page with ?page=1). */
(() => {
    'use strict';
    const $ = (id) => document.getElementById(id);
    const asPage = new URLSearchParams(location.search).has('page');
    if (asPage) document.body.classList.add('page');

    const pad = (n) => String(n).padStart(2, '0');
    const todayKey = () => {
        const d = new Date();
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    };
    const clock = (ms) => new Date(ms).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    const money = (v) => '£' + v.toFixed(2);

    let state = null, settings = {}, staffFilter = '';

    function el(tag, cls, ...children) {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        for (const c of children) if (c !== null && c !== undefined && c !== '') node.append(c);
        return node;
    }

    function todays() {
        const day = state && state.days && state.days[todayKey()];
        if (!day) return [];
        return Object.values(day.bookings).sort((a, b) => (a.start + a.staff).localeCompare(b.start + b.staff));
    }

    function setStatus(node, text, tone) {
        node.textContent = text;
        node.className = 'status' + (node.id === 'send-status' ? ' status--send' : '') + (tone ? ' status--' + tone : '');
        node.hidden = !text;
    }

    function renderStatus() {
        const refresh = state.refresh || {}, connect = state.connect || {}, send = state.send || {};
        const minutes = Number(settings.refreshMinutes) || 5;
        let text, tone;
        if (refresh.reason === 'no-tab') {
            text = 'Open Treatwell Connect in Chrome and log in. The list fills in from its calendar.';
            tone = 'warn';
        } else if (refresh.reason === 'logged-out') {
            text = 'Treatwell Connect is logged out. Log in again in its tab.';
            tone = 'bad';
        } else if (refresh.reason === 'no-script') {
            text = 'Reload the Treatwell Connect tab once (press F5 in that tab).';
            tone = 'warn';
        } else if (refresh.reason === 'no-source' || (!connect.hasSource && !todays().length)) {
            text = 'Open the Calendar in Treatwell Connect once, so the bookings can be read.';
            tone = 'warn';
        } else if (refresh.reason === 'timeout' || refresh.reason === 'error') {
            text = `Treatwell didn't answer the last refresh (${clock(refresh.at)}). Trying again in ${minutes} min.`;
            tone = 'warn';
        } else {
            const at = Math.max(refresh.ok ? refresh.at || 0 : 0, connect.seenAt || 0);
            text = at ? `Updated ${clock(at)} · refreshes every ${minutes} min` : 'Waiting for Treatwell Connect…';
            tone = at ? 'ok' : '';
        }
        setStatus($('status'), text, tone);

        if (!settings.apiUrl) {
            setStatus($('send-status'), '', '');
        } else if (send.ok) {
            setStatus($('send-status'), `Sent to the salon software at ${clock(send.at)} ✓`, 'ok');
        } else if (send.error === 'key') {
            setStatus($('send-status'), 'The salon software refused the key. Check Settings.', 'bad');
        } else if (send.error) {
            setStatus($('send-status'), `Couldn't reach the salon software (${send.error === 'offline' ? 'no connection' : send.error}). It will retry.`, 'warn');
        } else {
            setStatus($('send-status'), '', '');
        }
    }

    function render() {
        if (!state) return;
        $('date').textContent = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
        renderStatus();

        const all = todays();
        const staff = [...new Set(all.map(b => b.staff).filter(Boolean))].sort();
        if (staffFilter && !staff.includes(staffFilter)) staffFilter = '';
        const shown = all.filter(b => !staffFilter || b.staff === staffFilter);

        const live = all.filter(b => !b.cancelled);
        const cancelled = all.length - live.length;
        const value = live.reduce((sum, b) => sum + (typeof b.price === 'number' ? b.price : 0), 0);
        $('summary').replaceChildren(
            el('span', '', el('strong', '', String(live.length)), live.length === 1 ? ' booking' : ' bookings'),
            cancelled ? el('span', '', el('strong', '', String(cancelled)), ' cancelled') : '',
            value ? el('span', '', el('strong', '', money(value)), ' booked') : '',
        );

        $('filters').replaceChildren(...(staff.length > 1 ? ['', ...staff] : []).map(name => {
            const chip = el('button', 'chip', name || 'Everyone');
            chip.type = 'button';
            chip.setAttribute('aria-pressed', String(staffFilter === name));
            chip.addEventListener('click', () => { staffFilter = name; render(); });
            return chip;
        }));

        const listEl = $('bookings');
        listEl.dataset.empty = state.connect && state.connect.seenAt ? 'No bookings for today.' : 'No bookings read yet.';
        listEl.replaceChildren(...shown.map(b => {
            const item = el('li', 'booking' + (b.cancelled ? ' booking--cancelled' : ''),
                el('div', 'booking__time', b.start, b.end ? el('small', '', '– ' + b.end) : ''),
                el('div', '',
                    el('div', 'booking__client', b.customer || 'Client',
                        b.cancelled ? el('span', 'badge badge--cancelled', 'Cancelled') : '',
                        b.noShow ? el('span', 'badge badge--noshow', 'No-show') : ''),
                    el('div', 'booking__service', b.service || ''),
                    b.phone || b.status ? el('div', 'booking__meta', [b.phone, b.status && !b.cancelled ? b.status.toLowerCase().replace(/_/g, ' ') : ''].filter(Boolean).join(' · ')) : '',
                    b.notes ? el('div', 'booking__notes', b.notes) : ''),
                el('div', 'booking__side',
                    b.staff ? el('span', 'booking__staff', b.staff) : '',
                    typeof b.price === 'number' ? el('div', 'booking__price', money(b.price)) : ''));
            return item;
        }));
    }

    async function load() {
        const data = await chrome.storage.local.get(['state', 'settings']);
        state = Object.assign({ days: {}, connect: {}, refresh: {}, send: {} }, data.state || {});
        settings = Object.assign({ refreshMinutes: 5 }, data.settings || {});
        render();
    }

    chrome.storage.onChanged.addListener((changes, area) => {
        if (area === 'local' && (changes.state || changes.settings)) load();
    });

    $('refresh').addEventListener('click', async () => {
        const btn = $('refresh');
        btn.classList.add('is-busy');
        btn.disabled = true;
        try {
            await chrome.runtime.sendMessage({ type: 'refresh' });
        } finally {
            btn.classList.remove('is-busy');
            btn.disabled = false;
            load();
        }
    });

    $('open-tab').addEventListener('click', () => {
        chrome.tabs.create({ url: chrome.runtime.getURL('popup.html?page=1') });
        if (!asPage) window.close();
    });
    $('settings').addEventListener('click', () => chrome.runtime.openOptionsPage());

    function download(name, type, content) {
        const url = URL.createObjectURL(new Blob([content], { type }));
        const a = el('a');
        a.href = url;
        a.download = name;
        document.body.append(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2000);
    }

    $('copy').addEventListener('click', async () => {
        const lines = todays().map(b => [
            `${b.start}${b.end ? '–' + b.end : ''}`,
            b.customer || 'Client',
            b.service,
            b.staff ? `(${b.staff})` : '',
            typeof b.price === 'number' ? money(b.price) : '',
            b.cancelled ? 'CANCELLED' : '',
        ].filter(Boolean).join('  '));
        await navigator.clipboard.writeText(lines.join('\n') || 'No bookings');
        $('copy').textContent = 'Copied ✓';
        setTimeout(() => { $('copy').textContent = 'Copy list'; }, 1500);
    });

    $('csv').addEventListener('click', () => {
        const cell = (v) => {
            const s = v === null || v === undefined ? '' : String(v);
            const safe = /^[=+\-@]/.test(s) ? "'" + s : s; // stops spreadsheets running it as a formula
            return /[",\n]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe;
        };
        const head = ['Date', 'Start', 'End', 'Client', 'Phone', 'Email', 'Service', 'Stylist', 'Price', 'Status', 'Cancelled', 'Notes', 'Treatwell ID'];
        const rows = todays().map(b => [b.date, b.start, b.end, b.customer, b.phone, b.email, b.service, b.staff,
            typeof b.price === 'number' ? b.price.toFixed(2) : '', b.status, b.cancelled ? 'yes' : '', b.notes, b.id]);
        download(`treatwell-bookings-${todayKey()}.csv`, 'text/csv', '﻿' + [head, ...rows].map(r => r.map(cell).join(',')).join('\r\n'));
    });

    $('sample').addEventListener('click', async () => {
        const answer = await chrome.runtime.sendMessage({ type: 'sample' });
        if (answer && answer.answers) {
            download(`treatwell-setup-${todayKey()}.json`, 'application/json', JSON.stringify(answer, null, 2));
            setStatus($('status'), 'Setup file saved to Downloads. It has no client names or phone numbers. Send it to your developer.', 'ok');
        } else {
            setStatus($('status'), 'Open the Treatwell Connect calendar first, then try again.', 'warn');
        }
    });

    load();
    if (asPage) setInterval(render, 60000); // keeps the date right on an all-day reception screen
})();
