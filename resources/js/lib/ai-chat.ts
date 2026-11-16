import i18n from '@/lib/i18n';

/**
 * Client for the assistant endpoints (#203), cut down from externa-core's `lib/ai-chat.ts`:
 * status, the SSE chat stream and the user's conversations. No attachments, context pickers or
 * tool approvals: Instituta's assistant only reads the brain.
 */

export type AiStatus = {
    enabled: boolean;
    provider: string;
    online: boolean;
    model: string | null;
    context_window: number | null;
    daily_usage: AiDailyUsage | null;
};

export type AiDailyUsage = {
    prompts_used: number;
    prompt_limit: number | null;
    tokens_used: number;
    token_budget: number | null;
};

export type AiConversationSummary = {
    id: string;
    title: string | null;
    updated_at: string | null;
};

export type AiStoredMessage = {
    id: string;
    role: string;
    content: string;
    created_at: string | null;
};

export type AiToolResultDetail = {
    id: string;
    successful: boolean;
    error: string | null;
};

export type AiStreamHandlers = {
    onToken?: (token: string) => void;
    onTool?: (
        toolName: string,
        call: { id: string; arguments: unknown },
    ) => void;
    onToolResult?: (
        toolName: string,
        result: unknown,
        detail: AiToolResultDetail,
    ) => void;
    onReasoningDelta?: (reasoningId: string, delta: string) => void;
    onReasoningEnd?: (reasoningId: string) => void;
    onConversationId?: (conversationId: string) => void;
    onDone?: () => void;
    onError?: (error: Error) => void;
    signal?: AbortSignal;
};

type ParsedSsePayload = {
    type?: string;
    delta?: string;
    text?: string;
    message?: string;
    conversation_id?: string;
    tool_name?: string;
    tool_id?: string;
    id?: string;
    name?: string;
    arguments?: unknown;
    result?: unknown;
    successful?: boolean;
    error?: string | null;
    preliminary?: boolean;
    reasoning_id?: string;
};

/**
 * Laravel accepts the `XSRF-TOKEN` cookie back as `X-XSRF-TOKEN`; the `csrf-token` meta tag is
 * the fallback (same as `lib/notifications-api.ts`).
 */
function requestHeaders(): Record<string, string> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const cookieMatch = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    if (cookieMatch?.[1]) {
        headers['X-XSRF-TOKEN'] = decodeURIComponent(cookieMatch[1]);
    } else {
        const meta = document.querySelector<HTMLMetaElement>(
            'meta[name="csrf-token"]',
        );

        if (meta?.content) {
            headers['X-CSRF-TOKEN'] = meta.content;
        }
    }

    return headers;
}

async function errorMessage(response: Response): Promise<string> {
    try {
        const body = (await response.json()) as { message?: unknown };

        if (typeof body.message === 'string' && body.message !== '') {
            return body.message;
        }
    } catch {
        // Not JSON: fall back to the generic message.
    }

    return i18n.t('assistant.errors.request', { status: response.status });
}

export function isAbortError(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'AbortError';
}

