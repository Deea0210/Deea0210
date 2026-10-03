(() => {
    'use strict';
    const form = document.getElementById('form');
    const status = document.getElementById('status');
    const say = (text, tone) => {
        status.textContent = text;
        status.className = 'status' + (tone ? ' status--' + tone : '');
        status.hidden = !text;
    };

    const read = () => ({
        refreshMinutes: Number(form.refreshMinutes.value) || 5,
        apiUrl: form.apiUrl.value.trim(),
        apiKey: form.apiKey.value.trim(),
        device: form.device.value.trim() || 'Reception PC',
    });

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

    chrome.storage.local.get('settings').then(({ settings }) => {
        const s = Object.assign({ refreshMinutes: 5, apiUrl: '', apiKey: '', device: 'Reception PC' }, settings || {});
        form.refreshMinutes.value = String(s.refreshMinutes);
        form.apiUrl.value = s.apiUrl;
        form.apiKey.value = s.apiKey;
        form.device.value = s.device;
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
