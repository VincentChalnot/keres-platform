import {LobbyAPI, ApiError} from '../network/LobbyAPI';
import {LobbySeekClient} from '../network/LobbySeekClient';
import {CustomSeekInput, QuickPairPreset, SeekEvent, SeekListing, SeekSummary} from '../models/seek';
import {alertModal} from '../utils/modal';

/** 04-matchmaking.md sec 4.2: the client heartbeat period; also the pairing-retry granularity. */
const SEEK_HEARTBEAT_INTERVAL_MS = 10000;
/** sec 5.2: last-resort backstop while the lobby is visible, independent of SSE health. */
const RECONCILE_BACKSTOP_MS = 30000;

/**
 * `GET /lobby` orchestrator (04-matchmaking.md sec 5, 08-frontend.md sec
 * 5). Renders the live seek table from the server-supplied bootstrap, then
 * keeps it live from `lobby/seeks`; owns at most one of *my* seeks and
 * runs a heartbeat while it is open; navigates to `/play/{uuid}` the
 * moment any response - create, quick-pair, heartbeat or accept - reports
 * a match.
 */
export class LobbyController {
    private readonly seekClient = new LobbySeekClient();
    private readonly tableBody: HTMLElement;
    private readonly poolSizeEl: HTMLElement | null;

    private seeks = new Map<string, SeekSummary>();
    private mySeekUuid: string | null = null;
    private heartbeatTimer: ReturnType<typeof setInterval> | null = null;
    private backstopTimer: ReturnType<typeof setInterval> | null = null;
    private navigating = false;

    constructor(
        private readonly api: LobbyAPI,
        private readonly root: HTMLElement,
    ) {
        const tableBody = document.getElementById('lobby-seek-table-body');

        if (!tableBody) {
            throw new Error('LobbyController requires #lobby-seek-table-body');
        }

        this.tableBody = tableBody;
        this.poolSizeEl = document.getElementById('lobby-pool-size');
    }

    init(): void {
        this.hydrateFromBootstrap();
        this.wirePresetButtons();
        this.wireCustomSeekForm();
        this.seekClient.connect(
            (event) => this.handleSeekEvent(event),
            () => void this.refetch(),
        );
        this.backstopTimer = setInterval(() => void this.refetch(), RECONCILE_BACKSTOP_MS);
        document.addEventListener('visibilitychange', () => {
            if ('visible' === document.visibilityState) {
                void this.refetch();
            }
        });
        window.addEventListener('pagehide', () => this.cancelMySeekOnUnload());
    }

    dispose(): void {
        this.seekClient.disconnect();

        if (null !== this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
        }

        if (null !== this.backstopTimer) {
            clearInterval(this.backstopTimer);
        }
    }

    private hydrateFromBootstrap(): void {
        const bootstrapEl = document.getElementById('lobby-bootstrap');
        const raw = bootstrapEl?.textContent;

        if (!raw) {
            return;
        }

        try {
            const listing = JSON.parse(raw) as SeekListing;
            this.applyListing(listing);
        } catch (error) {
            console.error('Malformed #lobby-bootstrap payload:', error);
        }
    }

    private async refetch(): Promise<void> {
        try {
            const listing = await this.api.listSeeks();
            this.applyListing(listing);
        } catch (error) {
            console.error('Failed to refresh lobby seek list:', error);
        }
    }

    private applyListing(listing: SeekListing): void {
        this.seeks = new Map(listing.seeks.map((seek) => [seek.uuid, seek]));
        this.mySeekUuid = listing.seeks.find((seek) => true === seek.self)?.uuid ?? null;
        this.render(listing.poolSize);
        this.syncHeartbeat();
    }

