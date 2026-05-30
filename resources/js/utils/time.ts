/**
 * Human, locale-aware time formatting. `relativeTime` → "2 hours ago" /
 * "just now"; `absoluteTime` → the full localized timestamp (good for a title
 * tooltip on the relative label).
 */
const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31536000],
    ['month', 2592000],
    ['week', 604800],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
    ['second', 1],
];

export function relativeTime(iso: string | null, locale = 'en'): string {
    if (!iso) return '';
    const sec = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    if (Math.abs(sec) < 45) return new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(0, 'second');

    const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    for (const [unit, secs] of UNITS) {
        if (Math.abs(sec) >= secs || unit === 'second') {
            return rtf.format(Math.round(sec / secs), unit);
        }
    }
    return '';
}

export function absoluteTime(iso: string | null, locale = 'en'): string {
    return iso ? new Date(iso).toLocaleString(locale) : '';
}
