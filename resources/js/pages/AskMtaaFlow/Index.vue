<script setup lang="ts">
import { translate as t } from '@/lib/i18n';
import { computed, nextTick, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    ArrowUp,
    CircleHelp,
    FileText,
    History,
    Plus,
    ShieldCheck,
    Sparkles,
    Trash2,
} from '@lucide/vue';
import AssistantMessage from '@/components/AssistantMessage.vue';
import AssistantAction from '@/components/AssistantAction.vue';
import { i18n } from '@/lib/i18n';
import AssistantThinking from '@/components/AssistantThinking.vue';
import {
    conversationScrollBehavior,
    parseAskReply,
    useAskConversation,
    type AskReply,
    type ChatMessage,
} from '@/composables/useAskConversation';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ask } from '@/routes/ai';
import { create as createInvoice } from '@/routes/invoices';

type ErrorResponse = {
    message?: string;
    errors?: Record<string, string[]>;
};

const page = usePage();
const firstName = computed(
    () => page.props.auth.user.name.split(' ')[0] || 'there',
);
const suggestions = [
    {
        get tag() {
            return t('ask.growth');
        },
        get text() {
            return t('interface.how_can_i_attract_international_clients');
        },
    },
    {
        get tag() {
            return t('ask.writing');
        },
        get text() {
            return t('interface.help_me_draft_a_professional_payment_reminder');
        },
    },
    {
        get tag() {
            return t('ask.business');
        },
        get text() {
            return t('interface.how_many_customers_do_i_have');
        },
    },
    {
        get tag() {
            return t('ask.currency');
        },
        get text() {
            return t('interface.how_much_is_2_dollars_in_kenyan_shillings');
        },
    },
    {
        get tag() {
            return t('ask.cashFlow');
        },
        get text() {
            return t('interface.which_invoices_have_an_outstanding_balance');
        },
    },
];
const input = ref('');
const composing = ref(false);
const showClear = ref(false);
const scroller = ref<HTMLElement | null>(null);
const composer = ref<HTMLTextAreaElement | null>(null);
const {
    messages,
    busy,
    loading,
    conversationId,
    firstQuestion,
    send: sendQuestion,
    clear,
    openConversation,
} = useAskConversation(getAnswer, undefined, t);
const hasConversation = computed(() => messages.value.length > 0);

type SavedConversation = {
    id: string;
    title: string;
    created_at: string;
    updated_at: string;
};

type SavedMessage = {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    metadata: Record<string, unknown> | null;
};

const conversations = ref<SavedConversation[]>([]);
const historyError = ref('');
const historyLoaded = ref(false);

