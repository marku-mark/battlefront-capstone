export const BUSINESS_TIMEZONE = 'Asia/Manila';

function parseTimestamp(value) {
    // Database timestamps without an offset are stored in UTC.
    if (
        typeof value === 'string' &&
        /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(value)
    ) {
        return new Date(value.replace(' ', 'T') + 'Z');
    }

    return new Date(value);
}

export function formatRelativeTime(value, now = Date.now()) {
    if (!value) {
        return 'Not available';
    }

    const seconds = (parseTimestamp(value).getTime() - Number(now)) / 1000;

    if (!Number.isFinite(seconds)) {
        return 'Not available';
    }

    if (Math.abs(seconds) < 1) {
        return 'just now';
    }

    const units = [
        ['year', 365 * 24 * 60 * 60],
        ['month', 30 * 24 * 60 * 60],
        ['week', 7 * 24 * 60 * 60],
        ['day', 24 * 60 * 60],
        ['hour', 60 * 60],
        ['minute', 60],
        ['second', 1],
    ];
    const [unit, duration] = units.find(
        ([, size]) => Math.abs(seconds) >= size,
    );

    return new Intl.RelativeTimeFormat('en', { numeric: 'always' }).format(
        Math.trunc(seconds / duration),
        unit,
    );
}

export function formatDateTime(
    value,
    dateStyle = 'medium',
    timezone = BUSINESS_TIMEZONE,
) {
    return new Intl.DateTimeFormat('en-PH', {
        dateStyle,
        timeStyle: 'short',
        timeZone: timezone,
    }).format(parseTimestamp(value));
}

export function formatTimestampDate(
    value,
    dateStyle = 'medium',
    timezone = BUSINESS_TIMEZONE,
) {
    return new Intl.DateTimeFormat('en-PH', {
        dateStyle,
        timeZone: timezone,
    }).format(parseTimestamp(value));
}

export function formatCalendarDate(value, dateStyle = 'medium') {
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));

    return new Intl.DateTimeFormat('en-PH', {
        dateStyle,
        timeZone: 'UTC',
    }).format(date);
}
