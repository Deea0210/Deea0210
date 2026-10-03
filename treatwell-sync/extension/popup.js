/* The list of today's bookings (toolbar popup, or a full page with ?page=1). */
(() => {
    'use strict';
    const $ = (id) => document.getElementById(id);
    const api = globalThis.browser || globalThis.chrome; // Firefox: browser.*, Chrome: chrome.*
    const params = new URLSearchParams(location.search);
    const asPage = params.has('page');
    if (asPage) document.body.classList.add('page');
    // Firefox can lose a file saved straight from the toolbar popup, so there it's saved from the full-page list.
    const saveFromTab = !asPage && typeof globalThis.browser !== 'undefined' && /Firefox\//.test(navigator.userAgent);

    const pad = (n) => String(n).padStart(2, '0');
    const todayKey = () => {
        const d = new Date();
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    };
    const nowTime = () => {
        const d = new Date();
        return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
    };
    const clock = (ms) => new Date(ms).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    const money = (v) => '£' + v.toFixed(2);

    // Which bookings to show. "Happening" leaves out no-shows and cancelled bookings.
    const happening = (b) => !b.cancelled && !b.noShow;
    const VIEWS = [
        { key: 'happening', label: 'Happening', test: happening },
        { key: 'upcoming', label: 'Still to come', test: (b, now) => happening(b) && !b.completed && (b.end || b.start) > now },
        { key: 'missed', label: 'No-shows', test: (b) => !happening(b), hideWhenEmpty: true },
    ];

    let state = null, settings = {}, staffFilter = params.get('staff') || '';
    let view = 'happening';
    try { view = localStorage.getItem('view') || view; } catch (e) { /* default view */ }

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

    /** The bookings on screen: the chosen view, then the chosen stylist. */
    function shown() {
        const v = VIEWS.find(x => x.key === view) || VIEWS[0];
        const now = nowTime();
        return todays().filter(b => v.test(b, now) && (!staffFilter || b.staff === staffFilter));
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
            text = 'Open Treatwell Connect in this browser and log in. The list fills in from its calendar.';
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

    function badges(b) {
        if (b.cancelled) return el('span', 'badge badge--cancelled', b.status || 'Cancelled');
        if (b.noShow) return el('span', 'badge badge--noshow', 'No-show');
        if (b.completed) return el('span', 'badge badge--done', 'Done');
        if (/unconfirmed/i.test(b.status || '')) return el('span', 'badge badge--unconfirmed', 'Unconfirmed');
        return '';
    }

    function render() {
        if (!state) return;
        $('date').textContent = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
        renderStatus();

        const all = todays();
        const now = nowTime();
        const visibleViews = VIEWS.filter(v => !v.hideWhenEmpty || all.some(b => v.test(b, now)) || view === v.key);
        if (!visibleViews.some(v => v.key === view)) view = 'happening';
        $('views').replaceChildren(...visibleViews.map(v => {
            const n = all.filter(b => v.test(b, now)).length;
            const btn = el('button', 'view', v.label, el('span', '', ` ${n}`));
            btn.type = 'button';
            btn.setAttribute('role', 'tab');
            btn.setAttribute('aria-selected', String(v.key === view));
            btn.addEventListener('click', () => {
                view = v.key;
                try { localStorage.setItem('view', view); } catch (e) { /* not remembered */ }
                render();
            });
            return btn;
        }));

        const inView = all.filter(b => (VIEWS.find(x => x.key === view) || VIEWS[0]).test(b, now));
        const staff = [...new Set(inView.map(b => b.staff).filter(Boolean))].sort();
        if (staffFilter && !staff.includes(staffFilter)) staffFilter = '';
        const list = shown();

        const value = list.reduce((sum, b) => sum + (typeof b.price === 'number' ? b.price : 0), 0);
        const done = list.filter(b => b.completed).length;
        $('summary').replaceChildren(
            el('span', '', el('strong', '', String(list.length)), list.length === 1 ? ' booking' : ' bookings'),
            view === 'happening' && done ? el('span', '', el('strong', '', String(done)), ' done') : '',
            value && view !== 'missed' ? el('span', '', el('strong', '', money(value)), staffFilter ? ` for ${staffFilter}` : ' booked') : '',
        );

        $('filters').replaceChildren(...(staff.length > 1 ? ['', ...staff] : []).map(name => {
            const chip = el('button', 'chip', name || 'Everyone');
            chip.type = 'button';
            chip.setAttribute('aria-pressed', String(staffFilter === name));
            chip.addEventListener('click', () => { staffFilter = name; render(); });
            return chip;
        }));

        const excluded = settings.excludeStaff || [];
        const leftOutEl = $('left-out');
        leftOutEl.hidden = !excluded.length;
        if (excluded.length) {
            const change = el('button', '', 'Change');
            change.type = 'button';
            change.addEventListener('click', () => api.runtime.openOptionsPage());
            leftOutEl.replaceChildren(`Leaving out bookings for ${excluded.join(', ')} · `, change);
        }

        const listEl = $('bookings');
        listEl.dataset.empty = !(state.connect && state.connect.seenAt) ? 'No bookings read yet.'
            : view === 'upcoming' ? 'Nothing more today.' : view === 'missed' ? 'No no-shows today.' : 'No bookings for today.';
        listEl.replaceChildren(...list.map(b => el('li',
            'booking' + (b.cancelled || b.noShow ? ' booking--cancelled' : '') + (b.completed ? ' booking--done' : ''),
            el('div', 'booking__time', b.start, b.end ? el('small', '', '– ' + b.end) : ''),
            el('div', '',
                el('div', 'booking__client', b.customer || 'Client', badges(b)),
                el('div', 'booking__service', b.service || ''),
                b.phone ? el('div', 'booking__meta', b.phone) : '',
                b.notes ? el('div', 'booking__notes', b.notes) : ''),
            el('div', 'booking__side',
                b.staff ? el('span', 'booking__staff', b.staff) : '',
                typeof b.price === 'number' ? el('div', 'booking__price', money(b.price)) : ''))));
    }

    async function load() {
        const data = await api.storage.local.get(['state', 'settings']);
        state = Object.assign({ days: {}, connect: {}, refresh: {}, send: {} }, data.state || {});
        settings = Object.assign({ refreshMinutes: 5 }, data.settings || {});
        render();
    }

    api.storage.onChanged.addListener((changes, area) => {
        if (area === 'local' && (changes.state || changes.settings)) load();
    });

    $('refresh').addEventListener('click', async () => {
        const btn = $('refresh');
        btn.classList.add('is-busy');
        btn.disabled = true;
        try {
            await api.runtime.sendMessage({ type: 'refresh' });
        } finally {
            btn.classList.remove('is-busy');
            btn.disabled = false;
            load().then(() => {
        const save = params.get('save');
        if (asPage && (save === 'csv' || save === 'sample')) $(save).click();
    });
        }
    });

    $('open-tab').addEventListener('click', () => {
        api.tabs.create({ url: api.runtime.getURL('popup.html?page=1') });
        if (!asPage) window.close();
    });
    $('settings').addEventListener('click', () => api.runtime.openOptionsPage());

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

    // Copy and CSV take what's on screen (view + stylist).
    $('copy').addEventListener('click', async () => {
        const lines = shown().map(b => [
            `${b.start}${b.end ? '–' + b.end : ''}`,
            b.customer || 'Client',
            b.service,
            b.staff ? `(${b.staff})` : '',
            typeof b.price === 'number' ? money(b.price) : '',
            b.cancelled || b.noShow ? (b.status || 'Cancelled').toUpperCase() : '',
        ].filter(Boolean).join('  '));
        await navigator.clipboard.writeText(lines.join('\n') || 'No bookings');
        $('copy').textContent = 'Copied ✓';
        setTimeout(() => { $('copy').textContent = 'Copy list'; }, 1500);
    });

    /** In the Firefox popup: open the full-page list, which saves the file (same tab and stylist). */
    function saveInTab(what) {
        const q = new URLSearchParams({ page: '1', save: what });
        if (staffFilter) q.set('staff', staffFilter);
        api.tabs.create({ url: api.runtime.getURL('popup.html?' + q) });
        window.close();
    }

    $('csv').addEventListener('click', () => {
        if (saveFromTab) return saveInTab('csv');
        const cell = (v) => {
            const s = v === null || v === undefined ? '' : String(v);
            const safe = /^[=+\-@]/.test(s) ? "'" + s : s; // stops spreadsheets running it as a formula
            return /[",\n]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe;
        };
        const head = ['Date', 'Start', 'End', 'Client', 'Phone', 'Email', 'Service', 'Stylist', 'Price', 'Status', 'Notes', 'Treatwell ID'];
        const rows = shown().map(b => [b.date, b.start, b.end, b.customer, b.phone, b.email, b.service, b.staff,
            typeof b.price === 'number' ? b.price.toFixed(2) : '', b.status, b.notes, b.id]);
        const suffix = view === 'happening' ? '' : '-' + view;
        download(`treatwell-bookings-${todayKey()}${suffix}.csv`, 'text/csv', '﻿' + [head, ...rows].map(r => r.map(cell).join(',')).join('\r\n'));
    });

    $('sample').addEventListener('click', async () => {
        if (saveFromTab) return saveInTab('sample');
        const answer = await api.runtime.sendMessage({ type: 'sample' });
        if (answer && answer.answers) {
            download(`treatwell-setup-${todayKey()}.json`, 'application/json', JSON.stringify(answer, null, 2));
            setStatus($('status'), 'Setup file saved to Downloads. It has no client names or phone numbers. Send it to your developer.', 'ok');
        } else {
            setStatus($('status'), 'Open the Treatwell Connect calendar first, then try again.', 'warn');
        }
    });

    load().then(() => {
        const save = params.get('save');
        if (asPage && (save === 'csv' || save === 'sample')) $(save).click();
    });
    setInterval(render, 60000); // "Still to come" and the date move on by themselves
})();
