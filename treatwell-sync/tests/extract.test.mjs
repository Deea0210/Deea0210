/*
 * Tests the booking reader on several JSON layouts a salon calendar can use, and on
 * answers that must NOT be read as bookings (rotas, blocked time, reviews, the menu).
 *   node tests/extract.test.mjs
 */
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
const require = createRequire(import.meta.url);
const X = require('../extension/extract.js');

const D = '2026-10-03';
let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log('✓', name);
    } catch (e) {
        console.log('✗', name);
        throw e;
    }
}
const read = (json, lookups) => X.dedupe(X.findBookings(json, lookups || { staff: {}, service: {}, customer: {} }));

test('calendar with separate date/time fields, ids resolved from the employees list', () => {
    const lookups = { staff: {}, service: {}, customer: {} };
    X.collectLookups({ employees: [{ id: 11, name: 'Renato' }, { id: 12, firstName: 'Mohammad', lastName: 'A' }] }, lookups);
    const json = {
        appointments: [
            { id: 501, appointmentDate: D, startTime: '10:30:00', endTime: '11:15:00', employeeId: 11, offerName: 'Skin Fade',
              customer: { firstName: 'John', lastName: 'Smith', phone: '07700 900123' }, status: 'CONFIRMED', amount: 15.75 },
            { id: 502, appointmentDate: D, startTime: '09:00', durationMinutes: 30, employeeId: 12, offerName: 'Beard Trim',
              customer: { name: 'Ali K' }, status: 'CANCELLED', amount: '£8.00' },
        ],
        workingHours: [{ employeeId: 11, date: D, startTime: '09:00', endTime: '18:00' }],
        blockedTimes: [{ employeeId: 12, date: D, startTime: '13:00', endTime: '13:30', title: 'Lunch' }],
    };
    const b = read(json, lookups);
    assert.equal(b.length, 2);
    assert.deepEqual(b.map(x => [x.start, x.end, x.staff, x.service, x.customer, x.price, x.cancelled]), [
        ['09:00', '09:30', 'Mohammad A', 'Beard Trim', 'Ali K', 8, true],
        ['10:30', '11:15', 'Renato', 'Skin Fade', 'John Smith', 15.75, false],
    ]);
    assert.equal(b[1].phone, '07700 900123');
    assert.equal(b[1].id, '501');
});

test('ISO date-times with time zone, nested objects, several services', () => {
    const json = { data: { items: [
        { uuid: 'a1b2c3d4-0000-4000-8000-000000000001', startsAt: `${D}T09:15:00Z`, endsAt: `${D}T10:00:00Z`,
          employee: { id: 3, displayName: 'Sofia' }, client: { firstName: 'Emma', lastName: 'Jones', mobile: '+447700900456', email: 'e@example.com' },
          services: [{ name: 'Wash', price: 5 }, { name: 'Clipper Cut', price: { amountMinor: 1225, currency: 'GBP' } }],
          bookingChannel: 'MARKETPLACE', notes: 'Prefers scissors on top' },
    ] } };
    const [b] = read(json);
    // 09:15Z is 10:15 in London in October (BST) when the tests run in Europe/London
    assert.equal(b.start, X.moment(`${D}T09:15:00Z`).time);
    assert.equal(b.service, 'Wash + Clipper Cut');
    assert.equal(b.price, 17.25);
    assert.equal(b.customer, 'Emma Jones');
    assert.equal(b.email, 'e@example.com');
    assert.equal(b.staff, 'Sofia');
    assert.equal(b.channel, 'MARKETPLACE');
    assert.equal(b.notes, 'Prefers scissors on top');
});

test('bookings grouped under day keys, plain names', () => {
    const json = { days: { [D]: [{ time: '14:00', staff: 'Adam', treatment: 'Hot Towel', customerName: 'Tom' }],
                           '2026-10-04': [{ time: '15:00', staff: 'Adam', treatment: 'Wash', customerName: 'Sam' }] } };
    const b = read(json);
    assert.deepEqual(b.map(x => [x.date, x.start, x.customer]), [[D, '14:00', 'Tom'], ['2026-10-04', '15:00', 'Sam']]);
});

