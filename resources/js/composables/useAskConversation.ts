import { computed, ref } from "vue";
import english from "../locales/en.json" with { type: "json" };

const defaultTranslate = (key: string): string =>
    english[key as keyof typeof english] ?? key;

export type AskReply = {
    answer: string;
    intent?: string;
    conversation_id?: string;
    proposal?: ActionProposal;
};
export type ActionProposal = {
    intent: "action" | "invoice";
    preview: Record<string, unknown>;
    confirmation: { token: string; expires_at: string };
};
export type ChatMessage = {
    id: number;
    role: "user" | "assistant";
    text: string;
    invoice?: boolean;
    error?: boolean;
    proposal?: ActionProposal;
};
export type AskRequest = (
    question: string,
    conversationId: string,
) => Promise<AskReply>;

export function createConversationId(
    source: Pick<Crypto, "getRandomValues"> &
        Partial<Pick<Crypto, "randomUUID">> = crypto,
): string {
    if (typeof source.randomUUID === "function") {
        return source.randomUUID();
    }
    const bytes = source.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (value) =>
        value.toString(16).padStart(2, "0"),
    ).join("");
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function useAskConversation(
    request: AskRequest,
    createId: () => string = createConversationId,
    translate: (key: string) => string = defaultTranslate,
) {
    const messages = ref<ChatMessage[]>([]);
    const busy = ref(false);
    const loading = ref(false);
    const conversationId = ref<string>(createId());
    const firstQuestion = computed(
        () =>
            messages.value.find((message) => message.role === "user")?.text ??
            translate("ask.currentConversation"),
    );
    let messageId = 0;

    async function send(question: string): Promise<boolean> {
        const text = question.trim();
        if (!text || busy.value) {
            return false;
        }
        busy.value = true;
        messages.value.push({ id: ++messageId, role: "user", text });
        try {
            const reply = await request(text, conversationId.value);
            if (
                reply.conversation_id &&
                /^[a-f\d]{8}(?:-[a-f\d]{4}){3}-[a-f\d]{12}$/i.test(
                    reply.conversation_id,
                )
            ) {
                conversationId.value = reply.conversation_id;
            }
            messages.value.push({
                id: ++messageId,
                role: "assistant",
                text: reply.answer,
                invoice: reply.intent === "invoice",
                ...(reply.proposal ? { proposal: reply.proposal } : {}),
            });
        } catch (error) {
            messages.value.push({
                id: ++messageId,
                role: "assistant",
                error: true,
                text:
                    error instanceof Error
                        ? error.message
                        : translate("ask.connection"),
            });
        } finally {
            messages.value = messages.value.slice(-40);
            busy.value = false;
        }
        return true;
    }

    function openConversation(
        id: string,
        savedMessages: ChatMessage[],
    ): boolean {
        if (busy.value || loading.value) {
            return false;
        }

        conversationId.value = id;
        messages.value = savedMessages;
        messageId = Math.max(0, ...savedMessages.map((message) => message.id));

        return true;
    }

    function startNewConversation(): boolean {
        if (busy.value || loading.value) {
            return false;
        }

        messages.value = [];
        conversationId.value = createId();
        messageId = 0;

        return true;
    }

    function clear(): boolean {
        return startNewConversation();
    }

    return {
        messages,
        busy,
        loading,
        conversationId,
        firstQuestion,
        send,
        clear,
        openConversation,
        startNewConversation,
    };
}

export function parseAskReply(
    payload: unknown,
    translate: (key: string) => string = defaultTranslate,
): AskReply {
    if (!payload || typeof payload !== "object") {
        throw new Error(translate("ask.unreadable"));
    }
    const data = payload as Record<string, unknown>;
    const intent = typeof data.intent === "string" ? data.intent : undefined;
    const answer = typeof data.answer === "string" ? data.answer.trim() : "";
    if (!answer && intent === "invoice") {
        return {
            answer: translate("ask.invoiceForm"),
            intent,
            conversation_id:
                typeof data.conversation_id === "string"
                    ? data.conversation_id
                    : undefined,
        };
    }
    if (!answer || isStructuredData(answer)) {
        throw new Error(translate("ask.noReadableAnswer"));
    }
    return {
        answer,
        intent,
        ...((intent === "action" || intent === "invoice") &&
        data.confirmation &&
        typeof data.confirmation === "object" &&
        typeof (data.confirmation as Record<string, unknown>).token === "string"
            ? {
                  proposal: {
                      intent,
                      preview: (data.preview ?? data.draft ?? {}) as Record<
                          string,
                          unknown
                      >,
                      confirmation:
                          data.confirmation as ActionProposal["confirmation"],
                  } as ActionProposal,
              }
            : {}),
        conversation_id:
            typeof data.conversation_id === "string"
                ? data.conversation_id
                : undefined,
    };
}

function isStructuredData(text: string): boolean {
    if (!text.startsWith("{") && !text.startsWith("[")) {
        return false;
    }
    try {
        const value: unknown = JSON.parse(text);
        return value !== null && typeof value === "object";
    } catch {
        return false;
    }
}

export function conversationScrollBehavior(
    reducedMotion: boolean,
): ScrollBehavior {
    return reducedMotion ? "instant" : "smooth";
}
