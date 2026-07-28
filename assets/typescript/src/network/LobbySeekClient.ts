import {SeekEvent} from '../models/seek';

/**
 * Live subscription to the public `lobby/seeks` Mercure topic
 * (04-matchmaking.md sec 5.2, 02-realtime.md sec 4.3). Deliberately
 * standalone rather than a generalisation of `MercureClient`: the full
 * multi-topic/`UserEventClient` architecture described in
 * `08-frontend.md` sec 6 belongs to later phases (`user/{uuid}` has no
 * subscriber yet); this is the one topic Phase 3 needs.
 */
export class LobbySeekClient {
    private eventSource: EventSource | null = null;

    /**
     * @param onEvent      fires for every `seek.added`/`seek.removed` frame
     * @param onReconnect  fires on initial connect and every automatic
     *                     browser reconnect (`onopen`) - the caller's cue to
     *                     refetch `GET /lobby/seeks` (sec 5.2 reconciliation)
     */
    connect(onEvent: (event: SeekEvent) => void, onReconnect: () => void): void {
        this.disconnect();

        const url = this.buildUrl();
        this.eventSource = new EventSource(url.toString());

        this.eventSource.onopen = () => onReconnect();

        this.eventSource.onmessage = (messageEvent: MessageEvent) => {
            try {
                const data = JSON.parse(messageEvent.data) as SeekEvent;
                onEvent(data);
            } catch (error) {
                console.error('Malformed lobby/seeks frame:', error);
            }
        };

        this.eventSource.onerror = (error) => {
            console.error('lobby/seeks Mercure connection error:', error);
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
        url.searchParams.append('topic', 'lobby/seeks');

        return url;
    }
}
