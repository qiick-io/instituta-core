import {
    AlertTriangle,
    CheckCircle2,
    FilePenLine,
    KeyRound,
    Snowflake,
    XCircle,
} from 'lucide-react';
import { createNotificationsSource } from '@/lib/notifications-api';
import type {
    DatabaseNotificationRow,
    NotificationItem,
} from '@/lib/notifications-api';
import i18n from '@/lib/i18n';
import notifications from '@/routes/notifications';

/**
 * Maps Instituta notification types to drawer items (icons, i18n titles, links).
 * Titles and bodies stay out of the database payload so en/it/de stay in locales.
 */
export function toNotificationItem(
    row: DatabaseNotificationRow,
): NotificationItem {
    const type = typeof row.data.type === 'string' ? row.data.type : 'unknown';
    const sourceName =
        typeof row.data.source_name === 'string' ? row.data.source_name : '';
    const proposalTitle =
        typeof row.data.title === 'string' ? row.data.title : '';
    const keyName =
        typeof row.data.key_name === 'string' ? row.data.key_name : '';
    const reason = typeof row.data.reason === 'string' ? row.data.reason : '';
    const status = typeof row.data.status === 'string' ? row.data.status : '';
    const expiresAt =
        typeof row.data.expires_at === 'string' ? row.data.expires_at : '';
    const url = typeof row.data.url === 'string' ? row.data.url : null;

    const mapping: Record<
        string,
        {
            icon: NotificationItem['icon'];
            title: string;
            body?: string;
        }
    > = {
        sync_finished: {
            icon: CheckCircle2,
            title: i18n.t('notifications.types.syncFinished.title', {
                name: sourceName,
            }),
            body: i18n.t('notifications.types.syncFinished.body', {
                count: row.data.items_upserted ?? 0,
            }),
        },
        sync_failed: {
            icon: XCircle,
            title: i18n.t('notifications.types.syncFailed.title', {
                name: sourceName,
            }),
            body: i18n.t('notifications.types.syncFailed.body', { reason }),
        },
        proposal_opened: {
            icon: FilePenLine,
            title: i18n.t('notifications.types.proposalOpened.title'),
            body: i18n.t('notifications.types.proposalOpened.body', {
                title: proposalTitle,
            }),
        },
        proposal_resolved: {
            icon: FilePenLine,
            title: i18n.t('notifications.types.proposalResolved.title', {
                status,
            }),
            body: i18n.t('notifications.types.proposalResolved.body', {
                title: proposalTitle,
            }),
        },
        api_key_expiring: {
            icon: KeyRound,
            title: i18n.t('notifications.types.apiKeyExpiring.title', {
                name: keyName,
            }),
            body: i18n.t('notifications.types.apiKeyExpiring.body', {
                expiresAt,
            }),
        },
        freeze_activated: {
            icon: Snowflake,
            title: i18n.t('notifications.types.freezeActivated.title'),
            body: i18n.t('notifications.types.freezeActivated.body', {
                reason,
            }),
        },
    };

    const mapped = mapping[type] ?? {
        icon: AlertTriangle,
        title: i18n.t('notifications.untitled'),
        body: undefined,
    };

    return {
        id: row.id,
        icon: mapped.icon,
        title: mapped.title || i18n.t('notifications.untitled'),
        body: mapped.body,
        link: url,
        time: row.created_at,
        isRead: row.read_at !== null,
    };
}

/** Shared notifications source for the sidebar bell (Laravel contract + Wayfinder). */
export const notificationsSource = createNotificationsSource(
    {
        index: notifications.index(),
        unreadCount: notifications.unreadCount(),
        read: notifications.read(),
        unread: notifications.unread(),
    },
    toNotificationItem,
);
