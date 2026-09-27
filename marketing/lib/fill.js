/* Fills [data-brand="path.to.value"] elements and [data-qr] boxes from window.BRAND. */
(function () {
    'use strict';
    var B = window.BRAND || {};
    var get = function (path) {
        return path.split('.').reduce(function (o, k) { return o == null ? undefined : o[k]; }, B);
    };

    document.querySelectorAll('[data-brand]').forEach(function (el) {
        var value = get(el.getAttribute('data-brand'));
        if (value === undefined || value === '') {
            if (el.hasAttribute('data-hide-empty')) el.closest('[data-row]') ? el.closest('[data-row]').remove() : el.remove();
            return;
        }
        el.textContent = Array.isArray(value) ? value.join(el.getAttribute('data-join') || ' · ') : value;
    });

    // Repeat a <template data-list="offer.items"> for each item.
    document.querySelectorAll('template[data-list]').forEach(function (tpl) {
        (get(tpl.getAttribute('data-list')) || []).forEach(function (item) {
            var node = tpl.content.firstElementChild.cloneNode(true);
            node.querySelector('[data-item]').textContent = item;
            tpl.parentNode.insertBefore(node, tpl);
        });
    });

    // Wordmark: "deea" + coloured dot.
    document.querySelectorAll('[data-wordmark]').forEach(function (el) {
        el.innerHTML = '';
        el.appendChild(document.createTextNode(String(B.name || '').toLowerCase()));
        var dot = document.createElement('i');
        dot.textContent = '.';
        el.appendChild(dot);
    });

    // QR codes as crisp SVG paths (vector in the PDF).
    document.querySelectorAll('[data-qr]').forEach(function (el) {
        if (typeof qrcode !== 'function' || !B.bookingUrl) return;
        var qr = qrcode(0, 'M');
        qr.addData(B.bookingUrl);
        qr.make();
        var n = qr.getModuleCount();
        var d = '';
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (qr.isDark(r, c)) d += 'M' + c + ' ' + r + 'h1v1h-1z';
            }
        }
        var color = el.getAttribute('data-qr') || '#16131f';
        el.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + n + ' ' + n + '" shape-rendering="crispEdges" role="img" aria-label="QR code: ' + B.bookingUrl + '"><path fill="' + color + '" d="' + d + '"/></svg>';
    });

    document.documentElement.setAttribute('data-ready', '1');
})();
