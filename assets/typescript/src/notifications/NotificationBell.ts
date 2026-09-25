import {NotificationAPI, NotificationRow} from '../network/NotificationAPI';

/** Fallback refresh for rows written without a live frame (07-notifications.md sec 7.4). */
const POLL_INTERVAL_MS = 60000;

/**
 * The header bell (every signed-in page): unread badge, dropdown with the
 * latest notifications, per-item and "mark all" read actions. Kept live by
 * the private `user/{uuid}` Mercure topic - any frame there may carry a new
 * notification - plus a slow poll while the tab is visible.
 */
export class NotificationBell {
    private readonly api = new NotificationAPI();
    private readonly toggle: HTMLButtonElement;
    private readonly badge: HTMLElement;
    private readonly panel: HTMLElement;
    private readonly list: HTMLElement;
    private readonly readAllButton: HTMLButtonElement | null;
    private eventSource: EventSource | null = null;
    private pollTimer: ReturnType<typeof setInterval> | null = null;

    constructor(private readonly root: HTMLElement) {
        this.toggle = root.querySelector('#notification-bell-toggle') as HTMLButtonElement;
        this.badge = root.querySelector('#notification-bell-badge') as HTMLElement;
        this.panel = root.querySelector('#notification-bell-panel') as HTMLElement;
        this.list = root.querySelector('#notification-bell-list') as HTMLElement;
        this.readAllButton = root.querySelector('#notification-bell-read-all');
    }

    init(): void {
        this.toggle.addEventListener('click', (event) => {
            event.stopPropagation();
            this.setOpen(this.panel.hidden);
        });
        document.addEventListener('click', (event) => {
            if (!this.panel.hidden && event.target instanceof Node && !this.root.contains(event.target)) {
                this.setOpen(false);
            }
        });
        document.addEventListener('keydown', (event) => {
            if ('Escape' === event.key && !this.panel.hidden) {
                this.setOpen(false);
                this.toggle.focus();
            }
        });
        this.readAllButton?.addEventListener('click', () => void this.markAllRead());
        this.list.addEventListener('click', (event) => this.handleListClick(event));

        this.subscribe();
        this.pollTimer = setInterval(() => {
            if ('visible' === document.visibilityState) {
                void this.refreshCount();
            }
        }, POLL_INTERVAL_MS);
        document.addEventListener('visibilitychange', () => {
            if ('visible' === document.visibilityState) {
                void this.refreshCount();
            }
        });
        window.addEventListener('pagehide', () => this.dispose(), {once: true});
    }

    dispose(): void {
        this.eventSource?.close();
        this.eventSource = null;

        if (null !== this.pollTimer) {
            clearInterval(this.pollTimer);
        }
    }

    private subscribe(): void {
        const hubUrl = this.root.dataset.mercureUrl;
        const userUuid = this.root.dataset.userUuid;

        if (!hubUrl || !userUuid || !('EventSource' in window)) {
            return;
        }

        const url = new URL(hubUrl, window.location.href);
        url.searchParams.append('topic', `user/${userUuid}`);
        this.eventSource = new EventSource(url.toString(), {withCredentials: true});
        this.eventSource.onmessage = (message: MessageEvent) => {
            try {
                const frame = JSON.parse(message.data) as {event?: string; unreadCount?: number};

                if ('notification' === frame.event && 'number' === typeof frame.unreadCount) {
                    this.setUnread(frame.unreadCount);
                    if (!this.panel.hidden) {
                        void this.loadList();
                    }
                }
            } catch (error) {
                console.error('Malformed user/{uuid} frame:', error);
            }
        };
    }

