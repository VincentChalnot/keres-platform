import {request} from './LobbyAPI';

/** One row as `NotificationFormatter` renders it (09-api-reference.md sec 4.5). */
export interface NotificationRow {
    uuid: string;
    type: string;
    text: string;
    url: string;
    icon: string;
    read: boolean;
    createdAt: string;
}

export interface NotificationListResult {
    notifications: NotificationRow[];
    unread: number;
}

/** `/notifications/*` JSON endpoints behind the header bell and the inbox page. */
export class NotificationAPI {
    list(limit = 10): Promise<NotificationListResult> {
        return request<NotificationListResult>(`/notifications/list?limit=${limit}`);
    }

    unreadCount(): Promise<number> {
        return request<{unread: number}>('/notifications/unread-count').then((result) => result.unread);
    }

    markRead(uuid: string): Promise<number> {
        return request<{unread: number}>(`/notifications/${encodeURIComponent(uuid)}/read`, 'POST').then((result) => result.unread);
    }

    markAllRead(): Promise<number> {
        return request<{unread: number}>('/notifications/read-all', 'POST').then((result) => result.unread);
    }
}
