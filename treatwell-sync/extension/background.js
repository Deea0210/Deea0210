/*
 * Keeps today's bookings, refreshes them from the open Treatwell Connect tab every few
 * minutes, and (if set up) sends them to the salon software.
 */
importScripts('extract.js');

const CONNECT_TABS = ['https://connect.treatwell.co.uk/*'];
const DEFAULTS = { apiUrl: '', apiKey: '', refreshMinutes: 5, device: 'Reception PC' };
const KEEP_DAYS = 7;

const today = () => self.TwExtract.localDate(new Date());

async function getSettings() {
    const { settings } = await chrome.storage.local.get('settings');
    return Object.assign({}, DEFAULTS, settings || {});
}

async function getState() {
    const { state } = await chrome.storage.local.get('state');
    return Object.assign({ days: {}, connect: {}, refresh: {}, send: {} }, state || {});
}

// Messages can arrive together; handle them one at a time so nothing is lost.
let queue = Promise.resolve();
const serial = (job) => (queue = queue.then(job, job));

// ------------------------------------------------------------ bookings from the Connect tab

function storeBookings(state, msg) {
    const complete = new Set(msg.complete || []);
    const byDay = {};
    for (const b of msg.bookings || []) (byDay[b.date] = byDay[b.date] || []).push(b);
    for (const day of new Set([...complete, ...Object.keys(byDay)])) {
        const entry = state.days[day] || { bookings: {}, complete: false };
        const fresh = byDay[day] || [];
        if (complete.has(day)) {
            entry.bookings = Object.fromEntries(fresh.map(b => [b.id, b]));
            entry.complete = true;
        } else {
            for (const b of fresh) entry.bookings[b.id] = b;
        }
        entry.updatedAt = msg.at;
        state.days[day] = entry;
    }
    // Don't keep clients' details longer than needed.
    const oldest = new Date();
    oldest.setDate(oldest.getDate() - KEEP_DAYS);
    const cutoff = self.TwExtract.localDate(oldest);
    for (const day of Object.keys(state.days)) if (day < cutoff) delete state.days[day];
}

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
    if (msg.type === 'bookings' && sender.tab) {
        serial(async () => {
            const state = await getState();
            storeBookings(state, msg);
            state.connect = { seenAt: msg.at, hasSource: msg.hasSource };
            await chrome.storage.local.set({ state });
            await sendToSalon();
            await updateBadge();
        });
        return false;
    }
    if (msg.type === 'refresh') {
        refreshNow().then(reply);
        return true;
    }
    if (msg.type === 'sample') {
        askConnectTab({ type: 'sample' }).then(reply);
        return true;
    }
    if (msg.type === 'settings-changed') {
        setupAlarm().then(() => serial(() => sendToSalon(true))).then(updateBadge).then(() => reply({ ok: true }));
        return true;
    }
    return false;
});

// ------------------------------------------------------------ automatic refresh

async function connectTabs() {
    return chrome.tabs.query({ url: CONNECT_TABS });
}

async function askConnectTab(message) {
    const tabs = await connectTabs();
    if (!tabs.length) return { ok: false, reason: 'no-tab' };
    let last = { ok: false, reason: 'no-script' };
    // Prefer the tab being looked at, then the most recently used one.
    tabs.sort((a, b) => (b.active - a.active) || ((b.lastAccessed || 0) - (a.lastAccessed || 0)));
    for (const tab of tabs) {
        try {
            const answer = await chrome.tabs.sendMessage(tab.id, message);
            if (answer && answer.ok !== false) return answer;
            if (answer) last = answer;
        } catch (e) {
            // The tab was open before the extension was installed or updated: it needs a reload.
        }
    }
    return last;
}

async function refreshNow() {
    const result = await askConnectTab({ type: 'refresh', day: today() });
    await serial(async () => {
        const state = await getState();
        state.refresh = { at: Date.now(), ok: Boolean(result.ok), reason: result.reason || '' };
        await chrome.storage.local.set({ state });
        await sendToSalon(); // retries a send that failed earlier
    });
    await updateBadge();
    return result;
}

async function setupAlarm() {
    const { refreshMinutes } = await getSettings();
    await chrome.alarms.clear('refresh');
    await chrome.alarms.create('refresh', { periodInMinutes: Math.max(1, Number(refreshMinutes) || 5), delayInMinutes: 0.1 });
}

chrome.runtime.onInstalled.addListener(() => {
    setupAlarm();
    updateBadge();
});
chrome.runtime.onStartup.addListener(() => {
    setupAlarm();
    updateBadge();
});
chrome.alarms.onAlarm.addListener((alarm) => {
    if (alarm.name === 'refresh') refreshNow();
});

// ------------------------------------------------------------ salon software

function todaysBookings(state) {
    const day = state.days[today()];
    return day ? Object.values(day.bookings).sort((a, b) => (a.start + a.staff).localeCompare(b.start + b.staff)) : [];
}

function fingerprint(value) {
    const s = JSON.stringify(value);
    let h = 5381;
    for (let i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) >>> 0;
    return h.toString(36) + ':' + s.length;
}

/** Sends today's list when it changed (or when `force`). Runs inside serial(). */
async function sendToSalon(force) {
    const settings = await getSettings();
    if (!settings.apiUrl || !settings.apiKey) return;
    const state = await getState();
    const date = today();
    const day = state.days[date];
    if (!day) return;
    const bookings = todaysBookings(state);
    const hash = fingerprint([date, day.complete, bookings]);
    if (!force && state.send.ok && state.send.hash === hash) return;
    let result;
    try {
        const response = await fetch(settings.apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + settings.apiKey },
            body: JSON.stringify({
                source: 'treatwell-connect',
                date,
                complete: Boolean(day.complete),
                syncedAt: new Date().toISOString(),
                device: settings.device,
                bookings,
            }),
        });
        result = response.ok
            ? { ok: true }
            : { ok: false, error: response.status === 401 || response.status === 403 ? 'key' : 'server ' + response.status };
    } catch (e) {
        result = { ok: false, error: 'offline' };
    }
    const latest = await getState();
    latest.send = Object.assign({ at: Date.now(), hash: result.ok ? hash : state.send.hash }, result);
    await chrome.storage.local.set({ state: latest });
}

// ------------------------------------------------------------ toolbar badge

async function updateBadge() {
    const state = await getState();
    const live = todaysBookings(state).filter(b => !b.cancelled);
    const problem = (state.refresh.reason === 'logged-out') || (state.send.ok === false && (await getSettings()).apiUrl);
    await chrome.action.setBadgeText({ text: problem ? '!' : live.length ? String(live.length) : '' });
    await chrome.action.setBadgeBackgroundColor({ color: problem ? '#b91c1c' : '#0f766e' });
}
