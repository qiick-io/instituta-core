import { useCallback, useRef, useState } from 'react';
import { fetchAiConversation, streamAiChat } from '@/lib/ai-chat';
import {
    addToolCall,
    appendReasoning,
    appendText,
    applyToolResult,
    closeReasoning,
} from '@/lib/ai-message-parts';
import type { AiMessagePart } from '@/lib/ai-message-parts';

export type AiChatMessage = {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    /** Live answer parts (reasoning, tool lines, text); empty for reloaded messages. */
    parts: AiMessagePart[];
    isError: boolean;
};

let localMessageCounter = 0;

function localId(): string {
    localMessageCounter += 1;

    return `local-${localMessageCounter}`;
}

/**
 * One assistant conversation (#203), shared by the Assistant page and the ⌘J panel: send a
 * question, build the answer's parts live from the SSE stream with the `ai-message-parts`
 * reducers, load or start a conversation. `onConversationStarted` lets the page refresh its list.
 */
export function useAiChat(
    options: { onConversationStarted?: (id: string) => void } = {},
) {
    const [messages, setMessages] = useState<AiChatMessage[]>([]);
    const [conversationId, setConversationId] = useState<string | null>(null);
    const [isStreaming, setIsStreaming] = useState(false);
    const abortRef = useRef<AbortController | null>(null);
    const onStartedRef = useRef(options.onConversationStarted);
    onStartedRef.current = options.onConversationStarted;

    const updateAnswer = useCallback(
        (
            answerId: string,
            update: (message: AiChatMessage) => AiChatMessage,
        ) => {
            setMessages((current) =>
                current.map((message) =>
                    message.id === answerId ? update(message) : message,
                ),
            );
        },
        [],
    );

    const send = useCallback(
        async (prompt: string) => {
            const question = prompt.trim();

            if (question === '' || isStreaming) {
                return;
            }

            const answerId = localId();
            setMessages((current) => [
                ...current,
                {
                    id: localId(),
                    role: 'user',
                    content: question,
                    parts: [],
                    isError: false,
                },
                {
                    id: answerId,
                    role: 'assistant',
                    content: '',
                    parts: [],
                    isError: false,
                },
            ]);
            setIsStreaming(true);
            abortRef.current = new AbortController();

            await streamAiChat(question, conversationId, {
                signal: abortRef.current.signal,
                onToken: (token) =>
                    updateAnswer(answerId, (message) => ({
                        ...message,
                        content: message.content + token,
                        parts: appendText(message.parts, token, Date.now()),
                    })),
                onReasoningDelta: (reasoningId, delta) =>
                    updateAnswer(answerId, (message) => ({
                        ...message,
                        parts: appendReasoning(
                            message.parts,
                            reasoningId,
                            delta,
                            Date.now(),
                        ),
                    })),
                onReasoningEnd: () =>
                    updateAnswer(answerId, (message) => ({
                        ...message,
                        parts: closeReasoning(message.parts, Date.now()),
                    })),
                onTool: (name, call) =>
                    updateAnswer(answerId, (message) => ({
                        ...message,
                        parts: addToolCall(
                            message.parts,
                            { id: call.id, name, input: call.arguments },
                            Date.now(),
                        ),
                    })),
                onToolResult: (name, output, detail) =>
                    updateAnswer(answerId, (message) => ({
                        ...message,
                        parts: applyToolResult(message.parts, {
                            id: detail.id,
                            name,
                            output,
                            successful: detail.successful,
                            error: detail.error,
                            denied: false,
                        }),
                    })),
                onConversationId: (id) => {
                    setConversationId((current) => {
                        if (current === null) {
                            onStartedRef.current?.(id);
                        }

                        return id;
                    });
                },
                onError: (error) =>
                    updateAnswer(answerId, (message) => ({
                        ...message,
                        content: error.message,
                        parts: [],
                        isError: true,
                    })),
            });

            updateAnswer(answerId, (message) => ({
                ...message,
                parts: closeReasoning(message.parts, Date.now()),
            }));
            setIsStreaming(false);
        },
        [conversationId, isStreaming, updateAnswer],
    );

    const startNew = useCallback(() => {
        abortRef.current?.abort();
        setMessages([]);
        setConversationId(null);
        setIsStreaming(false);
    }, []);

    const open = useCallback(async (id: string) => {
        abortRef.current?.abort();
        setIsStreaming(false);
        setConversationId(id);
        const stored = await fetchAiConversation(id);
        setMessages(
            stored
                .filter(
                    (message) =>
                        message.role === 'user' || message.role === 'assistant',
                )
                .map((message) => ({
                    id: message.id,
                    role: message.role === 'user' ? 'user' : 'assistant',
                    content: message.content,
                    parts: [],
                    isError: false,
                })),
        );
    }, []);

    return { messages, conversationId, isStreaming, send, startNew, open };
}
