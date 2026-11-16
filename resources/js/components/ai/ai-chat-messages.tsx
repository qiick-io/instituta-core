import { useTranslation } from 'react-i18next';
import {
    AiAnswerParts,
    describeToolCallGenerically,
} from '@/components/ai/ai-answer-parts';
import type { AiToolCallDescriber } from '@/components/ai/ai-answer-parts';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { Message, MessageContent } from '@/components/ui/message';
import type { AiChatMessage } from '@/hooks/use-ai-chat';

const BRAIN_TOOL_KEYS: Record<string, string> = {
    SearchBrainTool: 'search',
    SimilarBrainSectionsTool: 'similar',
    OutlineBrainItemTool: 'outline',
    ReadBrainSectionTool: 'read',
    ResolveProductTool: 'resolve',
};

/**
 * The sentence of each brain tool call (app-ui `docs/chat-and-assistant.md`): present tense
 * while it runs, past tense when done, "Could not …" on failure; never the raw JSON.
 */
const describeBrainToolCall: AiToolCallDescriber = (call, t) => {
    const key = BRAIN_TOOL_KEYS[call.name];

    if (key === undefined) {
        return describeToolCallGenerically(call, t);
    }

    const input = (call.input ?? {}) as Record<string, unknown>;
    const subject =
        [input.q, input.item, input.product_id].find(
            (value): value is string => typeof value === 'string',
        ) ?? '';
    const phase =
        call.state === 'output-error'
            ? 'error'
            : call.state === 'output-available'
              ? 'done'
              : 'running';

    return {
        title: t(`assistant.tools.${key}.${phase}`, { subject }),
    };
};

/**
 * The thread of an assistant conversation (#203), shaped after externa-core's AiChatMessages:
 * the user's prompts in muted bubbles aligned to the end, answers full width as a ghost bubble
 * with their parts (reasoning, tool lines, text), errors in a destructive bubble.
 */
export function AiChatMessages({
    messages,
    isStreaming,
}: {
    messages: AiChatMessage[];
    isStreaming: boolean;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-4" aria-live="polite">
            {messages.map((message, index) => {
                const isUser = message.role === 'user';
                const isLastStreaming =
                    isStreaming && index === messages.length - 1;

                if (isUser) {
                    return (
                        <Message key={message.id} align="end">
                            <MessageContent>
                                <Bubble variant="muted" align="end">
                                    <BubbleContent className="whitespace-pre-wrap">
                                        {message.content}
                                    </BubbleContent>
                                </Bubble>
                            </MessageContent>
                        </Message>
                    );
                }

                return (
                    <Message key={message.id}>
                        <MessageContent>
                            <Bubble
                                variant={
                                    message.isError ? 'destructive' : 'ghost'
                                }
                            >
                                <BubbleContent>
                                    {message.isError ? (
                                        message.content
                                    ) : message.parts.length === 0 &&
                                      message.content === '' ? (
                                        <span className="text-muted-foreground">
                                            {t('assistant.thinking')}
                                        </span>
                                    ) : (
                                        <AiAnswerParts
                                            parts={message.parts}
                                            content={message.content}
                                            isStreaming={isLastStreaming}
                                            describeToolCall={
                                                describeBrainToolCall
                                            }
                                        />
                                    )}
                                </BubbleContent>
                            </Bubble>
                        </MessageContent>
                    </Message>
                );
            })}
        </div>
    );
}