async function loadConversations() {
    try {
        const response = await fetch('/ai/conversations', {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error('Unable to load conversations.');
        }

        const data = await response.json();
        conversations.value = data.conversations;
        historyError.value = '';
    } catch {
        historyError.value = 'Could not load conversation history.';
    } finally {
        historyLoaded.value = true;
    }
}

async function selectConversation(id: string) {
    if (busy.value || loading.value) return;

    loading.value = true;
    historyError.value = '';

    try {
        const response = await fetch(
            `/ai/conversations/${encodeURIComponent(id)}`,
            {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        if (!response.ok) {
            throw new Error('Unable to open conversation.');
        }

        const data = await response.json();

        const savedMessages: ChatMessage[] = (
            data.messages as SavedMessage[]
        ).map((message) => {
            const metadata = message.metadata ?? {};
            const intent = metadata.intent;

            const proposal =
                metadata.proposal && typeof metadata.proposal === 'object'
                    ? (metadata.proposal as ChatMessage['proposal'])
                    : undefined;

            return {
                id: message.id,
                role: message.role,
                text: message.content,
                invoice: intent === 'invoice',
                ...(proposal ? { proposal } : {}),
            };
        });

        loading.value = false;

        if (openConversation(id, savedMessages)) {
            input.value = '';
            void toBottom();
        }
    } catch {
        historyError.value = 'Could not open this conversation.';
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await loadConversations();

    // Open the most recently updated saved conversation.
    if (conversations.value.length > 0 && !busy.value) {
        await selectConversation(conversations.value[0].id);
    }
});

async function getAnswer(
    question: string,
    conversationId: string,
): Promise<AskReply> {
    const cookie = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    const response = await fetch(ask.url(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': cookie ? decodeURIComponent(cookie[1]) : '',
        },
        body: JSON.stringify({
            question,
            conversation_id: conversationId,
            locale: i18n.global.locale.value,
        }),
    });
    let payload: unknown;
    try {
        payload = await response.json();
    } catch {
        throw new Error(
            t(
                'interface.the_server_returned_an_unreadable_response_please_try_again',
            ),
        );
    }
    if (!response.ok) {
        if (response.status === 401) {
            throw new Error(
                t('interface.you_are_signed_out_log_in_again_to_continue'),
            );
        }
        if (response.status === 419) {
            throw new Error(
                t(
                    'interface.your_session_expired_refresh_the_page_and_try_again',
                ),
            );
        }
        if (response.status === 429) {
            throw new Error(
                t(
                    'interface.too_many_requests_please_wait_a_minute_and_try_again',
                ),
            );
        }
        const error =
            payload && typeof payload === 'object'
                ? (payload as ErrorResponse)
                : {};
        const validation = Object.values(error.errors ?? {})
            .flat()
            .filter((value) => typeof value === 'string')
            .join(' ');
        throw new Error(
            validation ||
                (typeof error.message === 'string'
                    ? error.message
                    : t('errors.assistant')),
        );
    }
    return parseAskReply(payload, t);
}

const toBottom = () =>
    nextTick(() => {
        scroller.value?.scrollTo({
            top: scroller.value.scrollHeight,
            behavior: conversationScrollBehavior(
                window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            ),
        });
    });

async function send(text?: string) {
    const question = (text ?? input.value).trim();

    if (!question || busy.value || loading.value || composing.value) {
        return;
    }

    const request = sendQuestion(question);

    input.value = '';
    void toBottom();

    await request;
    await loadConversations();

    void toBottom();
}

function handleComposerKeydown(event: KeyboardEvent) {
    if (
        event.key === 'Enter' &&
        !event.shiftKey &&
        !event.isComposing &&
        !composing.value &&
        event.keyCode !== 229
    ) {
        event.preventDefault();
        void send();
    }
}

function newChat() {
    if (clear()) {
        input.value = '';
        historyError.value = '';
        void nextTick(() => composer.value?.focus());
    }
}
function confirmClear() {
    newChat();
    showClear.value = false;
}
</script>
<template>
    <div class="mf-page mf-reveal !gap-5">
        <Head title="Ask MtaaFlow" />
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="mf-kicker mb-2">
                    {{ t('interface.a_little_less_admin') }}
                </p>
                <h1
                    class="font-display text-3xl font-semibold tracking-[-0.045em] sm:text-4xl"
                >
                    {{ t('ask.copilot') }}
                </h1>
            </div>
            <span
                class="inline-flex items-center gap-2 rounded-full border border-border bg-card/80 px-3 py-2 text-xs font-medium text-muted-foreground"
                ><ShieldCheck
                    class="size-4 text-[#0f7d58] dark:text-[#8bdbb4]"
                    aria-hidden="true"
                />{{ t('interface.your_records_your_workspace') }}</span
            >
        </div>
        <div
            class="flex min-h-[650px] min-w-0 gap-5 lg:h-[calc(100dvh-15rem)] lg:min-h-[620px]"
        >
            <aside
                class="mf-glass hidden w-[225px] shrink-0 flex-col p-4 xl:flex"
                :aria-label="t('interface.conversation_tools')"
            >
                <button
                    class="mf-button w-full gap-2"
                    :disabled="busy"
                    @click="newChat"
                >
                    <Plus class="size-4" aria-hidden="true" />{{ t('ask.new') }}
                </button>
                <div
                    class="mt-8 flex items-center gap-2 px-2 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    <History class="size-3.5" aria-hidden="true" />{{
                        t('interface.this_workspace')
                    }}
                </div>

                <div class="mt-3 flex min-h-0 flex-col gap-1 overflow-y-auto">
                    <button
                        v-for="conversation in conversations"
                        :key="conversation.id"
                        type="button"
                        :disabled="busy || loading"
                        :aria-current="
                            conversation.id === conversationId
                                ? 'true'
                                : undefined
                        "
                        class="flex min-w-0 items-center gap-2 rounded-xl px-3 py-3 text-left text-xs font-medium transition hover:bg-secondary disabled:opacity-50"
                        :class="
                            conversation.id === conversationId
                                ? 'bg-secondary text-secondary-foreground'
                                : 'text-muted-foreground'
                        "
                        @click="selectConversation(conversation.id)"
                    >
                        <History class="size-3.5 shrink-0" />
                        <span class="truncate">
                            {{ conversation.title }}
                        </span>
                    </button>

                    <p
                        v-if="
                            historyLoaded &&
                            conversations.length === 0 &&
                            !historyError
                        "
                        class="px-2 py-3 text-xs text-muted-foreground"
                    >
                        No saved conversations yet.
                    </p>

                    <p
                        v-if="historyError"
                        class="px-2 py-3 text-xs text-destructive"
                    >
                        {{ historyError }}
                    </p>
                </div>

                <p
                    v-if="!hasConversation"
                    class="mt-3 px-2 text-xs leading-relaxed text-muted-foreground"
                >
                    {{
                        t(
                            'interface.your_current_conversation_will_appear_here',
                        )
                    }}
                </p>
                <div class="mt-auto pt-10">
                    <div
                        class="rounded-2xl border border-border bg-card/70 p-4"
                    >
                        <CircleHelp
                            class="mb-3 size-5 text-[#0f7d58] dark:text-[#8bdbb4]"
                            aria-hidden="true"
                        />
                        <p class="text-xs font-semibold">
                            {{ t('interface.from_question_to_clarity') }}
                        </p>
                        <p
                            class="mt-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{
                                t(
                                    'interface.explore_business_ideas_draft_messages_convert_currencies_or_ask_about_your_records_review_an_invoice_draft_before_you_confirm_it',
                                )
                            }}
                        </p>
                    </div>
                    <button
                        class="mt-3 flex min-h-11 w-full items-center gap-2 rounded-xl px-3 text-xs text-muted-foreground hover:bg-destructive/5 hover:text-destructive disabled:opacity-40"
                        :disabled="busy || !messages.length"
                        @click="showClear = true"
                    >
                        <Trash2 class="size-3.5" aria-hidden="true" />{{
                            t('ask.clear')
                        }}
                    </button>
                </div>
            </aside>
            <section
                class="mf-glass flex min-w-0 flex-1 flex-col overflow-hidden"
                :aria-label="t('interface.ask_mtaaflow_conversation')"
                :aria-busy="busy"
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-border/70 bg-card/60 px-4 py-4 sm:px-6"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-10 items-center justify-center rounded-2xl rounded-bl-sm bg-[#123d32] text-[#a8e5c5]"
                            ><Sparkles class="size-5" aria-hidden="true"
                        /></span>
                        <div>
                            <h2 class="font-display text-base font-semibold">
                                Ask MtaaFlow
                            </h2>
                            <p class="mt-0.5 text-[11px] text-muted-foreground">
                                {{
                                    t(
                                        'interface.business_guidance_your_records_when_you_need_them',
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-1 xl:hidden">
                        <button
                            class="mf-button-secondary min-h-9 gap-1.5 px-2.5 text-xs"
                            :disabled="busy"
                            @click="newChat"
                        >
                            <Plus class="size-3.5" aria-hidden="true" />{{
                                t('ask.newShort')
                            }}</button
                        ><button
                            class="mf-button-secondary min-h-9 px-2.5"
                            :aria-label="t('ask.clear')"
                            :disabled="busy || !messages.length"
                            @click="showClear = true"
                        >
                            <Trash2 class="size-3.5" aria-hidden="true" />
                        </button>
                    </div>
                </header>
                <div
                    ref="scroller"
                    class="flex min-h-0 flex-1 flex-col gap-6 overflow-x-hidden overflow-y-auto overscroll-contain p-4 sm:p-6"
                    role="log"
                    :aria-label="t('interface.conversation_messages')"
                    aria-live="polite"
                    aria-relevant="additions text"
                >
                    <div
                        v-if="!messages.length"
                        class="mx-auto flex w-full max-w-3xl flex-1 flex-col items-center justify-center py-5 text-center sm:py-8"
                    >
                        <span
                            class="relative mb-6 flex size-[76px] items-center justify-center rounded-[25px] rounded-bl-md border border-[#cde6d8] bg-gradient-to-br from-[#edf8f0] to-[#d6eddd] text-[#123d32] shadow-[0_12px_28px_-16px_#15966b66]"
                            ><Sparkles
                                class="size-8"
                                stroke-width="1.5"
                                aria-hidden="true"
                        /></span>
                        <p class="mf-kicker mb-3">
                            {{ t('common.greeting', { name: firstName }) }}
                        </p>
                        <h3
                            class="max-w-xl font-display text-[clamp(1.7rem,3vw,2.6rem)] leading-[1.1] font-semibold tracking-[-0.045em]"
                        >
                            {{ t('interface.a_clearer_picture') }}<br /><span
                                class="text-[#0f7d58] dark:text-[#8bdbb4]"
                                >{{
                                    t('interface.just_one_question_away')
                                }}</span
                            >
                        </h3>
                        <p
                            class="mt-4 max-w-md text-sm leading-relaxed text-muted-foreground"
                        >
                            {{
                                t(
                                    'interface.explore_a_new_idea_or_find_clarity_in_your_records_ask_a_question_in_your_own_words_then_keep_the_conversation_going',
                                )
                            }}
                        </p>
                        <div
                            class="mt-8 grid w-full grid-cols-1 gap-2.5 text-left sm:grid-cols-2"
                        >
                            <button
                                v-for="(suggestion, index) in suggestions"
                                :key="suggestion.tag"
                                class="group flex min-h-20 items-start justify-between gap-3 rounded-2xl border border-border bg-card px-4 py-3.5 text-left transition hover:-translate-y-0.5 hover:border-[#15966b]/50 hover:shadow-sm disabled:opacity-50"
                                :class="
                                    index === suggestions.length - 1
                                        ? 'sm:col-span-2'
                                        : ''
                                "
                                :disabled="busy"
                                @click="send(suggestion.text)"
                            >
                                <span
                                    ><span
                                        class="mb-1.5 block text-[9px] font-bold tracking-[0.12em] text-[#0f7d58] dark:text-[#8bdbb4]"
                                        >{{ suggestion.tag }}</span
                                    ><span
                                        class="block text-xs leading-relaxed font-medium"
                                        >{{ suggestion.text }}</span
                                    ></span
                                ><ArrowRight
                                    class="mt-4 size-3.5 shrink-0 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-[#0f7d58] dark:text-[#8bdbb4]"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </div>
                    <TransitionGroup
                        name="chat-message"
                        tag="div"
                        class="flex flex-col gap-6"
                    >
                        <div
                            v-for="message in messages"
                            :key="message.id"
                            class="flex min-w-0"
                            :class="
                                message.role === 'user'
                                    ? 'justify-end'
                                    : 'items-start gap-2.5 sm:gap-3.5'
                            "
                        >
                            <div
                                v-if="message.role === 'user'"
                                class="max-w-[92%] rounded-[20px] rounded-tr-sm bg-[#123d32] px-5 py-3.5 text-sm leading-relaxed break-words whitespace-pre-wrap text-white shadow-sm sm:max-w-[80%]"
                            >
                                {{ message.text }}
                            </div>
                            <template v-else>
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-xl rounded-bl-sm bg-secondary text-secondary-foreground"
                                    ><Sparkles
                                        class="size-4"
                                        aria-hidden="true"
                                /></span>
                                <article
                                    class="mf-card min-w-0 flex-1 space-y-4 rounded-tl-sm p-4 sm:p-5"
                                    :class="
                                        message.error
                                            ? 'border-destructive/20'
                                            : ''
                                    "
                                >
                                    <p
                                        v-if="message.error"
                                        class="text-xs font-semibold text-destructive"
                                    >
                                        {{ t('ask.error') }}
                                    </p>
                                    <AssistantMessage :text="message.text" />
                                    <AssistantAction
                                        v-if="message.proposal"
                                        :proposal="message.proposal"
                                    />
                                    <Link
                                        v-if="
                                            message.invoice &&
                                            !message.error &&
                                            !message.proposal
                                        "
                                        :href="createInvoice()"
                                        class="mf-button gap-2"
                                        ><FileText
                                            class="size-4"
                                            aria-hidden="true" />{{
                                            t('ask.openInvoice')
                                        }}<ArrowRight
                                            class="size-4"
                                            aria-hidden="true"
                                    /></Link>
                                </article>
                            </template>
                        </div>
                    </TransitionGroup>
                    <Transition name="chat-message">
                        <div
                            v-if="busy"
                            class="flex items-start gap-2.5 sm:gap-3.5"
                        >
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-xl rounded-bl-sm bg-secondary text-secondary-foreground"
                                ><Sparkles class="size-4" aria-hidden="true"
                            /></span>
                            <AssistantThinking :label="t('ask.thinking')" />
                        </div>
                    </Transition>
                </div>
                <footer class="border-t border-border/70 bg-card/70 p-3 sm:p-5">
                    <form
                        class="flex items-end gap-2 rounded-2xl border border-[#15966b]/30 bg-card p-1.5 shadow-[0_3px_15px_-8px_#15966b66] focus-within:border-[#15966b] focus-within:ring-2 focus-within:ring-[#15966b]/10"
                        @submit.prevent="send()"
                    >
                        <label for="ask-question" class="sr-only">{{
                            t('interface.your_question_for_mtaaflow')
                        }}</label
                        ><textarea
                            id="ask-question"
                            v-model="input"
                            ref="composer"
                            rows="1"
                            maxlength="4000"
                            @keydown="handleComposerKeydown"
                            @compositionstart="composing = true"
                            @compositionend="composing = false"
                            autocomplete="off"
                            :placeholder="t('ask.placeholder')"
                            class="max-h-36 min-h-11 min-w-0 flex-1 resize-y bg-transparent px-2.5 py-2.5 text-sm outline-none placeholder:text-muted-foreground sm:px-3"
                        ></textarea
                        ><button
                            class="mf-button min-h-10 shrink-0 gap-2 rounded-xl px-3.5 disabled:opacity-40"
                            :disabled="busy || !input.trim()"
                            :aria-label="t('ask.send')"
                        >
                            <span class="hidden sm:inline">{{
                                t('ask.ask')
                            }}</span
                            ><ArrowUp class="size-4" aria-hidden="true" />
                        </button>
                    </form>
                    <p
                        class="mt-2.5 px-1 text-center text-[10px] leading-relaxed text-muted-foreground"
                    >
                        {{
                            t(
                                'interface.mtaaflow_can_make_mistakes_check_important_numbers_before_acting_on_them',
                            )
                        }}
                    </p>
                </footer>
            </section>
        </div>
        <Dialog v-model:open="showClear"
            ><DialogContent
                ><DialogHeader
                    ><DialogTitle class="font-display text-2xl">{{
                        t('interface.make_room_for_a_fresh_start')
                    }}</DialogTitle
                    ><DialogDescription class="leading-relaxed">{{
                        t(
                            'interface.this_clears_the_messages_in_this_conversation_your_invoices_customers_and_stock_stay_in_your_account',
                        )
                    }}</DialogDescription></DialogHeader
                ><DialogFooter class="mt-3 gap-2"
                    ><button
                        class="mf-button-secondary"
                        @click="showClear = false"
                    >
                        {{ t('ask.keep') }}</button
                    ><button
                        class="mf-button !bg-destructive !bg-none"
                        :disabled="busy"
                        @click="confirmClear"
                    >
                        {{ t('ask.clear') }}
                    </button></DialogFooter
                ></DialogContent
            ></Dialog
        >
    </div>
</template>

<style scoped>
.chat-message-enter-active {
    transition:
        opacity 180ms ease,
        transform 180ms ease;
}
.chat-message-leave-active {
    transition: opacity 100ms ease;
}
.chat-message-enter-from {
    opacity: 0;
    transform: translateY(5px);
}
.chat-message-leave-to {
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .chat-message-enter-active,
    .chat-message-leave-active {
        transition: none;
    }
    .chat-message-enter-from {
        transform: none;
    }
}
</style>
