/* Booking page: service picker, availability calendar and time slots. */
(() => {
    'use strict';

    const form = document.querySelector('[data-booking]');
    if (!form) return;

    const $ = (selector) => form.querySelector(selector);
    const api = form.dataset.api;
    const today = form.dataset.today;       // YYYY-MM-DD (server timezone)
    const horizon = form.dataset.horizon;   // last bookable day
    const minMonth = today.slice(0, 7);
    const maxMonth = horizon.slice(0, 7);

    const dateInput = $('[data-date-input]');
    const timeInput = $('[data-time-input]');
    const calendar = $('[data-calendar]');
    const daysEl = $('[data-cal-days]');
    const monthLabel = $('[data-cal-label]');
    const prevBtn = $('[data-cal-prev]');
    const nextBtn = $('[data-cal-next]');
    const timesEl = $('[data-times]');
    const timesTitle = $('[data-times-title]');
    const meetingSelect = $('[data-meeting-select]');
    const submitBtn = $('[data-submit]');
    const summary = {
        service: $('[data-sum-service]'),
        date: $('[data-sum-date]'),
        time: $('[data-sum-time]'),
        price: $('[data-sum-price]'),
    };
    const serviceInputs = Array.from(form.querySelectorAll('input[name="service"]'));

    let service = serviceInputs.find((input) => input.checked) || null;
    let month = (dateInput.value || today).slice(0, 7);
    let autoAdvanced = Boolean(dateInput.value);
    let renderId = 0;
    const cache = new Map();

    const pad = (n) => String(n).padStart(2, '0');
    const parseIso = (iso) => new Date(Number(iso.slice(0, 4)), Number(iso.slice(5, 7)) - 1, Number(iso.slice(8, 10) || 1));
    const addMonths = (ym, n) => {
        const d = new Date(Number(ym.slice(0, 4)), Number(ym.slice(5, 7)) - 1 + n, 1);
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
    };
    const longDate = (iso) => parseIso(iso).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
    const monthName = (ym) => parseIso(`${ym}-01`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });

    async function fetchMonth(ym) {
        const key = `${service.value}|${ym}`;
        if (!cache.has(key)) {
            const response = await fetch(`${api}?service=${encodeURIComponent(service.value)}&month=${ym}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const json = await response.json();
            cache.set(key, json.days || {});
        }
        return cache.get(key);
    }

    async function renderCalendar() {
        const id = ++renderId;
        monthLabel.textContent = monthName(month);
        prevBtn.disabled = month <= minMonth;
        nextBtn.disabled = month >= maxMonth;

        if (!service) {
            drawDays({});
            return;
        }
        calendar.classList.add('is-loading');
        let days;
        try {
            days = await fetchMonth(month);
        } catch (error) {
            if (id === renderId) {
                calendar.classList.remove('is-loading');
                timesTitle.textContent = 'Could not load availability. Please refresh the page.';
            }
            return;
        }
        if (id !== renderId) return; // a newer render started meanwhile
        calendar.classList.remove('is-loading');

        // Jump ahead once when the current month is fully booked.
        if (!Object.keys(days).length && !autoAdvanced && month < maxMonth) {
            autoAdvanced = true;
            month = addMonths(month, 1);
            renderCalendar();
            return;
        }
        drawDays(days);
    }

    function drawDays(days) {
        const [year, mon] = month.split('-').map(Number);
        const offset = (new Date(year, mon - 1, 1).getDay() + 6) % 7; // Monday first
        const total = new Date(year, mon, 0).getDate();
        const fragment = document.createDocumentFragment();

        for (let i = 0; i < offset; i++) fragment.append(document.createElement('span'));
        for (let d = 1; d <= total; d++) {
            const iso = `${month}-${pad(d)}`;
            const slots = days[iso] || [];
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'day';
            button.textContent = String(d);
            button.dataset.date = iso;
            if (iso === today) button.classList.add('day--today');
            if (slots.length) {
                button.classList.add('day--available');
                button.setAttribute('aria-pressed', String(iso === dateInput.value));
                button.setAttribute('aria-label', `${longDate(iso)}, ${slots.length} time${slots.length > 1 ? 's' : ''} available`);
                button.addEventListener('click', () => selectDate(iso, slots));
            } else {
                button.disabled = true;
                button.setAttribute('aria-label', `${longDate(iso)}, not available`);
            }
            fragment.append(button);
        }
        daysEl.replaceChildren(fragment);

        if (!service) {
            timesTitle.textContent = 'Choose a service to see available times.';
            timesEl.replaceChildren();
        } else if (dateInput.value && dateInput.value.startsWith(month)) {
            if (days[dateInput.value]) {
                drawTimes(dateInput.value, days[dateInput.value]);
            } else {
                clearSelection('That day is no longer available. Please pick another one.');
            }
        } else if (!dateInput.value) {
            timesTitle.textContent = Object.keys(days).length
                ? 'Pick a highlighted day to see times.'
                : 'No free times this month. Try the next one.';
            timesEl.replaceChildren();
        }
    }

    function selectDate(iso, slots) {
        dateInput.value = iso;
        timeInput.value = '';
        daysEl.querySelectorAll('.day--available').forEach((btn) => {
            btn.setAttribute('aria-pressed', String(btn.dataset.date === iso));
        });
        drawTimes(iso, slots);
        updateSummary();
    }

    function drawTimes(iso, slots) {
        if (timeInput.value && !slots.includes(timeInput.value)) timeInput.value = '';
        timesTitle.textContent = longDate(iso);
        const fragment = document.createDocumentFragment();
        slots.forEach((time) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'slot';
            button.textContent = time;
            button.setAttribute('aria-pressed', String(time === timeInput.value));
            button.addEventListener('click', () => {
                timeInput.value = time;
                timesEl.querySelectorAll('.slot').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
                updateSummary();
            });
            fragment.append(button);
        });
        timesEl.replaceChildren(fragment);
    }

    function clearSelection(message) {
        dateInput.value = '';
        timeInput.value = '';
        timesEl.replaceChildren();
        timesTitle.textContent = message;
        updateSummary();
    }

    function updateMeetingTypes() {
        if (!service || !meetingSelect) return;
        let types = {};
        try { types = JSON.parse(service.dataset.meetings || '{}'); } catch (e) { /* keep default */ }
        const current = meetingSelect.value || meetingSelect.dataset.selected;
        meetingSelect.replaceChildren(...Object.entries(types).map(([value, label]) => {
            const option = new Option(label, value);
            option.selected = value === current;
            return option;
        }));
    }

    function updateSummary() {
        summary.service.textContent = service ? service.dataset.name : '—';
        summary.price.textContent = service ? service.dataset.price : '—';
        summary.date.textContent = dateInput.value ? longDate(dateInput.value) : '—';
        summary.time.textContent = timeInput.value && service
            ? `${timeInput.value} · ${service.dataset.durationLabel}`
            : '—';
    }

    serviceInputs.forEach((input) => {
        input.addEventListener('change', () => {
            service = input;
            month = minMonth;
            autoAdvanced = false;
            clearSelection('Pick a highlighted day to see times.');
            updateMeetingTypes();
            renderCalendar();
        });
    });

    prevBtn.addEventListener('click', () => {
        if (month > minMonth) { month = addMonths(month, -1); renderCalendar(); }
    });
    nextBtn.addEventListener('click', () => {
        if (month < maxMonth) { month = addMonths(month, 1); renderCalendar(); }
    });

    form.addEventListener('submit', (event) => {
        let problem = null;
        if (!service) problem = serviceInputs[0];
        else if (!dateInput.value || !timeInput.value) problem = calendar;
        if (problem) {
            event.preventDefault();
            if (problem === calendar) timesTitle.textContent = 'Please pick a day and a time first.';
            problem.closest('fieldset').scrollIntoView({ behavior: 'smooth', block: 'start' });
            (problem === calendar ? (daysEl.querySelector('.day--available') || prevBtn) : problem).focus({ preventScroll: true });
            return;
        }
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending…';
    });

    // Re-enable the button if the page is restored from the back/forward cache.
    window.addEventListener('pageshow', () => {
        if (submitBtn.disabled) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Request booking';
        }
    });

    updateMeetingTypes();
    updateSummary();
    renderCalendar();
})();
