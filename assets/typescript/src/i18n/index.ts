/**
 * Front-end translations. The server renders the catalogue of the current
 * locale into the page (`<script type="application/json" id="app-i18n">`,
 * built by `App\Service\Locale\FrontendCatalogue` from the `frontend` /
 * `frontend_<area>` translation domains - the same YAML files PHP reads, so
 * there is no second copy). Strings are looked up by key:
 *
 *   t('common.cancel')
 *   t('play.timer.minutes_left', {count: 3})   // ICU subset, see messageFormat.ts
 *
 * A missing key renders as the key itself (and warns once in the console), so
 * a gap is visible instead of blank. The key set is checked in CI by
 * `tests/Unit/Service/Translation/FrontendKeysTest.php`.
 */
import {formatMessage, type MessageParams} from './messageFormat';

export type {MessageParams};

interface Catalogue {
    locale: string;
    messages: Record<string, string>;
}

let catalogue: Catalogue | null = null;
const warned = new Set<string>();

function loadCatalogue(): Catalogue {
    if (catalogue !== null) {
        return catalogue;
    }

    const fallback: Catalogue = {locale: document.documentElement.lang || 'en', messages: {}};
    const element = document.getElementById('app-i18n');

    try {
        const data = JSON.parse(element?.textContent ?? '') as Partial<Catalogue>;
        catalogue = {locale: data.locale ?? fallback.locale, messages: data.messages ?? {}};
    } catch {
        console.error('i18n: the #app-i18n catalogue is missing or invalid; showing translation keys.');
        catalogue = fallback;
    }

    return catalogue;
}

/** The locale the page is rendered in (`en`, `fr`, ...), also `<html lang>`. */
export function locale(): string {
    return loadCatalogue().locale;
}

/** Translates a front-end key for the current locale. */
export function t(key: string, params: MessageParams = {}): string {
    const {locale: current, messages} = loadCatalogue();
    const message = messages[key];

    if (message === undefined) {
        if (!warned.has(key)) {
            warned.add(key);
            console.warn(`i18n: missing translation key "${key}" (${current})`);
        }

        return key;
    }

    try {
        return formatMessage(message, params, current);
    } catch (error) {
        console.error(`i18n: cannot format "${key}"`, error);

        return key;
    }
}

/** True when the key exists, for the rare caller with an optional label (e.g. a server-sent code). */
export function hasTranslation(key: string): boolean {
    return key in loadCatalogue().messages;
}

/** A date and/or time in the current locale (default: short date + short time). */
export function formatDateTime(value: Date | string | number, options: Intl.DateTimeFormatOptions = {dateStyle: 'medium', timeStyle: 'short'}): string {
    return new Intl.DateTimeFormat(locale(), options).format(new Date(value));
}

/** A date in the current locale. */
export function formatDate(value: Date | string | number, options: Intl.DateTimeFormatOptions = {dateStyle: 'medium'}): string {
    return new Intl.DateTimeFormat(locale(), options).format(new Date(value));
}

/** A number in the current locale. */
export function formatNumber(value: number, options?: Intl.NumberFormatOptions): string {
    return new Intl.NumberFormat(locale(), options).format(value);
}

/** "3 minutes ago" / "il y a 3 minutes" for a past (or future) instant, relative to `now`. */
export function formatRelativeTime(value: Date | string | number, now: Date | number = Date.now()): string {
    const seconds = Math.round((new Date(value).getTime() - new Date(now).getTime()) / 1000);
    const format = new Intl.RelativeTimeFormat(locale(), {numeric: 'auto'});
    const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [
        ['year', 31536000],
        ['month', 2592000],
        ['week', 604800],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return format.format(seconds, 'second');
}
