import {ApiError, LobbyAPI} from '../network/LobbyAPI';
import {FriendEventClient} from '../network/FriendEventClient';
import {FriendRequestRow, FriendRow, FriendsListResult, PlayerSearchResult, UserEvent} from '../models/friends';

const SEARCH_DEBOUNCE_MS = 250;
const MIN_SEARCH_LENGTH = 3;

/**
 * `GET /friends` orchestrator (05-social.md sec 3/4, 08-frontend.md sec
 * 5). Renders the four lists from the server-supplied bootstrap, then
 * keeps them live from the private `user/{uuid}` topic; every mutation
 * (accept/decline/remove/block/unblock/request) just re-fetches the whole
 * list afterward - this page is low-traffic, surgical per-row patching
 * isn't worth the complexity it would add.
 */
export class FriendsController {
    private readonly eventClient: FriendEventClient;
    private readonly incomingList: HTMLElement;
    private readonly outgoingList: HTMLElement;
    private readonly friendsList: HTMLElement;
    private readonly blockedList: HTMLElement;
    private readonly searchInput: HTMLInputElement;
    private readonly searchResults: HTMLElement;

    private searchTimer: ReturnType<typeof setTimeout> | null = null;
    private searchAbort: AbortController | null = null;

    constructor(
        private readonly api: LobbyAPI,
        private readonly root: HTMLElement,
    ) {
        this.incomingList = this.required('friends-incoming-list');
        this.outgoingList = this.required('friends-outgoing-list');
        this.friendsList = this.required('friends-accepted-list');
        this.blockedList = this.required('friends-blocked-list');
        this.searchInput = this.required('friends-search-input') as HTMLInputElement;
        this.searchResults = this.required('friends-search-results');
        this.eventClient = new FriendEventClient(root.dataset.userUuid ?? '');
    }

    init(): void {
        this.hydrateFromBootstrap();
        this.wireActionDelegation();
        this.wireSearch();
        this.eventClient.connect(
            (event) => this.handleUserEvent(event),
            () => { /* nothing to reconcile on (re)connect beyond what SSE frames already cover */ },
        );
    }

    dispose(): void {
        this.eventClient.disconnect();

        if (null !== this.searchTimer) {
            clearTimeout(this.searchTimer);
        }

        this.searchAbort?.abort();
    }

    private required(id: string): HTMLElement {
        const el = document.getElementById(id);

        if (!el) {
            throw new Error(`FriendsController requires #${id}`);
        }

        return el;
    }

    private hydrateFromBootstrap(): void {
        const bootstrapEl = document.getElementById('friends-bootstrap');
        const raw = bootstrapEl?.textContent;

        if (!raw) {
            return;
        }

        try {
            const listing = JSON.parse(raw) as FriendsListResult;
            this.render(listing);
        } catch (error) {
            console.error('Malformed #friends-bootstrap payload:', error);
        }
    }

    private async refetch(): Promise<void> {
        try {
            const listing = await this.api.listFriends();
            this.render(listing);
        } catch (error) {
            console.error('Failed to refresh friends list:', error);
        }
    }

    private handleUserEvent(event: UserEvent): void {
        if ('friend_request' === event.event || 'friend_accepted' === event.event) {
            void this.refetch();
        }
        // Any other event type belongs to a later phase - ignored here.
    }

    private render(listing: FriendsListResult): void {
        this.renderRequests(this.incomingList, listing.incoming, ['friend-accept', 'friend-decline'], 'No pending requests.');
        this.renderRequests(this.outgoingList, listing.outgoing, [], 'No pending requests.', 'Request sent');
        this.renderFriends(listing.friends);
        this.renderRequests(this.blockedList, listing.blocked, ['friend-unblock'], "You haven't blocked anyone.");
    }

    private renderRequests(
        list: HTMLElement,
        rows: FriendRequestRow[],
        actions: Array<'friend-accept' | 'friend-decline' | 'friend-unblock'>,
        emptyMessage: string,
        staticLabel?: string,
    ): void {
        list.innerHTML = '';

        if (0 === rows.length) {
            list.innerHTML = `<li class="has-text-grey">${emptyMessage}</li>`;

            return;
        }

        for (const row of rows) {
            const li = document.createElement('li');
            li.className = 'friends-list__row mb-2';
            li.dataset.username = row.username;

            const buttons = actions.map((action) => {
                const label = {'friend-accept': 'Accept', 'friend-decline': 'Decline', 'friend-unblock': 'Unblock'}[action];
                const cls = 'friend-accept' === action ? 'is-primary' : 'is-light';

                return `<button type="button" class="button is-small is-rounded ${cls}" data-action="${action}" data-username="${this.escape(row.username)}">${label}</button>`;
            }).join(' ');

            li.innerHTML = `<span>${this.escape(row.displayName ?? row.username)} <span class="has-text-grey">@${this.escape(row.username)}</span></span> `
                + (staticLabel ? `<span class="tag is-light is-rounded">${staticLabel}</span>` : buttons);

            list.appendChild(li);
        }
    }

