/*
 * Finds bookings in the JSON that the Treatwell Connect pages load.
 *
 * Treatwell doesn't publish the format its Connect pages use, so this doesn't depend on one
 * exact shape: it looks for lists of objects that have a start time plus a service or a client,
 * and reads each field by the names booking systems usually give it (startTime, employee,
 * offerName, customer, …). Stylist/service/client ids are resolved through lookups built from
 * the other lists the page loads (employees, menu, customers).
 *
 * Runs in the extension (window.TwExtract) and in Node for the tests (module.exports).
 */
(function (root) {
    'use strict';

    const norm = (k) => String(k).toLowerCase().replace(/[^a-z0-9]/g, '');
    const list = (...names) => names.map(norm);

    // Field names in order of preference (compared without case, "_" or "-").
    const F = {
        id: list('id', 'appointmentId', 'bookingId', 'reservationId', 'uuid', 'bookingReference', 'reference', 'orderReference', 'orderId'),
        start: list('startDateTime', 'startsAt', 'startAt', 'start', 'startTime', 'startTimeLocal', 'startLocal', 'startDate',
            'appointmentStart', 'dateTimeFrom', 'startTimestamp', 'from', 'begin', 'dateTime', 'datetime', 'time'),
        end: list('endDateTime', 'endsAt', 'endAt', 'end', 'endTime', 'endTimeLocal', 'endLocal', 'appointmentEnd',
            'dateTimeTo', 'endTimestamp', 'to', 'finish'),
        date: list('date', 'appointmentDate', 'bookingDate', 'serviceDate', 'visitDate', 'day'),
        duration: list('durationMinutes', 'durationMins', 'duration', 'lengthMinutes', 'length', 'minutes'),
        staff: list('employee', 'employeeName', 'employeeFullName', 'staff', 'staffName', 'staffMember', 'stylist', 'stylistName',
            'practitioner', 'therapist', 'professional', 'teamMember', 'resource', 'resourceName', 'worker'),
        staffId: list('employeeId', 'staffId', 'staffMemberId', 'stylistId', 'practitionerId', 'therapistId', 'professionalId',
            'teamMemberId', 'resourceId'),
        service: list('serviceName', 'service', 'services', 'treatmentName', 'treatment', 'treatments', 'offerName', 'offer', 'offers',
            'skuName', 'sku', 'skus', 'menuItemName', 'menuItem', 'menuItems', 'itemName', 'items', 'lineItems', 'serviceTitle',
            'treatmentTitle', 'productName', 'product'),
        serviceId: list('serviceId', 'treatmentId', 'offerId', 'skuId', 'menuItemId'),
        customer: list('customer', 'customerName', 'customerFullName', 'client', 'clientName', 'clientFullName', 'guest', 'guestName',
            'consumer', 'consumerName'),
        customerId: list('customerId', 'clientId', 'consumerId', 'guestId'),
        customerFirst: list('customerFirstName', 'clientFirstName', 'guestFirstName', 'consumerFirstName'),
        customerLast: list('customerLastName', 'customerSurname', 'clientLastName', 'guestLastName', 'consumerLastName'),
        phone: list('phone', 'phoneNumber', 'mobile', 'mobileNumber', 'mobilePhone', 'telephone', 'tel', 'contactNumber',
            'customerPhone', 'customerPhoneNumber', 'clientPhone', 'clientMobile'),
        email: list('email', 'emailAddress', 'customerEmail', 'clientEmail'),
        price: list('price', 'totalPrice', 'priceAmount', 'fullPrice', 'salePrice', 'amount', 'total', 'totalAmount', 'orderTotal',
            'amountDue', 'cost', 'priceIncludingVat'),
        status: list('status', 'appointmentStatus', 'bookingStatus', 'state'),
        notes: list('notes', 'note', 'notesForVenue', 'customerNotes', 'clientNotes', 'bookingNotes', 'comment', 'comments', 'remarks'),
        channel: list('channel', 'bookingChannel', 'bookingSource', 'source', 'bookingActor', 'bookedVia', 'origin', 'platform'),
        name: list('fullName', 'displayName', 'name', 'title', 'label'),
        firstName: list('firstName', 'givenName', 'first'),
        lastName: list('lastName', 'surname', 'familyName', 'last'),
    };

    // Lists under these keys are rotas, opening hours, blocked time… never bookings.
    const NOT_BOOKING_LIST = /shift|rota|schedule|workinghour|openinghour|hours|block|break|absence|holiday|timeoff|availab|slot|closure|closed|template|rule|review|rating|notification|message|sale|transaction|payment|invoice|receipt|voucher|report|statistic|stats/;
    // …and objects with one of these types are blocked time, not a client booking.
    const NOT_BOOKING_TYPE = /block|break|lunch|holiday|absence|shift|working|closed|closure|unavailable|timeoff|time_off|rota|schedule|personal/i;
    const LOOKUP_KEYS = {
        staff: /employee|staff|stylist|resource|team|practitioner|therapist|professional|worker/,
        service: /service|treatment|offer|sku|menu|product/,
        customer: /customer|client|consumer|guest/,
    };

    const RE_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;
    const RE_TIME = /^(\d{1,2}):(\d{2})(?::\d{2}(?:\.\d+)?)?$/;
    const RE_DATETIME = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{1,2}):(\d{2})(?::\d{2}(?:\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})?$/i;
    const pad = (n) => String(n).padStart(2, '0');
    const localDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const localTime = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
    const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

    /** Index of an object's keys by their normalised name. */
    function index(o) {
        const ix = new Map();
        for (const k of Object.keys(o)) if (!ix.has(norm(k))) ix.set(norm(k), k);
        return ix;
    }

    /** First present (non-empty) value among the given normalised field names. */
    function first(o, ix, names) {
        for (const n of names) {
            const k = ix.get(n);
            if (k !== undefined && o[k] !== null && o[k] !== undefined && o[k] !== '') return o[k];
        }
        return undefined;
    }
    const firstKey = (ix, names) => {
        for (const n of names) if (ix.has(n)) return ix.get(n);
        return undefined;
    };

    function text(v) {
        if (v === null || v === undefined) return '';
        if (typeof v === 'string') return v.trim();
        if (typeof v === 'number' || typeof v === 'boolean') return String(v);
        if (isObj(v)) {
            const ix = index(v);
            return text(first(v, ix, list('code', 'name', 'value', 'status', 'label', 'type')));
        }
        return '';
    }

    /** A date and/or time from a value: "2026-10-03T10:30:00Z", "2026-10-03", "10:30", epoch, {dateTime}… */
    function moment(value) {
        if (value === null || value === undefined || value === '') return null;
        if (typeof value === 'number' || (typeof value === 'string' && /^\d{10}(\d{3})?$/.test(value))) {
            const n = Number(value);
            if (n < 1e9) return null;
            const d = new Date(n < 1e11 ? n * 1000 : n);
            return isNaN(d.getTime()) ? null : { date: localDate(d), time: localTime(d) };
        }
        if (isObj(value)) {
            const ix = index(value);
            const whole = moment(first(value, ix, list('dateTime', 'datetime', 'iso', 'local', 'value', 'timestamp')));
            if (whole) return whole;
            const d = moment(first(value, ix, list('date', 'day'))), t = moment(first(value, ix, list('time', 'startTime')));
            return d || t ? { date: d && d.date, time: t && t.time } : null;
        }
        if (typeof value !== 'string') return null;
        const s = value.trim();
        let m = RE_DATETIME.exec(s);
        if (m) {
            if (Number(m[4]) > 23 || Number(m[5]) > 59) return null;
            if (m[6]) { // has a time zone: show it in this computer's (the salon's) local time
                const d = new Date(s.replace(' ', 'T'));
                return isNaN(d.getTime()) ? null : { date: localDate(d), time: localTime(d) };
            }
            return { date: `${m[1]}-${m[2]}-${m[3]}`, time: `${pad(m[4])}:${m[5]}` };
        }
        if ((m = RE_DATE.exec(s))) return { date: s };
        if ((m = RE_TIME.exec(s)) && Number(m[1]) < 24 && Number(m[2]) < 60) return { time: `${pad(m[1])}:${m[2]}` };
        return null;
    }

    /** Date + time from several fields, e.g. appointmentDate + startTime, or one startDateTime. */
    function when(o, ix, names, fallbackDate) {
        let date, time;
        for (const n of names) {
            const k = ix.get(n);
            if (k === undefined) continue;
            const m = moment(o[k]);
            if (!m) continue;
            if (m.time && !time) time = m.time;
            if (m.date && !date) date = m.date;
            if (date && time) break;
        }
        if (!date) {
            const d = moment(first(o, ix, F.date));
            date = (d && d.date) || fallbackDate;
        }
        return { date, time };
    }

    function addMinutes(time, minutes) {
        const [h, m] = time.split(':').map(Number);
        const total = h * 60 + m + Math.round(minutes);
        if (!(total >= 0 && total < 24 * 60)) return '';
        return `${pad(Math.floor(total / 60))}:${pad(total % 60)}`;
    }

    function nameOf(v) {
        if (v === null || v === undefined) return '';
        if (typeof v === 'string') return v.trim();
        if (Array.isArray(v)) return [...new Set(v.map(nameOf).filter(Boolean))].join(' + ');
        if (!isObj(v)) return '';
        const ix = index(v);
        const n = first(v, ix, F.name);
        if (typeof n === 'string' && n.trim()) return n.trim();
        if (isObj(n)) return nameOf(n);
        const full = [first(v, ix, F.firstName), first(v, ix, F.lastName)].filter(x => typeof x === 'string' && x.trim()).join(' ');
        if (full) return full.trim();
        // e.g. { sku: { name } } or { employee: { … } } one level down
        const inner = first(v, ix, list('service', 'treatment', 'offer', 'sku', 'menuItem', 'product', 'employee', 'person', 'details', 'profile'));
        return isObj(inner) ? nameOf(inner) : '';
    }

    function idOf(v) {
        if (typeof v === 'number') return String(v);
        if (typeof v === 'string' && v.trim() && v.length <= 80) return v.trim();
        if (isObj(v)) {
            const ix = index(v);
            const id = first(v, ix, F.id);
            return typeof id === 'number' || typeof id === 'string' ? String(id) : '';
        }
        return '';
    }

    function money(v, key) {
        if (typeof v === 'number') return /minor|pence|cents/i.test(key || '') ? v / 100 : v;
        if (typeof v === 'string') {
            const m = v.replace(/,/g, '').match(/-?\d+(?:\.\d+)?/);
            return m ? parseFloat(m[0]) : null;
        }
        if (isObj(v)) {
            const ix = index(v);
            const k = firstKey(ix, list('amountIncludingVat', 'gross', 'amount', 'value', 'total', 'price', 'amountMinor',
                'amountInMinorUnits', 'minorUnits', 'pence', 'cents'));
            return k === undefined ? null : money(v[k], k);
        }
        return null;
    }

    /** The service(s) of a booking: one name, or "A + B" with the prices added up. */
    function servicesOf(o, ix, lookups) {
        const v = first(o, ix, F.service);
        let name = '', price = null;
        if (Array.isArray(v)) {
            name = nameOf(v);
            let sum = 0, any = false;
            for (const x of v) {
                if (!isObj(x)) continue;
                const xi = index(x);
                const k = firstKey(xi, F.price);
                const p = k === undefined ? null : money(x[k], k);
                if (p !== null) { sum += p; any = true; }
            }
            if (any) price = sum;
            if (!name) name = v.map(x => lookupName(lookups.service, idOf(x))).filter(Boolean).join(' + ');
        } else if (isObj(v)) {
            name = nameOf(v) || lookupName(lookups.service, idOf(v));
            const xi = index(v);
            const k = firstKey(xi, F.price);
            if (k !== undefined) price = money(v[k], k);
        } else if (typeof v === 'string') {
            name = v.trim();
        }
        if (!name) name = lookupName(lookups.service, idOf(first(o, ix, F.serviceId)));
        return { name, price };
    }

    function lookupName(table, id) {
        return id && table && table[id] ? table[id].name : '';
    }

    function entity(o, ix, names, idNames, table) {
        const v = first(o, ix, names);
        if (typeof v === 'string' && !/^\d+$/.test(v)) return v.trim();
        if (isObj(v) || Array.isArray(v)) {
            const n = nameOf(v);
            if (n) return n;
        }
        const id = idOf(isObj(v) || typeof v === 'number' || typeof v === 'string' ? v : undefined) || idOf(first(o, ix, idNames));
        return lookupName(table, id);
    }

    function customerOf(o, ix, ctx, lookups) {
        let name = '', phone = '', email = '';
        const v = first(o, ix, F.customer);
        if (isObj(v)) {
            const ci = index(v);
            name = nameOf(v);
            phone = text(first(v, ci, F.phone));
            email = text(first(v, ci, F.email));
            if (!name) {
                const known = lookups.customer[idOf(v)];
                if (known) ({ name } = known);
            }
        } else if (typeof v === 'string') {
            name = v.trim();
        }
        if (!name) name = [first(o, ix, F.customerFirst), first(o, ix, F.customerLast)].filter(x => typeof x === 'string').join(' ').trim();
        const known = lookups.customer[idOf(first(o, ix, F.customerId))];
        if (known) {
            name = name || known.name;
            phone = phone || known.phone || '';
            email = email || known.email || '';
        }
        phone = phone || text(first(o, ix, F.phone));
        email = email || text(first(o, ix, F.email));
        return {
            name: name || (ctx.customer || ''),
            phone: phone || (ctx.phone || ''),
            email: email || (ctx.email || ''),
        };
    }

    function isBlockedTime(o, ix) {
        const t = first(o, ix, list('type', 'kind', 'eventType', 'appointmentType'));
        return typeof t === 'string' && NOT_BOOKING_TYPE.test(t);
    }

    /** Does this object look like one client booking? */
    function looksLikeBooking(o, ctx) {
        if (!isObj(o)) return false;
        const ix = index(o);
        if (isBlockedTime(o, ix)) return false;
        const w = when(o, ix, F.start, ctx.date);
        if (!w.date || !w.time) return false;
        return first(o, ix, F.service) !== undefined || first(o, ix, F.serviceId) !== undefined
            || first(o, ix, F.customer) !== undefined || first(o, ix, F.customerId) !== undefined
            || first(o, ix, F.customerFirst) !== undefined;
    }

    function hash(s) {
        let h = 5381;
        for (let i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) >>> 0;
        return h.toString(36);
    }

    function read(o, ctx, lookups) {
        const ix = index(o);
        const s = when(o, ix, F.start, ctx.date);
        let end = when(o, ix, F.end, s.date).time || '';
        if (!end) {
            const d = Number(first(o, ix, F.duration));
            if (d > 0 && d < 24 * 60) end = addMinutes(s.time, d);
        }
        const service = servicesOf(o, ix, lookups);
        const customer = customerOf(o, ix, ctx, lookups);
        const staff = entity(o, ix, F.staff, F.staffId, lookups.staff) || ctx.staff || '';
        const status = text(first(o, ix, F.status)) || ctx.status || '';
        const priceKey = firstKey(ix, F.price);
        let price = priceKey === undefined ? null : money(o[priceKey], priceKey);
        if (price === null) price = service.price;
        const flag = (names) => names.some(n => ix.has(norm(n)) && o[ix.get(norm(n))] === true);
        const booking = {
            id: '',
            date: s.date,
            start: s.time,
            end,
            customer: customer.name,
            phone: customer.phone,
            email: customer.email,
            service: service.name,
            staff,
            price: price === null || isNaN(price) ? null : Math.round(price * 100) / 100,
            status,
            cancelled: /cancel|declin|reject|delet|void|refund/i.test(status) || flag(['cancelled', 'canceled', 'isCancelled', 'isCanceled']),
            noShow: /no.?show/i.test(status) || flag(['noShow', 'isNoShow']),
            notes: text(first(o, ix, F.notes)) || ctx.notes || '',
            channel: text(first(o, ix, F.channel)) || ctx.channel || '',
        };
        const ownId = idOf(first(o, ix, F.id));
        booking.id = ownId
            ? (ctx.groupId && !/^\d{6,}$|^[0-9a-f-]{20,}$/i.test(ownId) ? `${ctx.groupId}-${ownId}` : ownId)
            : 'tw-' + hash([booking.date, booking.start, booking.staff, booking.customer, booking.service, ctx.groupId || ''].join('|'));
        return booking;
    }

    /** All bookings in a JSON response. */
    function findBookings(json, lookups) {
        lookups = lookups || { staff: {}, service: {}, customer: {} };
        const out = [];
        (function walk(node, ctx, depth, key) {
            if (depth > 9 || node === null || typeof node !== 'object') return;
            if (key && NOT_BOOKING_LIST.test(norm(key))) return;
            if (Array.isArray(node)) {
                for (const item of node) walk(item, ctx, depth + 1, key);
                return;
            }
            const ix = index(node);
            if (isBlockedTime(node, ix)) return;
            const here = Object.assign({}, ctx);
            const d = moment(first(node, ix, F.date));
            if (d && d.date) here.date = d.date;

            // An order or a booking holding several services, each with its own time: list the parts.
            const parts = Object.keys(node).filter(k => Array.isArray(node[k]) && !NOT_BOOKING_LIST.test(norm(k))
                && node[k].some(x => looksLikeBooking(x, here)));
            if (!parts.length && looksLikeBooking(node, ctx)) {
                out.push(read(node, ctx, lookups));
                return;
            }
            if (parts.length || first(node, ix, F.customer) !== undefined) {
                const c = customerOf(node, ix, here, lookups);
                if (c.name) here.customer = c.name;
                if (c.phone) here.phone = c.phone;
                if (c.email) here.email = c.email;
                const channel = text(first(node, ix, F.channel));
                if (channel) here.channel = channel;
                const notes = text(first(node, ix, F.notes));
                if (notes) here.notes = notes;
                if (parts.length) {
                    const status = text(first(node, ix, F.status));
                    if (status) here.status = status;
                    const id = idOf(first(node, ix, F.id));
                    if (id) here.groupId = id;
                }
            }
            for (const k of Object.keys(node)) {
                const v = node[k];
                if (v && typeof v === 'object') walk(v, RE_DATE.test(k) ? Object.assign({}, here, { date: k }) : here, depth + 1, k);
            }
        })(json, {}, 0, '');
        return out;
    }

    /** Remembers stylist, service and client names by id from any list the page loads. */
    function collectLookups(json, lookups) {
        (function walk(node, key, depth) {
            if (depth > 9 || node === null || typeof node !== 'object') return;
            if (Array.isArray(node)) {
                for (const item of node) walk(item, key, depth + 1);
                return;
            }
            const cat = Object.keys(LOOKUP_KEYS).find(c => LOOKUP_KEYS[c].test(norm(key)));
            if (cat) {
                const ix = index(node);
                const idNames = cat === 'staff' ? F.staffId : cat === 'service' ? F.serviceId : F.customerId;
                const id = idOf(first(node, ix, F.id)) || idOf(first(node, ix, idNames));
                const name = nameOf(node);
                if (id && name && !/^\d+$/.test(name)) {
                    lookups[cat][id] = Object.assign(lookups[cat][id] || {}, {
                        name,
                        phone: text(first(node, ix, F.phone)) || (lookups[cat][id] || {}).phone || '',
                        email: text(first(node, ix, F.email)) || (lookups[cat][id] || {}).email || '',
                    });
                }
            }
            for (const k of Object.keys(node)) {
                const v = node[k];
                // { "employees": { "12": { name } } } keeps the parent key for the ids below it
                if (v && typeof v === 'object') walk(v, /^\d+$/.test(k) ? key : k, depth + 1);
            }
        })(json, '', 0);
        return lookups;
    }

    /** One entry per booking: same id, or same day, time and client; newer data wins. */
    function dedupe(bookings) {
        const merge = (a, b) => {
            const m = Object.assign({}, a);
            for (const [k, v] of Object.entries(b)) if (v !== '' && v !== null && v !== undefined) m[k] = v;
            if (b.cancelled === false && a.cancelled && !/cancel/i.test(b.status || '')) m.cancelled = a.cancelled;
            return m;
        };
        const byId = new Map();
        for (const b of bookings) byId.set(b.id, byId.has(b.id) ? merge(byId.get(b.id), b) : b);
        const bySig = new Map();
        for (const b of byId.values()) {
            const sig = b.customer ? [b.date, b.start, norm(b.customer), norm(b.staff)].join('|') : 'id:' + b.id;
            bySig.set(sig, bySig.has(sig) ? merge(bySig.get(sig), b) : b);
        }
        return [...bySig.values()].sort((a, b) => (a.date + a.start + a.staff).localeCompare(b.date + b.start + b.staff));
    }

    /** All YYYY-MM-DD dates written in a URL or request body. */
    function datesIn(s) {
        let decoded = s || '';
        try { decoded = decodeURIComponent(decoded); } catch (e) { /* keep as is */ }
        return [...new Set(decoded.match(/\d{4}-\d{2}-\d{2}/g) || [])].sort();
    }

    /** Every day from the first to the last date in a URL (up to 6 weeks), e.g. a week view. */
    function daysCovered(url) {
        const dates = datesIn(url);
        if (!dates.length) return [];
        const out = [];
        const d = new Date(dates[0] + 'T12:00:00');
        const last = dates[dates.length - 1];
        for (let i = 0; i < 42; i++) {
            const day = localDate(d);
            out.push(day);
            if (day >= last) break;
            d.setDate(d.getDate() + 1);
        }
        return out;
    }

    /** The same request for another day: dates are swapped for `day` unless it's already in range. */
    function forDay(s, day) {
        const dates = datesIn(s);
        if (!dates.length || (day >= dates[0] && day <= dates[dates.length - 1])) return s;
        return s.replace(/\d{4}-\d{2}-\d{2}/g, day).replace(/\d{4}%2D\d{2}%2D\d{2}/gi, day);
    }

    /** Keeps the shape of a response for setting up the sync, without names, phones or other personal details. */
    const KEEP_TEXT_KEYS = /^(status|state|type|kind|source|channel|currency|bookingactor|origin|platform|category|colou?r|appointmentstatus|bookingstatus)$/;
    function redact(node, key, depth) {
        depth = depth || 0;
        if (depth > 12) return '…';
        if (Array.isArray(node)) {
            const out = node.slice(0, 3).map(x => redact(x, key, depth + 1));
            if (node.length > 3) out.push(`… ${node.length - 3} more`);
            return out;
        }
        if (isObj(node)) {
            const o = {};
            for (const k of Object.keys(node)) o[k] = redact(node[k], k, depth + 1);
            return o;
        }
        if (typeof node === 'string') {
            if (RE_DATETIME.test(node) || RE_DATE.test(node) || RE_TIME.test(node)) return node;
            if (/^[A-Z][A-Z0-9_]{1,30}$/.test(node)) return node;
            if (KEEP_TEXT_KEYS.test(norm(key || '')) && node.length <= 40 && !/@|\d{5,}/.test(node)) return node;
            return node.replace(/[^\s\d.,:;/()+\-_]/g, 'x').replace(/\d/g, '0').slice(0, 30);
        }
        if (typeof node === 'number' && /phone|mobile|tel/.test(norm(key || ''))) return 0;
        return node;
    }

    function redactUrl(url) {
        try {
            const u = new URL(url);
            const params = [...u.searchParams.entries()].map(([k, v]) => {
                const keep = /^\d{4}-\d{2}-\d{2}/.test(v) || /^\d{1,5}$/.test(v) || /^[a-z_,-]{1,40}$/i.test(v);
                return `${k}=${keep ? v : '…'}`;
            });
            return u.origin + u.pathname + (params.length ? '?' + params.join('&') : '');
        } catch (e) {
            return '(unreadable address)';
        }
    }

    const api = { findBookings, collectLookups, dedupe, datesIn, daysCovered, forDay, redact, redactUrl, moment, localDate };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.TwExtract = api;
})(typeof self !== 'undefined' ? self : this);