    private handleSeekEvent(event: SeekEvent): void {
        if ('seek.added' === event.type && event.seek) {
            // Broadcasts never carry viewer-specific `self`/`playable`
            // (02-realtime.md sec 4.3) — `playable` arrives as null, so
            // buildRow would render no button at all and the seek would sit
            // unplayable until the 30s backstop refetch. Adopt the seek with
            // an optimistic playable flag: we know it's foreign (not ours),
            // and the accept endpoint enforces its own guards. The refetch
            // still corrects it if the viewer is blocked/out-of-range.
            if (event.seekUuid !== this.mySeekUuid) {
                const foreign: SeekSummary = {...event.seek, self: false, playable: true};
                this.seeks.set(event.seekUuid, foreign);
            }
        } else if ('seek.removed' === event.type) {
            this.seeks.delete(event.seekUuid);

            if (event.seekUuid === this.mySeekUuid) {
                if ('matched' === event.reason) {
                    // The broadcast carries no gameUuid by design (02-realtime.md
                    // sec 4.3); one more heartbeat on the now-consumed seek
                    // resolves it (HeartbeatSeekAction answers `matched` +
                    // `gameUuid` for a non-open seek too), instead of leaving
                    // the player stranded on the lobby with no way to find
                    // their game.
                    void this.beat();
                } else {
                    this.mySeekUuid = null;
                }
            }
        }

        this.render(event.poolSize);
        this.syncHeartbeat();

        if (this.seeks.size !== event.poolSize) {
            // sec 5.2: an event was missed - refetch rather than trust the running count.
            void this.refetch();
        }
    }

    private render(poolSize: number): void {
        if (this.poolSizeEl) {
            this.poolSizeEl.textContent = `(${poolSize} waiting)`;
        }

        this.tableBody.replaceChildren();

        const rows = [...this.seeks.values()].sort((a, b) => a.createdAt - b.createdAt);

        for (const seek of rows) {
            this.tableBody.appendChild(this.buildRow(seek));
        }
    }

    private buildRow(seek: SeekSummary): HTMLTableRowElement {
        const row = document.createElement('tr');
        row.dataset.seekUuid = seek.uuid;

        const player = document.createElement('td');
        player.textContent = seek.user.username;
        row.appendChild(player);

        const timeControl = document.createElement('td');
        timeControl.textContent = this.formatTimeControl(seek);
        row.appendChild(timeControl);

        const rated = document.createElement('td');
        rated.textContent = seek.rated ? 'Rated' : 'Casual';
        row.appendChild(rated);

        const color = document.createElement('td');
        color.textContent = seek.color.charAt(0).toUpperCase() + seek.color.slice(1);
        row.appendChild(color);

        const action = document.createElement('td');

        if (true === seek.self) {
            const cancelButton = document.createElement('button');
            cancelButton.type = 'button';
            cancelButton.className = 'button is-small is-rounded is-danger is-outlined';
            cancelButton.textContent = 'Cancel';
            cancelButton.addEventListener('click', () => void this.cancel(seek.uuid));
            action.appendChild(cancelButton);
        } else if (true === seek.playable) {
            const playButton = document.createElement('button');
            playButton.type = 'button';
            playButton.className = 'button is-small is-rounded is-primary';
            playButton.textContent = 'Play';
            playButton.addEventListener('click', () => void this.accept(seek.uuid));
            action.appendChild(playButton);
        } else if (null !== seek.playable) {
            const disabled = document.createElement('span');
            disabled.className = 'has-text-grey-light';
            disabled.textContent = 'Not available';
            action.appendChild(disabled);
        }

        row.appendChild(action);

        return row;
    }

    private formatTimeControl(seek: SeekSummary): string {
        const {timeControl} = seek;

        if ('unlimited' === timeControl.kind) {
            return 'Unlimited';
        }

        if ('correspondence' === timeControl.kind) {
            return `${timeControl.daysPerMove} day${1 === timeControl.daysPerMove ? '' : 's'}/move`;
        }

        return `${Math.round((timeControl.initialSeconds ?? 0) / 60)}+${timeControl.incrementSeconds ?? 0}`;
    }

    private wirePresetButtons(): void {
        for (const button of Array.from(this.root.querySelectorAll<HTMLButtonElement>('[data-preset]'))) {
            button.addEventListener('click', () => {
                const preset = button.dataset.preset as QuickPairPreset;
                void this.quickPair(preset);
            });
        }
    }

    private wireCustomSeekForm(): void {
        const form = document.getElementById('lobby-custom-seek-form') as HTMLFormElement | null;

        if (!form) {
            return;
        }

        const kindSelect = form.querySelector<HTMLSelectElement>('[name="kind"]');
        const toggleFieldVisibility = (): void => {
            const kind = kindSelect?.value ?? 'realtime';

            for (const el of Array.from(form.querySelectorAll<HTMLElement>('[data-field="realtime"]'))) {
                el.style.display = 'realtime' === kind ? '' : 'none';
            }

            for (const el of Array.from(form.querySelectorAll<HTMLElement>('[data-field="correspondence"]'))) {
                el.style.display = 'correspondence' === kind ? '' : 'none';
            }
        };

        kindSelect?.addEventListener('change', toggleFieldVisibility);
        toggleFieldVisibility();

        form.addEventListener('submit', (submitEvent) => {
            submitEvent.preventDefault();
            void this.submitCustomSeek(form);
        });
    }

