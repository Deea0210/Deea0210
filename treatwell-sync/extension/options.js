(() => {
    'use strict';
    const form = document.getElementById('form');
    const status = document.getElementById('status');
    const say = (text, tone) => {
        status.textContent = text;
        status.className = 'status' + (tone ? ' status--' + tone : '');
        status.hidden = !text;
    };

    // Same rule as background.js: "S.Tsegi", "s tsegi" and "STSEGI" are the same person.
    const staffKey = (name) => String(name || '').toLowerCase().replace(/[^\p{L}\p{N}]/gu, '');
    const typedStaff = () => {
        const names = form.excludeStaff.value.split(/[\n,;]+/).map(n => n.trim()).filter(Boolean);
        return names.filter((n, i) => names.findIndex(m => staffKey(m) === staffKey(n)) === i);
    };
    let seenStaff = []; // staff names seen in Treatwell bookings

    const read = () => ({
        refreshMinutes: Number(form.refreshMinutes.value) || 5,
        apiUrl: form.apiUrl.value.trim(),
        apiKey: form.apiKey.value.trim(),
        device: form.device.value.trim() || 'Reception PC',
        excludeStaff: typedStaff(),
    });

    /** Clickable names seen in Treatwell, and a hint for typed names that match nobody. */
    function renderStaff() {
        const left = new Set(typedStaff().map(staffKey));
        const box = document.getElementById('staff-seen');
        box.hidden = !seenStaff.length;
        box.replaceChildren(Object.assign(document.createElement('span'), { className: 'note', textContent: 'Staff in Treatwell:' }),
            ...seenStaff.map(name => {
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'chip';
                chip.textContent = name;
                chip.setAttribute('aria-pressed', String(left.has(staffKey(name))));
                chip.title = left.has(staffKey(name)) ? 'Click to include again' : 'Click to leave out';
                chip.addEventListener('click', () => {
                    const names = typedStaff();
                    form.excludeStaff.value = (left.has(staffKey(name))
                        ? names.filter(n => staffKey(n) !== staffKey(name))
                        : names.concat(name)).join('\n');
                    renderStaff();
                });
                return chip;
            }));
        const check = document.getElementById('staff-check');
        const known = new Set(seenStaff.map(staffKey));
        const unknown = seenStaff.length ? typedStaff().filter(n => staffKey(n) && !known.has(staffKey(n))) : [];
        check.hidden = !unknown.length;
        check.textContent = unknown.map(n => {
            const close = seenStaff.find(s => staffKey(s).includes(staffKey(n)) || staffKey(n).includes(staffKey(s)));
            return `"${n}" isn't a staff name in Treatwell${close ? `. Did you mean ${close}?` : '. Check the spelling.'}`;
        }).join(' ');
    }
    form.excludeStaff.addEventListener('input', renderStaff);

    /** Chrome only lets the extension talk to the salon software's address once it's allowed here. */
    function askAccess(apiUrl) {
        if (!apiUrl) return Promise.resolve(true);
        let origin;
        try {
            origin = new URL(apiUrl).origin + '/*';
        } catch (e) {
            return Promise.resolve(false);
        }
        return chrome.permissions.request({ origins: [origin] }).catch(() => false);
    }

    chrome.storage.local.get(['settings', 'state']).then(({ settings, state }) => {
        const s = Object.assign({ refreshMinutes: 5, apiUrl: '', apiKey: '', device: 'Reception PC', excludeStaff: [] }, settings || {});
        form.refreshMinutes.value = String(s.refreshMinutes);
        form.apiUrl.value = s.apiUrl;
        form.apiKey.value = s.apiKey;
        form.device.value = s.device;
        form.excludeStaff.value = s.excludeStaff.join('\n');
        seenStaff = Object.keys((state && state.staffSeen) || {}).sort((a, b) => a.localeCompare(b));
        renderStaff();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const s = read();
        const access = askAccess(s.apiUrl); // must start straight from the click
        if (s.apiUrl && !s.apiKey) return say('Add the key too, or leave the address empty.', 'warn');
        if (!(await access)) return say('Chrome needs your OK to reach the salon software. Click Save again and choose Allow.', 'warn');
        await chrome.storage.local.set({ settings: s });
        await chrome.runtime.sendMessage({ type: 'settings-changed' });
        say('Saved ✓', 'ok');
    });

    document.getElementById('test').addEventListener('click', async () => {
        const s = read();
        const access = askAccess(s.apiUrl);
        if (!s.apiUrl || !s.apiKey) return say('Fill in the address and key first.', 'warn');
        if (!(await access)) return say('Chrome needs your OK to reach the salon software. Try again and choose Allow.', 'warn');
        say('Testing…');
        try {
            const url = s.apiUrl + (s.apiUrl.includes('?') ? '&' : '?') + 'ping=1';
            const res = await fetch(url, { headers: { Authorization: 'Bearer ' + s.apiKey }, cache: 'no-store' });
            if (res.ok) say('Connected. The salon software accepted the key.', 'ok');
            else say(res.status === 401 ? 'Connected, but the key was refused.' : `The server answered with an error (${res.status}).`, 'bad');
        } catch (e) {
            say('Could not reach that address. Check it and the internet connection.', 'bad');
        }
    });
})();
