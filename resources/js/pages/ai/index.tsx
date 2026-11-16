import { Head } from '@inertiajs/react';
import { Plus, Sparkles, Trash2 } from 'lucide-react';
import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderIconButton } from '@/components/admin/header-icon-button';
import { AiChatMessages } from '@/components/ai/ai-chat-messages';
import { ConfirmDestructiveDialog } from '@/components/confirm-destructive-dialog';
import {
    ChatComposer,
    ChatLayout,
    ChatListItem,
    ChatListSection,
    ChatThread,
} from '@/components/layout/chat-layout';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { useAiChat } from '@/hooks/use-ai-chat';
import AppLayout from '@/layouts/app-layout';
import { deleteAiConversation, fetchAiConversations } from '@/lib/ai-chat';
import type { AiConversationSummary, AiStatus } from '@/lib/ai-chat';
import { toast } from '@/lib/toast';
import { index as assistantIndex } from '@/routes/ai';

type Props = {
    status: AiStatus;
    conversations: AiConversationSummary[];
};

/**
 * The Assistant page (#203), laid out as app-ui `docs/chat-and-assistant.md` and externa-core
 * `pages/ai/index.tsx`: "New chat" in the header, conversations on the left, the thread and the
 * composer on the right. While the assistant is off it says how to turn it on.
 */
export default function AssistantPage({
    status,
    conversations: initialConversations,
}: Props) {
    const { t } = useTranslation();
    const [conversations, setConversations] = useState(initialConversations);
    const [draft, setDraft] = useState('');
    const [pendingDelete, setPendingDelete] =
        useState<AiConversationSummary | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const refreshConversations = useCallback(() => {
        void fetchAiConversations()
            .then(setConversations)
            .catch(() => undefined);
    }, []);

    const chat = useAiChat({ onConversationStarted: refreshConversations });
    const isUsable = status.enabled;

    const submit = (): void => {
        const prompt = draft;
        setDraft('');
        void chat.send(prompt);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: t('assistant.title'), href: assistantIndex() },
            ]}
            headerActions={
                isUsable ? (
                    <Button type="button" onClick={chat.startNew}>
                        <Plus className="mr-1 size-4" />
                        {t('assistant.newChat')}
                    </Button>
                ) : undefined
            }
        >
            <Head title={t('assistant.title')} />
            <PageLayout className="min-h-0">
                <ChatLayout
                    listLabel={t('assistant.conversations')}
                    isListCentered={conversations.length === 0}
                    isThreadOpen={
                        chat.conversationId !== null || chat.messages.length > 0
                    }
                    list={
                        conversations.length === 0 ? (
                            <p className="px-2 text-sm text-muted-foreground">
                                {t('assistant.noConversations')}
                            </p>
                        ) : (
                            <ChatListSection label={t('assistant.recent')}>
                                {conversations.map((conversation) => (
                                    <div
                                        key={conversation.id}
                                        className="group flex items-center gap-1"
                                    >
                                        <ChatListItem
                                            className="min-w-0 flex-1"
                                            isSelected={
                                                chat.conversationId ===
                                                conversation.id
                                            }
                                            onSelect={() =>
                                                void chat.open(conversation.id)
                                            }
                                        >
                                            <span className="truncate">
                                                {conversation.title ??
                                                    t('assistant.untitled')}
                                            </span>
                                        </ChatListItem>
                                        <HeaderIconButton
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            className="text-destructive opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                                            label={t(
                                                'assistant.deleteConversation',
                                            )}
                                            onClick={() =>
                                                setPendingDelete(conversation)
                                            }
                                        >
                                            <Trash2 className="size-3.5" />
                                        </HeaderIconButton>
                                    </div>
                                ))}
                            </ChatListSection>
                        )
                    }
                >
                    <ChatThread
                        composer={
                            <ChatComposer
                                value={draft}
                                onChange={setDraft}
                                onSubmit={submit}
                                label={t('assistant.composerLabel')}
                                sendLabel={t('assistant.send')}
                                placeholder={t('assistant.placeholder')}
                                disabled={!isUsable || chat.isStreaming}
                            />
                        }
                    >
                        {!isUsable ? (
                            <AssistantEmptyState
                                title={t('assistant.offTitle')}
                                description={t('assistant.offDescription')}
                            />
                        ) : chat.messages.length === 0 ? (
                            <AssistantEmptyState
                                title={t('assistant.emptyTitle')}
                                description={t(
                                    status.online
                                        ? 'assistant.emptyDescription'
                                        : 'assistant.offlineDescription',
                                )}
                            />
                        ) : (
                            <AiChatMessages
                                messages={chat.messages}
                                isStreaming={chat.isStreaming}
                            />
                        )}
                    </ChatThread>
                </ChatLayout>
            </PageLayout>

            <ConfirmDestructiveDialog
                open={pendingDelete !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) {
                        setPendingDelete(null);
                    }
                }}
                title={t('assistant.confirmDeleteTitle')}
                description={t('assistant.confirmDeleteDescription')}
                confirmLabel={t('assistant.deleteConversation')}
                confirming={isDeleting}
                onConfirm={() => {
                    if (pendingDelete === null) {
                        return;
                    }

                    const deletedId = pendingDelete.id;
                    setIsDeleting(true);
                    void deleteAiConversation(deletedId)
                        .then(() => {
                            if (chat.conversationId === deletedId) {
                                chat.startNew();
                            }

                            refreshConversations();
                        })
                        .catch((error: Error) => toast.error(error.message))
                        .finally(() => {
                            setIsDeleting(false);
                            setPendingDelete(null);
                        });
                }}
            />
        </AppLayout>
    );
}

/**
 * The Assistant's empty and "off" states with the violet AI tile (app-ui
 * `docs/chat-and-assistant.md`: AI surfaces use the `ai` tokens, never the brand primary).
 */
function AssistantEmptyState({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <Empty className="m-auto">
            <EmptyHeader>
                <EmptyMedia variant="icon" className="bg-ai/10 text-ai">
                    <Sparkles />
                </EmptyMedia>
                <EmptyTitle>{title}</EmptyTitle>
                <EmptyDescription>{description}</EmptyDescription>
            </EmptyHeader>
        </Empty>
    );
}