export async function fetchAiStatus(signal?: AbortSignal): Promise<AiStatus> {
    const response = await fetch('/ai/status', {
        headers: requestHeaders(),
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) {
        throw new Error(await errorMessage(response));
    }

    return (await response.json()) as AiStatus;
}

export async function fetchAiConversations(): Promise<AiConversationSummary[]> {
    const response = await fetch('/ai/conversations', {
        headers: requestHeaders(),
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(await errorMessage(response));
    }

    return (
        (await response.json()) as { conversations: AiConversationSummary[] }
    ).conversations;
}

export async function fetchAiConversation(
    conversationId: string,
): Promise<AiStoredMessage[]> {
    const response = await fetch(
        `/ai/conversations/${encodeURIComponent(conversationId)}`,
        { headers: requestHeaders(), credentials: 'same-origin' },
    );

    if (!response.ok) {
        throw new Error(await errorMessage(response));
    }

    return ((await response.json()) as { messages: AiStoredMessage[] })
        .messages;
}

export async function deleteAiConversation(
    conversationId: string,
): Promise<void> {
    const response = await fetch(
        `/ai/conversations/${encodeURIComponent(conversationId)}`,
        {
            method: 'DELETE',
            headers: requestHeaders(),
            credentials: 'same-origin',
        },
    );

    if (!response.ok) {
        throw new Error(await errorMessage(response));
    }
}

/**
 * Handles one parsed SSE event; returns false when the stream should stop (an error event).
 */
function dispatchEvent(
    parsed: ParsedSsePayload,
    handlers: AiStreamHandlers,
): boolean {
    switch (parsed.type) {
        case 'conversation':
            if (parsed.conversation_id) {
                handlers.onConversationId?.(parsed.conversation_id);
            }

            return true;
        case 'tool_call': {
            const name = parsed.tool_name ?? parsed.name ?? 'tool';
            handlers.onTool?.(name, {
                id: parsed.tool_id ?? parsed.id ?? name,
                arguments: parsed.arguments ?? {},
            });

            return true;
        }
        case 'tool_result': {
            if (parsed.preliminary) {
                return true;
            }

            const name = parsed.tool_name ?? parsed.name ?? 'tool';
            handlers.onToolResult?.(name, parsed.result, {
                id: parsed.tool_id ?? parsed.id ?? name,
                successful:
                    parsed.successful ??
                    (parsed.error === null || parsed.error === undefined),
                error: parsed.error ?? null,
            });

            return true;
        }
        case 'reasoning_delta':
            if (parsed.delta) {
                handlers.onReasoningDelta?.(
                    parsed.reasoning_id ?? 'reasoning',
                    parsed.delta,
                );
            }

            return true;
        case 'reasoning_end':
            handlers.onReasoningEnd?.(parsed.reasoning_id ?? 'reasoning');

            return true;
        case 'error':
            handlers.onError?.(
                new Error(parsed.message || i18n.t('assistant.errors.stream')),
            );

            return false;
        case 'text_delta':
        case undefined: {
            const token = parsed.delta ?? parsed.text;

            if (typeof token === 'string' && token !== '') {
                handlers.onToken?.(token);
            }

            return true;
        }
        default:
            // Bookends (stream_start, text_start, …) carry nothing to show.
            return true;
    }
}

/**
 * Sends a question and streams the answer (SSE `data:` lines, closed by `[DONE]`).
 */
export async function streamAiChat(
    message: string,
    conversationId: string | null,
    handlers: AiStreamHandlers = {},
): Promise<void> {
    let response: Response;

    try {
        response = await fetch('/ai/chat', {
            method: 'POST',
            headers: { ...requestHeaders(), Accept: 'text/event-stream' },
            credentials: 'same-origin',
            signal: handlers.signal,
            body: JSON.stringify({
                message,
                conversation_id: conversationId,
            }),
        });
    } catch (error) {
        if (!isAbortError(error)) {
            handlers.onError?.(new Error(i18n.t('assistant.errors.network')));
        }

        return;
    }

    if (!response.ok || !response.body) {
        handlers.onError?.(new Error(await errorMessage(response)));

        return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    try {
        while (true) {
            const { done, value } = await reader.read();

            if (done) {
                break;
            }

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop() ?? '';

            for (const line of lines) {
                const trimmed = line.trim();

                if (!trimmed.startsWith('data:')) {
                    continue;
                }

                const data = trimmed.slice(5).trim();

                if (data === '[DONE]') {
                    handlers.onDone?.();

                    return;
                }

                let parsed: ParsedSsePayload;

                try {
                    parsed = JSON.parse(data) as ParsedSsePayload;
                } catch {
                    parsed = { type: 'text_delta', delta: data };
                }

                if (!dispatchEvent(parsed, handlers)) {
                    return;
                }
            }
        }

        handlers.onDone?.();
    } catch (error) {
        if (!isAbortError(error)) {
            // Browsers surface a mid-stream disconnect as a TypeError ("network error").
            handlers.onError?.(
                new Error(i18n.t('assistant.errors.streamInterrupted')),
            );
        }
    }
}
