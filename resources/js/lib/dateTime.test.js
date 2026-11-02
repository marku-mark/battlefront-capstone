import assert from 'node:assert/strict';
import test from 'node:test';
import {
    BUSINESS_TIMEZONE,
    formatCalendarDate,
    formatRelativeTime,
    formatDateTime,
    formatTimestampDate,
} from './dateTime.js';

test('timestamps always display in Manila time across the UTC date boundary', () => {
    assert.equal(BUSINESS_TIMEZONE, 'Asia/Manila');
    assert.equal(
        formatDateTime('2026-09-09T16:00:00.000000Z'),
        'Sep 10, 2026, 12:00 AM',
    );
});

test('timestamp dates use Manila time even when only the date is shown', () => {
    assert.equal(
        formatTimestampDate('2026-09-09T16:00:00.000000Z', 'long'),
        'September 10, 2026',
    );
});

test('calendar dates retain their provided day without timezone conversion', () => {
    assert.equal(formatCalendarDate('2026-09-10'), 'Sep 10, 2026');
});

test('database timestamps without offsets display as UTC instants in Philippine time', () => {
    assert.equal(
        formatDateTime('2026-09-09 16:00:00'),
        'Sep 10, 2026, 12:00 AM',
    );
    assert.equal(formatTimestampDate('2026-09-09T16:00:00'), 'Sep 10, 2026');
    assert.equal(
        formatDateTime('2026-09-10T00:00:00+08:00'),
        'Sep 10, 2026, 12:00 AM',
    );
});

test('relative timestamps show elapsed units with singular and plural labels', () => {
    const now = Date.parse('2026-11-02T08:00:00Z');
    const cases = [
        [0, 'just now'],
        [1, '1 second ago'],
        [45, '45 seconds ago'],
        [59, '59 seconds ago'],
        [60, '1 minute ago'],
        [3599, '59 minutes ago'],
        [3600, '1 hour ago'],
        [7200, '2 hours ago'],
        [86399, '23 hours ago'],
        [86400, '1 day ago'],
        [172800, '2 days ago'],
        [604800, '1 week ago'],
        [1209600, '2 weeks ago'],
        [2592000, '1 month ago'],
        [5184000, '2 months ago'],
        [31536000, '1 year ago'],
        [63072000, '2 years ago'],
    ];

    for (const [seconds, expected] of cases) {
        assert.equal(
            formatRelativeTime(new Date(now - seconds * 1000), now),
            expected,
        );
    }
});

test('relative timestamps interpret UTC database values and explicit offsets consistently', () => {
    const now = Date.parse('2026-11-02T08:01:00Z');

    for (const value of [
        '2026-11-02 08:00:00',
        '2026-11-02T08:00:00',
        '2026-11-02T16:00:00+08:00',
    ]) {
        assert.equal(formatRelativeTime(value, now), '1 minute ago');
    }
});

test('relative timestamps handle future, missing and invalid values', () => {
    const now = Date.parse('2026-11-02T08:00:00Z');

    assert.equal(
        formatRelativeTime('2026-11-02T08:02:00Z', now),
        'in 2 minutes',
    );
    for (const value of [null, undefined, '', 'invalid']) {
        assert.equal(formatRelativeTime(value, now), 'Not available');
    }
});
