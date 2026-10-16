/** Name of the cookie holding the highest AI level a guest has beaten (read by the server, see `NextBot`). */
export const AI_BEATEN_COOKIE = 'keres_ai_beaten';

const ONE_YEAR_SECONDS = 365 * 24 * 60 * 60;

export function readCookie(name: string): string | null {
    for (const part of document.cookie.split(';')) {
        const [key, ...value] = part.trim().split('=');
        if (key === name) {
            return decodeURIComponent(value.join('='));
        }
    }

    return null;
}

/** A one-year, site-wide, lax cookie (the server reads it at account creation and for the lobby's next bot). */
export function writeCookie(name: string, value: string): void {
    const secure = 'https:' === window.location.protocol ? '; Secure' : '';
    document.cookie = `${name}=${encodeURIComponent(value)}; Path=/; Max-Age=${ONE_YEAR_SECONDS}; SameSite=Lax${secure}`;
}

/** A guest beat the AI at `level` without help (no undo, no hint): keeps the highest one in the cookie. */
export function recordGuestAiWin(level: number): void {
    const known = Number.parseInt(readCookie(AI_BEATEN_COOKIE) ?? '', 10);
    if (!Number.isFinite(known) || level > known) {
        writeCookie(AI_BEATEN_COOKIE, String(level));
    }
}
