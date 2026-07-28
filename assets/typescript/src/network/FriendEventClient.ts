import {UserEvent} from '../models/friends';

/**
 * Live subscription to the private `user/{uuid}` Mercure topic
 * (02-realtime.md sec 4.2, 3 - subscriber-JWT cookie already minted by
 * `MercureAuthorizationListener`, sent automatically as a same-origin
 * cookie). Deliberately standalone rather than the full multi-topic
 * `UserEventClient`/`AppShellController` architecture `08-frontend.md`
 * sec 6 describes for later phases - same scoping discipline as
 * `LobbySeekClient` for `lobby/seeks` in Phase 3. This is the one topic
 * Phase 4 needs (`friend_request`/`friend_accepted`); later phases widen
 * it or replace it outright.
 */
export class FriendEventClient {
    private eventSource: EventSource | null = null;

    constructor(private readonly userUuid: string) {
    }

    /**
     * @param onEvent      fires for every `user.event` frame; the caller
     *                     ignores any `event` value it doesn't handle
     * @param onReconnect  fires on initial connect and every automatic
     *                     browser reconnect (`onopen`)
     */
    connect(onEvent: (event: UserEvent) => void, onReconnect: () => void): void {
        this.disconnect();

        const url = this.buildUrl();
        this.eventSource = new EventSource(url.toString());

        this.eventSource.onopen = () => onReconnect();

        this.eventSource.onmessage = (messageEvent: MessageEvent) => {
            try {
                const data = JSON.parse(messageEvent.data) as UserEvent;
                onEvent(data);
            } catch (error) {
                console.error('Malformed user/{uuid} frame:', error);
            }
        };

        this.eventSource.onerror = (error) => {
            console.error('user/{uuid} Mercure connection error:', error);
            // EventSource retries automatically; onopen fires onReconnect() again.
        };
    }

    disconnect(): void {
        this.eventSource?.close();
        this.eventSource = null;
    }

    private buildUrl(): URL {
        const metaTag = document.querySelector('meta[name="mercure-url"]');
        const hubUrl = metaTag?.getAttribute('content')
            || `${window.location.protocol}//${window.location.host}/.well-known/mercure`;

        const url = new URL(hubUrl);
        url.searchParams.append('topic', `user/${this.userUuid}`);

        return url;
    }
}
