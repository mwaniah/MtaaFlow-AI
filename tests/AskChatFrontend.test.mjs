import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import { pathToFileURL } from 'node:url';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { renderAssistantMarkdown } from '../resources/js/lib/assistantMarkdown.ts';
import {
    conversationScrollBehavior,
    createConversationId,
    parseAskReply,
    useAskConversation,
} from '../resources/js/composables/useAskConversation.ts';

const chatId = '79423dcf-39d0-4ba1-9f49-321e6a517980';
const freshId = 'ecc6e9b2-3bb0-4368-a032-89e828591098';
const require = createRequire(import.meta.url);
const translationModule = `data:text/javascript;base64,${Buffer.from(`
import en from ${JSON.stringify(new URL('../resources/js/locales/en.json', import.meta.url).href)} with { type: 'json' };
import { createI18n } from ${JSON.stringify(import.meta.resolve('vue-i18n'))};
const i18n = createI18n({ legacy: false, flatJson: true, locale: 'en', messages: { en } });
export const translate = key => i18n.global.t(key);
`).toString('base64')}`;

async function component(name) {
    const filename = new URL(
        `../resources/js/components/${name}.vue`,
        import.meta.url,
    );
    const source = await readFile(filename, 'utf8');
    const { descriptor, errors } = parse(source, {
        filename: filename.pathname,
    });
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, {
        id: name,
        inlineTemplate: true,
    });
    const compiled = ts.transpileModule(script.content, {
        compilerOptions: {
            module: ts.ModuleKind.ESNext,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const code = compiled
        .replace(
            /from ["']@\/lib\/i18n["']/g,
            `from ${JSON.stringify(translationModule)}`,
        )
        .replace(
            /from ["']vue["']/g,
            `from ${JSON.stringify(pathToFileURL(require.resolve('vue')).href)}`,
        )
        .replace(
            /from ["']@\/lib\/assistantMarkdown["']/g,
            `from ${JSON.stringify(new URL('../resources/js/lib/assistantMarkdown.ts', import.meta.url).href)}`,
        );
    return (
        await import(
            `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`
        )
    ).default;
}

const markdown = (text) =>
    renderToString(
        createSSRApp({
            render: () => h('div', {}, renderAssistantMarkdown(text)),
        }),
    );

void test('creates a valid chat UUID when randomUUID is unavailable on a local HTTP origin', () => {
    const id = createConversationId({
        getRandomValues: (bytes) => bytes.fill(1),
    });

    assert.equal(id, '01010101-0101-4101-8101-010101010101');
});

void test('accepts a previously unseen question and preserves its natural-language answer', async () => {
    const calls = [];
    const chat = useAskConversation(
        async (...args) => {
            calls.push(args);
            return parseAskReply({
                intent: 'question',
                answer: 'Try a small pilot engagement before a retainer.',
                conversation_id: chatId,
            });
        },
        () => chatId,
    );

    await chat.send(
        '  How can I sell a pilot engagement to an overseas studio?  ',
    );

    assert.deepEqual(calls, [
        ['How can I sell a pilot engagement to an overseas studio?', chatId],
    ]);
    assert.equal(
        chat.messages.value[1].text,
        'Try a small pilot engagement before a retainer.',
    );
    assert.equal(chat.messages.value[1].error, undefined);
});

void test('keeps one pending request, rejects duplicate submissions and removes pending on success', async () => {
    let finish;
    let count = 0;
    const chat = useAskConversation(
        () => {
            count++;
            return new Promise((resolve) => {
                finish = resolve;
            });
        },
        () => chatId,
    );

    const pending = chat.send('Explain profit margins');
    assert.equal(chat.busy.value, true);
    assert.equal(await chat.send('Explain profit margins'), false);
    assert.equal(chat.clear(), false);
    assert.equal(count, 1);
    assert.equal(chat.messages.value.length, 1);
    finish({ answer: 'Profit margin is profit divided by revenue.' });
    await pending;

    assert.equal(chat.busy.value, false);
    assert.equal(chat.messages.value.length, 2);
});

void test('removes pending after a request failure and permits another question', async () => {
    let count = 0;
    const chat = useAskConversation(
        async () => {
            if (++count === 1) {
                throw new Error(
                    'The AI service is temporarily unavailable. Please try again.',
                );
            }
            return { answer: 'Here is a reminder you can adapt.' };
        },
        () => chatId,
    );

    await chat.send('Draft a payment reminder');
    assert.equal(chat.busy.value, false);
    assert.equal(chat.messages.value[1].error, true);
    assert.match(chat.messages.value[1].text, /temporarily unavailable/);
    await chat.send('Try again');

    assert.equal(count, 2);
    assert.equal(chat.busy.value, false);
    assert.equal(chat.messages.value[3].error, undefined);
});

void test('keeps the active chat UUID for follow-ups and replaces it when clearing', async () => {
    const ids = [chatId, freshId];
    const requests = [];
    const chat = useAskConversation(
        async (question, id) => {
            requests.push({ question, id });
            return { answer: 'Focus on referrals.' };
        },
        () => ids.shift(),
    );

    await chat.send('How can I grow my business?');
    await chat.send('Tell me more');
    assert.deepEqual(
        requests.map((request) => request.id),
        [chatId, chatId],
    );
    assert.equal(chat.clear(), true);

    assert.equal(chat.conversationId.value, freshId);
    assert.deepEqual(chat.messages.value, []);
});

void test('ignores empty input and bounds the active in-memory display', async () => {
    let count = 0;
    const chat = useAskConversation(
        async () => {
            count++;
            return { answer: 'A useful answer.' };
        },
        () => chatId,
    );

    assert.equal(await chat.send('   '), false);
    for (let index = 0; index < 23; index++) {
        await chat.send(`Question ${index}`);
    }

    assert.equal(count, 23);
    assert.equal(chat.messages.value.length, 40);
    assert.equal(chat.messages.value[0].text, 'Question 3');
});

void test('renders the account answer without accepting raw records or customer metadata', () => {
    const reply = parseAskReply({
        intent: 'account_read',
        answer: 'You currently have 1 customer in your MtaaFlow account.',
        data: {
            count: 1,
            customers: [{ email: 'private@example.test', phone: '555-0100' }],
        },
    });

    assert.equal(
        reply.answer,
        'You currently have 1 customer in your MtaaFlow account.',
    );
    assert.equal(JSON.stringify(reply).includes('private@example.test'), false);
    assert.equal(Object.hasOwn(reply, 'data'), false);
});

void test('rejects malformed and raw JSON answers instead of showing structured data', () => {
    for (const payload of [
        null,
        { data: { customers: [] } },
        { answer: { count: 1 } },
        { answer: '{"count":1,"customers":[]}' },
        { answer: '[{"email":"private@example.test"}]' },
    ]) {
        assert.throws(() => parseAskReply(payload), /readable|unreadable/);
    }
});

void test('keeps invoice drafting as an explicit form review rather than an automatic action', () => {
    const reply = parseAskReply({ intent: 'invoice', conversation_id: chatId });

    assert.equal(reply.intent, 'invoice');
    assert.equal(reply.conversation_id, chatId);
    assert.match(reply.answer, /explicitly confirm/);
    assert.match(reply.answer, /No invoice has been created/);
});

void test('renders paragraphs, lists, emphasis, headings and escaped code readably', async () => {
    const html = await markdown(
        '### Improve cash flow\n\nUse **clear terms** and *friendly reminders*.\n\n1. Agree a deposit\n2. Send an invoice\n\n- Track due dates\n- Follow up\n\nUse `revenue - expenses`.\n\n```text\n<invoice total="250">\n```',
    );

    assert.match(html, /<h3>Improve cash flow<\/h3>/);
    assert.match(html, /<strong>clear terms<\/strong>/);
    assert.match(html, /<em>friendly reminders<\/em>/);
    assert.match(
        html,
        /<ol start="1"><li>Agree a deposit<\/li><li>Send an invoice<\/li><\/ol>/,
    );
    assert.match(html, /<ul><li>Track due dates<\/li>/);
    assert.match(html, /<code>revenue - expenses<\/code>/);
    assert.match(html, /&lt;invoice total=/);
});

void test('escapes HTML and refuses executable, relative and credential-bearing Markdown links', async () => {
    const html = await markdown(
        '<script>alert("xss")</script>\n\n<img src=x onerror=alert(1)>\n\n[bad](javascript:alert) [data](data:text/html,payload) [relative](/admin) [credentials](https://user:secret@example.test/) [encoded](jav&#x61;script:alert)',
    );

    assert.equal(html.includes('<script>'), false);
    assert.equal(html.includes('<img'), false);
    assert.equal(html.includes('<a'), false);
    assert.match(html, /&lt;script&gt;/);
});

void test('allows only explicit HTTP(S) links with safe external navigation attributes', async () => {
    const html = await markdown(
        '[PayPal help](https://www.paypal.com/us/cshelp/) and [Reference](http://example.test/path?q=one&x=two)',
    );

    assert.match(html, /href="https:\/\/www.paypal.com\/us\/cshelp\/"/);
    assert.match(html, /rel="noopener noreferrer"/);
    assert.match(html, /referrerpolicy="no-referrer"/);
    assert.match(html, /q=one&amp;x=two/);
});

void test('renders escaped customer names literally without turning them into links or emphasis', async () => {
    const html = await markdown(
        String.raw`Your customers:\n\n- \[Client\](https://example.test)\n- \*\*Premium\*\* Studio\n- \_Partner\_ & Associates`.replaceAll(
            '\\n',
            '\n',
        ),
    );

    assert.equal(html.includes('<a '), false);
    assert.equal(html.includes('<strong>'), false);
    assert.equal(html.includes('<em>'), false);
    assert.equal(html.includes('\\'), false);
    assert.match(html, /\[Client\]\(https:\/\/example.test\)/);
    assert.match(html, /\*\*Premium\*\* Studio/);
    assert.match(html, /_Partner_ &amp; Associates/);
});

void test('preserves deliberate formatting around escaped names and literal backslashes in code', async () => {
    const html = await markdown(
        String.raw`**Customer: \[Client\](https://example.test)** and \*literal\* punctuation.` +
            ' Use `C:\\Invoices\\` and `\\*`.',
    );

    assert.match(
        html,
        /<strong>Customer: \[Client\]\(https:\/\/example.test\)<\/strong>/,
    );
    assert.equal(html.includes('<a '), false);
    assert.match(html, /\*literal\* punctuation/);
    assert.equal(html.includes('<code>C:\\Invoices\\</code>'), true);
    assert.equal(html.includes('<code>\\*</code>'), true);
});

void test('escapes a literal name containing HTML even when its Markdown punctuation is escaped', async () => {
    const AssistantMessage = await component('AssistantMessage');
    const html = await renderToString(
        createSSRApp(AssistantMessage, {
            text: String.raw`\[\<img src=x onerror=alert(1)\>\](https://example.test)`,
        }),
    );

    assert.equal(html.includes('<a '), false);
    assert.equal(html.includes('<img'), false);
    assert.equal(html.includes('\\'), false);
    assert.match(
        html,
        /\[&lt;img src=x onerror=alert\(1\)&gt;\]\(https:\/\/example.test\)/,
    );
});

void test('compiles the production assistant message component and escapes model-controlled content', async () => {
    const AssistantMessage = await component('AssistantMessage');
    const html = await renderToString(
        createSSRApp(AssistantMessage, {
            text: '**Clear terms** <svg onload="alert(1)"></svg>',
        }),
    );

    assert.match(html, /<strong>Clear terms<\/strong>/);
    assert.equal(html.includes('<svg'), false);
    assert.match(html, /&lt;svg/);
});

void test('renders the reusable thinking indicator with three decorative dots and an accessible status', async () => {
    const AssistantThinking = await component('AssistantThinking');
    const html = await renderToString(createSSRApp(AssistantThinking));

    assert.match(html, /role="status"/);
    assert.match(html, /aria-live="polite"/);
    assert.match(html, /Thinking/);
    assert.equal(
        (html.match(/class="assistant-thinking__dot"/g) ?? []).length,
        3,
    );
    assert.equal(html.includes('Checking your request'), false);
});

void test('uses immediate scrolling for reduced motion and smooth scrolling otherwise', () => {
    assert.equal(conversationScrollBehavior(true), 'instant');
    assert.equal(conversationScrollBehavior(false), 'smooth');
});
