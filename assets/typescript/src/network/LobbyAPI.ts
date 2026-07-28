import {
    CustomSeekInput,
    HeartbeatResult,
    QuickPairPreset,
    SeekCreateResult,
    SeekListing,
} from '../models/seek';

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

async function request<T>(path: string, method: 'GET' | 'POST' = 'GET', body?: unknown): Promise<T> {
    let response: Response;

    try {
        response = await fetch(path, {
            method,
            headers: undefined !== body ? {'Content-Type': 'application/json'} : {},
            body: undefined !== body ? JSON.stringify(body) : undefined,
            credentials: 'same-origin',
        });
    } catch (error) {
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
}
