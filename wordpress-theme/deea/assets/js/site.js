/* Small progressive enhancements shared by the site and the admin. */
(() => {
    'use strict';

    // Mobile navigation
    const toggle = document.querySelector('[data-nav-toggle]');
    const nav = document.getElementById('site-nav');
    if (toggle && nav) {
        toggle.addEventListener('click', () => {
            const open = nav.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', String(open));
        });
        nav.addEventListener('click', (event) => {
            if (event.target.closest('a')) {
                nav.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // Header border once the page scrolls
    const header = document.querySelector('[data-header]');
    if (header) {
        const onScroll = () => header.classList.toggle('is-scrolled', window.scrollY > 8);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    // Ask before destructive actions: <form data-confirm="Are you sure?">
    document.addEventListener('submit', (event) => {
        const message = event.target.getAttribute && event.target.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    // Show/hide a block with a checkbox: <input data-toggle-target="#id">
    document.querySelectorAll('[data-toggle-target]').forEach((input) => {
        const target = document.querySelector(input.dataset.toggleTarget);
        if (!target) return;
        const sync = () => { target.hidden = !input.checked; };
        input.addEventListener('change', sync);
        sync();
    });

    // Move focus to error summaries so screen readers announce them
    const focusTarget = document.querySelector('[data-focus]');
    if (focusTarget) {
        focusTarget.focus();
    }
})();