    private setOpen(open: boolean): void {
        this.panel.hidden = !open;
        this.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            void this.loadList();
        }
    }

    private async loadList(): Promise<void> {
        try {
            const result = await this.api.list();
            this.renderList(result.notifications);
            this.setUnread(result.unread);
        } catch (error) {
            console.error('Could not load notifications:', error);
            this.list.replaceChildren(this.emptyItem('Could not load notifications.'));
        }
    }

    private async refreshCount(): Promise<void> {
        try {
            this.setUnread(await this.api.unreadCount());
        } catch {
            // Transient (offline, session expired): the next poll retries.
        }
    }

    private async markAllRead(): Promise<void> {
        try {
            this.setUnread(await this.api.markAllRead());
            for (const item of Array.from(this.list.querySelectorAll('.is-unread'))) {
                item.classList.remove('is-unread');
                item.querySelector('[data-notification-read]')?.remove();
            }
        } catch (error) {
            console.error('Could not mark notifications as read:', error);
        }
    }

    private handleListClick(event: MouseEvent): void {
        const target = event.target instanceof Element ? event.target : null;
        const item = target?.closest<HTMLElement>('[data-uuid]');
        const uuid = item?.dataset.uuid;

        if (!item || !uuid) {
            return;
        }

        if (target?.closest('[data-notification-read]')) {
            event.preventDefault();
            void this.api.markRead(uuid).then((unread) => {
                this.setUnread(unread);
                item.classList.remove('is-unread');
                item.querySelector('[data-notification-read]')?.remove();
            });

            return;
        }

        const link = target?.closest<HTMLAnchorElement>('a[href]');

        if (link && item.classList.contains('is-unread')) {
            // Mark read, then follow the link (keepalive would lose the
            // response; a short await keeps the badge honest on return).
            event.preventDefault();
            void this.api.markRead(uuid).catch(() => undefined).finally(() => {
                window.location.href = link.href;
            });
        }
    }

    private renderList(rows: NotificationRow[]): void {
        if (0 === rows.length) {
            this.list.replaceChildren(this.emptyItem('You have no notifications yet.'));

            return;
        }

        this.list.replaceChildren(...rows.map((row) => this.buildItem(row)));
    }

    private buildItem(row: NotificationRow): HTMLLIElement {
        const item = document.createElement('li');
        item.className = `notification-bell__item${row.read ? '' : ' is-unread'}`;
        item.dataset.uuid = row.uuid;

        const link = document.createElement('a');
        link.href = row.url;
        link.className = 'notification-bell__link';

        const icon = document.createElement('span');
        icon.className = 'icon';
        const glyph = document.createElement('i');
        glyph.className = `fa-solid ${row.icon}`;
        glyph.setAttribute('aria-hidden', 'true');
        icon.appendChild(glyph);

        const body = document.createElement('span');
        body.className = 'notification-bell__body';
        const text = document.createElement('span');
        text.textContent = row.text;
        const time = document.createElement('time');
        time.className = 'notification-bell__time';
        time.dateTime = row.createdAt;
        time.textContent = this.formatAge(row.createdAt);
        body.append(text, time);

        link.append(icon, body);
        item.appendChild(link);

        if (!row.read) {
            const readButton = document.createElement('button');
            readButton.type = 'button';
            readButton.className = 'button is-small is-text notification-bell__read';
            readButton.dataset.notificationRead = '';
            readButton.title = 'Mark as read';
            readButton.setAttribute('aria-label', 'Mark as read');
            readButton.innerHTML = '<span class="icon"><i class="fa-solid fa-check" aria-hidden="true"></i></span>';
            item.appendChild(readButton);
        }

        return item;
    }

    private emptyItem(message: string): HTMLLIElement {
        const item = document.createElement('li');
        item.className = 'notification-bell__empty';
        item.textContent = message;

        return item;
    }

    private setUnread(count: number): void {
        this.badge.hidden = 0 === count;
        this.badge.textContent = count >= 100 ? '99+' : String(count);
        this.toggle.setAttribute('aria-label', count > 0 ? `Notifications (${count} unread)` : 'Notifications');

        if (this.readAllButton) {
            this.readAllButton.disabled = 0 === count;
        }
    }

    private formatAge(iso: string): string {
        const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));

        if (seconds < 60) return 'just now';
        if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} h ago`;
        if (seconds < 7 * 86400) return `${Math.floor(seconds / 86400)} d ago`;

        return new Date(iso).toLocaleDateString();
    }
}

/** The full inbox page (`GET /notifications`): per-item and "mark all" read buttons. */
function wireInboxPage(): void {
    const list = document.getElementById('notification-page-list');
    const readAll = document.querySelector<HTMLButtonElement>('[data-notifications-read-all]');
    const api = new NotificationAPI();

    readAll?.addEventListener('click', () => {
        void api.markAllRead().then(() => window.location.reload());
    });

    list?.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const item = target?.closest<HTMLElement>('[data-uuid]');
        const uuid = item?.dataset.uuid;

        if (!item || !uuid || !item.classList.contains('is-unread')) {
            return;
        }

        if (target?.closest('[data-notification-read]')) {
            void api.markRead(uuid).then(() => {
                item.classList.remove('is-unread');
                item.querySelector('[data-notification-read]')?.remove();
            });

            return;
        }

        const link = target?.closest<HTMLAnchorElement>('[data-notification-link]');

        if (link) {
            event.preventDefault();
            void api.markRead(uuid).catch(() => undefined).finally(() => {
                window.location.href = link.href;
            });
        }
    });
}

export function initNotifications(): void {
    const bellRoot = document.getElementById('notification-bell');

    if (bellRoot) {
        new NotificationBell(bellRoot).init();
    }

    wireInboxPage();
}