test('an order holding several appointments: one row each, client from the order', () => {
    const json = { orders: [{ orderReference: 'TW-889', customer: { name: 'Lucy Hall', phone: '07123' }, channel: 'ONLINE', appointments: [
        { id: 1, start: Date.parse(`${D}T11:00:00`), end: Date.parse(`${D}T11:30:00`), employee: 'Renato', sku: { name: 'Wash' } },
        { id: 2, start: Date.parse(`${D}T11:30:00`), end: Date.parse(`${D}T12:00:00`), employee: 'Renato', sku: { name: 'Skin Fade' } },
    ] }] };
    const b = read(json);
    assert.equal(b.length, 2);
    assert.deepEqual(b.map(x => [x.start, x.service, x.customer, x.phone, x.channel, x.id]), [
        ['11:00', 'Wash', 'Lucy Hall', '07123', 'ONLINE', 'TW-889-1'],
        ['11:30', 'Skin Fade', 'Lucy Hall', '07123', 'ONLINE', 'TW-889-2'],
    ]);
});

test('GraphQL answer with edges/nodes', () => {
    const json = { data: { venue: { calendar: { edges: [
        { node: { id: 'QXBwOjE=', startDateTime: `${D}T16:00:00`, staffMember: { name: 'Ioana' }, treatment: { title: 'Eyebrow Threading' },
                  consumer: { firstName: 'Mia' }, state: 'BOOKED', price: { value: '9.00' } } },
    ] } } } };
    const [b] = read(json);
    assert.deepEqual([b.start, b.staff, b.service, b.customer, b.price, b.status], ['16:00', 'Ioana', 'Eyebrow Threading', 'Mia', 9, 'BOOKED']);
});

test('things that are not bookings are ignored', () => {
    const noise = {
        venue: { name: 'Barber Shop', openingHours: [{ day: 'MONDAY', from: '09:00', to: '18:00' }] },
        menu: [{ id: 1, name: 'Skin Fade', price: 15.75, duration: 30 }],
        reviews: [{ date: D, time: '10:00', customer: { name: 'X' }, service: 'Skin Fade', rating: 5 }],
        employees: [{ id: 11, name: 'Renato', shifts: [{ date: D, startTime: '09:00', endTime: '17:00' }] }],
        events: [{ type: 'BLOCKED_TIME', date: D, startTime: '12:00', endTime: '13:00', employee: 'Renato', service: 'Lunch' }],
        user: { firstName: 'Reception', lastLogin: `${D}T08:00:00Z` },
    };
    assert.equal(read(noise).length, 0);
});

test('same booking seen twice (list + detail) is shown once, newer data wins', () => {
    const list = { appointments: [{ id: 7, appointmentDate: D, startTime: '12:00', serviceName: 'Wash', customerName: 'Ben', status: 'CONFIRMED' }] };
    const detail = { appointment: { id: 7, appointmentDate: D, startTime: '12:00', serviceName: 'Wash', customerName: 'Ben', customerPhone: '0700', status: 'CANCELLED' } };
    const b = X.dedupe([...X.findBookings(list), ...X.findBookings(detail)]);
    assert.equal(b.length, 1);
    assert.equal(b[0].phone, '0700');
    assert.equal(b[0].cancelled, true);
});

test('repeating the calendar request for today', () => {
    assert.equal(X.forDay('https://x/api/cal?date-from=2026-10-01&date-to=2026-10-01', D), 'https://x/api/cal?date-from=2026-10-03&date-to=2026-10-03');
    assert.equal(X.forDay('https://x/api/cal?from=2026-09-29&to=2026-10-05', D), 'https://x/api/cal?from=2026-09-29&to=2026-10-05');
    assert.equal(X.forDay('https://x/api/cal/2026-10-02', D), 'https://x/api/cal/2026-10-03');
    assert.equal(X.forDay('{"variables":{"date":"2026-10-02"}}', D), '{"variables":{"date":"2026-10-03"}}');
    assert.deepEqual(X.daysCovered('https://x?from=2026-09-30&to=2026-10-02'), ['2026-09-30', '2026-10-01', '2026-10-02']);
});

test('setup file keeps the shape but no personal details', () => {
    const r = X.redact({ customer: { name: 'John Smith', phone: '07700 900123', email: 'john@example.com' }, status: 'CONFIRMED',
        startTime: '10:30', price: 15.75, notes: 'Allergic to dye' });
    const s = JSON.stringify(r);
    for (const secret of ['John', 'Smith', '900123', 'example.com', 'Allergic']) assert.ok(!s.includes(secret), secret);
    assert.equal(r.status, 'CONFIRMED');
    assert.equal(r.startTime, '10:30');
    assert.equal(r.price, 15.75);
    assert.equal(X.redactUrl('https://connect.treatwell.co.uk/api/x?date=2026-10-03&token=abc123secretvalue99'),
        'https://connect.treatwell.co.uk/api/x?date=2026-10-03&token=…');
});

console.log(`\n${passed} tests passed`);
