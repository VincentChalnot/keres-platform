import {
    CustomSeekInput,
    HeartbeatResult,
    QuickPairPreset,
    SeekCreateResult,
    SeekListing,
} from '../models/seek';
import {FriendRequestResult, FriendsListResult, PlayerSearchResult} from '../models/friends';

/** Thrown on any `{"error": {...}}` envelope or transport failure - `code` is the only field a caller may branch on (09-api-reference.md sec 2.2/9). */
export class ApiError extends Error {
    constructor(
        public readonly code: string,
        public readonly status: number,
        public readonly details: Record<string, unknown> | null,
    ) {
        super(code);
        this.name = 'ApiError';
    }
}

/**
 * Dev-only testing aid mirroring `App\EventListener\Dev\DevUserSwitchListener`:
 * when the current page URL carries `?_as=<email>`, every AJAX call made from
 * that page forwards the same param so the acting identity stays consistent
 * with the page's own per-request override, even though a shared Playwright
 * cookie jar means the *real* session may belong to a different dev user.
 * A no-op whenever `_as` is absent from the page URL (i.e. always in prod).
 */
function withDevAs(path: string): string {
    const as = new URLSearchParams(window.location.search).get('_as');

    if (null === as) {
        return path;
    }

    return `${path}${path.includes('?') ? '&' : '?'}_as=${encodeURIComponent(as)}`;
}

async function request<T>(path: string, method: 'GET' | 'POST' = 'GET', body?: unknown, signal?: AbortSignal): Promise<T> {
    let response: Response;

    try {
        response = await fetch(withDevAs(path), {
            method,
            headers: undefined !== body ? {'Content-Type': 'application/json'} : {},
            body: undefined !== body ? JSON.stringify(body) : undefined,
            credentials: 'same-origin',
            signal,
        });
    } catch (error) {
        if (error instanceof DOMException && 'AbortError' === error.name) {
            throw error; // let the caller's catch distinguish a deliberate cancel from a real failure
        }

        throw new ApiError('network_error', 0, {message: String(error)});
    }

    let json: unknown;

    try {
        json = await response.json();
    } catch {
        throw new ApiError('malformed_json', response.status, null);
    }

    if (!response.ok) {
        let code = 'internal_error';
        let details: Record<string, unknown> | null = null;

        if (json !== null && typeof json === 'object' && 'error' in json) {
            const rawError = json.error;

            if (rawError !== null && typeof rawError === 'object' && 'code' in rawError && 'string' === typeof rawError.code) {
                code = rawError.code;
                details = 'details' in rawError && rawError.details !== null && 'object' === typeof rawError.details
                    ? rawError.details as Record<string, unknown>
                    : null;
            }
        }

        throw new ApiError(code, response.status, details);
    }

    if (null === json || 'object' !== typeof json || !('data' in json)) {
        throw new ApiError('malformed_json', response.status, null);
    }

    return json.data as T;
}

/** `04-matchmaking.md` sec 5, `09-api-reference.md` sec 4.1 - the Phase 3 subset of `08-frontend.md` sec 5.1's full `LobbyAPI`. */
export class LobbyAPI {
    listSeeks(): Promise<SeekListing> {
        return request<SeekListing>('/lobby/seeks');
    }

    createSeek(input: CustomSeekInput): Promise<SeekCreateResult> {
        return request<SeekCreateResult>('/lobby/seeks', 'POST', input);
    }

    quickPair(preset: QuickPairPreset): Promise<SeekCreateResult> {
        return request<SeekCreateResult>('/lobby/seeks/quick', 'POST', {preset});
    }

    heartbeatSeek(uuid: string): Promise<HeartbeatResult> {
        return request<HeartbeatResult>(`/lobby/seeks/${encodeURIComponent(uuid)}/heartbeat`, 'POST');
    }

    cancelSeek(uuid: string): Promise<{seek: {uuid: string; status: string}}> {
        return request<{seek: {uuid: string; status: string}}>(`/lobby/seeks/${encodeURIComponent(uuid)}/cancel`, 'POST');
    }

    acceptSeek(uuid: string): Promise<{gameUuid: string}> {
        return request<{gameUuid: string}>(`/lobby/seeks/${encodeURIComponent(uuid)}/accept`, 'POST');
    }

    // 05-social.md sec 3/4, 09-api-reference.md sec 4.3.
    listFriends(): Promise<FriendsListResult> {
        return request<FriendsListResult>('/friends/list');
    }

    requestFriend(username: string): Promise<FriendRequestResult> {
        return request<FriendRequestResult>('/friends/request', 'POST', {username});
    }

    acceptFriend(username: string): Promise<void> {
        return request<void>(`/friends/${encodeURIComponent(username)}/accept`, 'POST');
    }

    declineFriend(username: string): Promise<void> {
        return request<void>(`/friends/${encodeURIComponent(username)}/decline`, 'POST');
    }

    removeFriend(username: string): Promise<void> {
        return request<void>(`/friends/${encodeURIComponent(username)}/remove`, 'POST');
    }

    blockUser(username: string): Promise<void> {
        return request<void>('/friends/block', 'POST', {username});
    }

    unblockUser(username: string): Promise<void> {
        return request<void>(`/friends/${encodeURIComponent(username)}/unblock`, 'POST');
    }

    searchPlayers(q: string, signal?: AbortSignal): Promise<PlayerSearchResult[]> {
        return request<{players: PlayerSearchResult[]}>(`/players/search?q=${encodeURIComponent(q)}`, 'GET', undefined, signal)
            .then((result) => result.players);
    }
}