    private renderFriends(rows: FriendRow[]): void {
        this.friendsList.innerHTML = '';

        if (0 === rows.length) {
            this.friendsList.innerHTML = '<li class="has-text-grey">No friends yet - search for a username above.</li>';

            return;
        }

        for (const row of rows) {
            const li = document.createElement('li');
            li.className = 'friends-list__row mb-2';
            li.dataset.username = row.username;
            const dot = row.online ? '<span class="tag is-success is-rounded is-small">online</span>' : '';
            li.innerHTML = `<span>${this.escape(row.displayName ?? row.username)} <span class="has-text-grey">@${this.escape(row.username)}</span></span> ${dot} `
                + `<button type="button" class="button is-small is-rounded is-light" data-action="friend-remove" data-username="${this.escape(row.username)}">Unfriend</button>`;
            this.friendsList.appendChild(li);
        }
    }

    private wireActionDelegation(): void {
        this.root.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof HTMLElement)) {
                return;
            }

            const button = target.closest<HTMLElement>('[data-action]');

            if (!button || !this.root.contains(button)) {
                return;
            }

            const action = button.dataset.action;
            const username = button.dataset.username;

            if (!action || !username) {
                return;
            }

            void this.runAction(action, username);
        });
    }

    private async runAction(action: string, username: string): Promise<void> {
        try {
            switch (action) {
                case 'friend-accept':
                    await this.api.acceptFriend(username);
                    break;
                case 'friend-decline':
                    await this.api.declineFriend(username);
                    break;
                case 'friend-remove':
                    await this.api.removeFriend(username);
                    break;
                case 'friend-unblock':
                    await this.api.unblockUser(username);
                    break;
                case 'friend-request':
                    await this.api.requestFriend(username);
                    break;
                case 'friend-block':
                    await this.api.blockUser(username);
                    break;
                default:
                    return;
            }

            await this.refetch();
        } catch (error) {
            this.reportError(`Could not complete "${action}"`, error);
        }
    }

    private wireSearch(): void {
        this.searchInput.addEventListener('input', () => {
            const q = this.searchInput.value.trim();

            if (null !== this.searchTimer) {
                clearTimeout(this.searchTimer);
            }

            if (q.length < MIN_SEARCH_LENGTH) {
                this.searchAbort?.abort();
                this.searchResults.innerHTML = '';

                return;
            }

            this.searchTimer = setTimeout(() => void this.runSearch(q), SEARCH_DEBOUNCE_MS);
        });
    }

    private async runSearch(q: string): Promise<void> {
        this.searchAbort?.abort();
        const controller = new AbortController();
        this.searchAbort = controller;

        try {
            const results = await this.api.searchPlayers(q, controller.signal);
            this.renderSearchResults(results);
        } catch (error) {
            if (error instanceof DOMException && 'AbortError' === error.name) {
                return; // superseded by a newer keystroke
            }

            this.reportError('Search failed', error);
        }
    }

    private renderSearchResults(results: PlayerSearchResult[]): void {
        this.searchResults.innerHTML = '';

        if (0 === results.length) {
            this.searchResults.innerHTML = '<p class="has-text-grey">No players found.</p>';

            return;
        }

        const list = document.createElement('ul');
        list.className = 'friends-list';

        for (const player of results) {
            const li = document.createElement('li');
            li.className = 'friends-list__row mb-2';
            const dot = player.online ? '<span class="tag is-success is-rounded is-small">online</span>' : '';
            li.innerHTML = `<span>@${this.escape(player.username)}</span> ${dot} `
                + `<button type="button" class="button is-small is-rounded is-primary" data-action="friend-request" data-username="${this.escape(player.username)}">Add friend</button>`;
            list.appendChild(li);
        }

        this.searchResults.appendChild(list);
    }

    private escape(value: string): string {
        const div = document.createElement('div');
        div.textContent = value;

        return div.innerHTML;
    }

    private reportError(context: string, error: unknown): void {
        const message = error instanceof ApiError ? error.code : String(error);
        console.error(`${context}: ${message}`);
        window.alert(`${context}: ${message}`);
    }
}