    private async submitCustomSeek(form: HTMLFormElement): Promise<void> {
        const formData = new FormData(form);
        const kind = String(formData.get('kind') ?? 'realtime') as CustomSeekInput['kind'];

        const input: CustomSeekInput = {
            kind,
            initialSeconds: 'realtime' === kind ? Number(formData.get('initialSeconds')) : null,
            incrementSeconds: 'realtime' === kind ? Number(formData.get('incrementSeconds')) : null,
            daysPerMove: 'correspondence' === kind ? Number(formData.get('daysPerMove')) : null,
            rated: 'true' === formData.get('rated'),
            colorPreference: String(formData.get('colorPreference') ?? 'random') as CustomSeekInput['colorPreference'],
        };

        try {
            const result = await this.api.createSeek(input);
            this.mySeekUuid = result.seek.uuid;

            if (result.matched) {
                this.navigateToGame(result.matched.gameUuid);

                return;
            }

            this.seeks.set(result.seek.uuid, result.seek);
            this.render(this.seeks.size);
            this.syncHeartbeat();
        } catch (error) {
            this.reportError('Could not post seek', error);
        }
    }

    private async quickPair(preset: QuickPairPreset): Promise<void> {
        try {
            const result = await this.api.quickPair(preset);
            this.mySeekUuid = result.seek.uuid;

            if (result.matched) {
                this.navigateToGame(result.matched.gameUuid);

                return;
            }

            this.seeks.set(result.seek.uuid, result.seek);
            this.render(this.seeks.size);
            this.syncHeartbeat();
        } catch (error) {
            this.reportError('Could not quick-pair', error);
        }
    }

    private async accept(uuid: string): Promise<void> {
        try {
            const result = await this.api.acceptSeek(uuid);
            this.navigateToGame(result.gameUuid);
        } catch (error) {
            this.reportError('This seek is no longer available', error);
            void this.refetch();
        }
    }

    private async cancel(uuid: string): Promise<void> {
        try {
            await this.api.cancelSeek(uuid);
            this.seeks.delete(uuid);

            if (uuid === this.mySeekUuid) {
                this.mySeekUuid = null;
            }

            this.render(this.seeks.size);
            this.syncHeartbeat();
        } catch (error) {
            this.reportError('Could not cancel seek', error);
        }
    }

    /** Runs the widening-retry heartbeat (sec 3.1/4.2) exactly while I have an open seek. */
    private syncHeartbeat(): void {
        if (null === this.mySeekUuid) {
            if (null !== this.heartbeatTimer) {
                clearInterval(this.heartbeatTimer);
                this.heartbeatTimer = null;
            }

            return;
        }

        if (null !== this.heartbeatTimer) {
            return;
        }

        this.heartbeatTimer = setInterval(() => void this.beat(), SEEK_HEARTBEAT_INTERVAL_MS);
    }

    private async beat(): Promise<void> {
        if (null === this.mySeekUuid) {
            return;
        }

        try {
            const result = await this.api.heartbeatSeek(this.mySeekUuid);

            if ('matched' === result.status && result.gameUuid) {
                this.navigateToGame(result.gameUuid);
            }
        } catch (error) {
            if (error instanceof ApiError && ('seek_expired' === error.code || 'seek_unavailable' === error.code)) {
                this.mySeekUuid = null;
                this.syncHeartbeat();
                void this.refetch();

                return;
            }

            console.error('Heartbeat failed:', error);
        }
    }

    private cancelMySeekOnUnload(): void {
        if (null === this.mySeekUuid) {
            return;
        }

        navigator.sendBeacon(`/lobby/seeks/${encodeURIComponent(this.mySeekUuid)}/cancel`);
    }

    private navigateToGame(gameUuid: string): void {
        if (this.navigating) {
            return;
        }

        this.navigating = true;
        this.dispose();
        window.location.href = `/play/${gameUuid}`;
    }

    private reportError(context: string, error: unknown): void {
        const message = error instanceof ApiError ? error.code : String(error);
        console.error(`${context}: ${message}`);
        void alertModal(`${context}: ${message}`, 'Error');
    }
}
