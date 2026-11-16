import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AiChatMessages } from '@/components/ai/ai-chat-messages';
import { AssistantPanel } from '@/components/ai/assistant-panel';
import { ChatComposer } from '@/components/layout/chat-layout';
import { useAiChat } from '@/hooks/use-ai-chat';
import { useCan } from '@/hooks/use-can';
import { PermissionEnum } from '@/enums/permission-enum';
import { fetchAiStatus } from '@/lib/ai-chat';
import type { AiStatus } from '@/lib/ai-chat';
import { index as assistantIndex } from '@/routes/ai';

/**
 * The ⌘J Assistant side panel (#203), mounted once in the app layout as app-ui
 * `docs/chat-and-assistant.md` says: the same thread and composer as the Assistant page, off on
 * that page and for users without `can-use-ai`. The status is read when the panel first opens.
 */
export function AppAssistantPanel() {
    const { t } = useTranslation();
    const { can } = useCan();
    const { url } = usePage();
    const [isOpen, setIsOpen] = useState(false);
    const [draft, setDraft] = useState('');
    const [status, setStatus] = useState<AiStatus | null>(null);
    const chat = useAiChat();
    const isAssistantPage = url === '/ai' || url.startsWith('/ai?');
    const isEnabled = can(PermissionEnum.CanUseAi) && !isAssistantPage;

    useEffect(() => {
        if (!isOpen || status !== null) {
            return;
        }

        void fetchAiStatus()
            .then(setStatus)
            .catch(() => undefined);
    }, [isOpen, status]);

    const isUsable = status?.enabled === true;

    return (
        <AssistantPanel
            open={isOpen}
            onOpenChange={setIsOpen}
            enabled={isEnabled}
            onOpenWithPrompt={(prompt) => {
                chat.startNew();
                setDraft(prompt);
            }}
            onOpenFullPage={() => router.visit(assistantIndex.url())}
            composer={
                <ChatComposer
                    value={draft}
                    onChange={setDraft}
                    onSubmit={() => {
                        const prompt = draft;
                        setDraft('');
                        void chat.send(prompt);
                    }}
                    label={t('assistant.composerLabel')}
                    sendLabel={t('assistant.send')}
                    placeholder={t('assistant.placeholder')}
                    disabled={!isUsable || chat.isStreaming}
                />
            }
        >
            {status !== null && !isUsable ? (
                <p className="text-sm text-muted-foreground">
                    {t('assistant.offDescription')}
                </p>
            ) : chat.messages.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('assistant.emptyDescription')}
                </p>
            ) : (
                <AiChatMessages
                    messages={chat.messages}
                    isStreaming={chat.isStreaming}
                />
            )}
        </AssistantPanel>
    );
}
