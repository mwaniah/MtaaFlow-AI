import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import { pathToFileURL } from 'node:url';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createRenderer, nextTick } from 'vue';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const moduleUrl = (code) =>
    `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
const transpile = (code) =>
    ts.transpileModule(code, {
        compilerOptions: {
            module: ts.ModuleKind.ESNext,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const translations = moduleUrl(`
export const i18n = { global: { locale: { value: 'en' } } };
export const translate = (key, values) => key === 'payments.verifiedNotice' ? 'Verified ' + values.amount : key;
export const statusLabel = value => value;
`);
const stub = moduleUrl(`
export default () => null;
export const Head = () => null;
export const ArrowUpRight = () => null;
export const CheckCircle2 = () => null;
export const FileText = () => null;
export const LockKeyhole = () => null;
export const ShieldCheck = () => null;
export const date = value => value;
export const money = (value, currency) => currency + ' ' + value;
`);
const wayfinderUrl = new URL(
    '../resources/js/wayfinder/index.ts',
    import.meta.url,
).href;
const paypalRoutes = moduleUrl(
    transpile(
        await readFile(
            new URL(
                '../resources/js/routes/invoice-shares/paypal/index.ts',
                import.meta.url,
            ),
            'utf8',
        ),
    ).replace(/from ['"][./]+wayfinder['"]/g, `from '${wayfinderUrl}'`),
);
const publicRoutes = moduleUrl(
    transpile(
        await readFile(
            new URL(
                '../resources/js/routes/invoice-shares/index.ts',
                import.meta.url,
            ),
            'utf8',
        ),
    )
        .replace(/from ['"][./]+wayfinder['"]/g, `from '${wayfinderUrl}'`)
        .replace(/from ['"]\.\/paypal['"]/g, `from '${paypalRoutes}'`),
);
const api = moduleUrl(
    transpile(
        await readFile(
            new URL('../resources/js/lib/api.ts', import.meta.url),
            'utf8',
        ),
    ).replace(/from ['"]\.\/i18n['"]/g, `from '${translations}'`),
);
const { descriptor, errors } = parse(
    await readFile(
        new URL('../resources/js/pages/Invoices/Public.vue', import.meta.url),
        'utf8',
    ),
);
assert.deepEqual(errors, []);
const script = compileScript(descriptor, {
    id: 'public-invoice-checkout',
    inlineTemplate: true,
});
const code = transpile(script.content).replace(
    /from ['"]([^'"]+)['"]/g,
    (statement, name) => {
        const modules = {
            vue: pathToFileURL(require.resolve('vue')).href,
            '@/lib/i18n': translations,
            '@/lib/api': api,
            '@/routes/invoice-shares': publicRoutes,
            '@/routes/invoice-shares/paypal': paypalRoutes,
        };
        return `from '${modules[name] ?? stub}'`;
    },
);
const PublicInvoice = (await import(moduleUrl(code))).default;
const shareToken = 's'.repeat(64);
const invoice = {
    invoice_number: 'PUBLIC-001',
    customer_name: 'Customer',
    issue_date: '2026-11-10',
    due_date: null,
    currency: 'USD',
    items: [],
    subtotal: '100.00',
    total: '100.00',
    payments_received: '0.00',
    remaining_balance: '100.00',
    status: 'approved',
};
const checkout = {
    available: true,
    reason: null,
    reason_code: null,
    mode: 'sandbox',
    order_id: null,
};

function node(type, text = '') {
    return {
        type,
        text,
        props: {},
        children: [],
        parent: null,
        addEventListener() {},
        removeEventListener() {},
    };
}

function insert(child, parent, anchor = null) {
    if (child.parent) {
        child.parent.children.splice(child.parent.children.indexOf(child), 1);
    }
    const position = anchor ? parent.children.indexOf(anchor) : -1;
    parent.children.splice(
        position < 0 ? parent.children.length : position,
        0,
        child,
    );
    child.parent = parent;
}

function find(root, predicate) {
    if (predicate(root)) return root;
    for (const child of root.children) {
        const found = find(child, predicate);
        if (found) return found;
    }
}

function textContent(root) {
    if (root.type === 'comment') return '';
    return root.text + root.children.map(textContent).join('');
}

function mount(context, responses) {
    const requests = [];
    const navigations = [];
    let app;
    const originalDocument = Object.getOwnPropertyDescriptor(
        globalThis,
        'document',
    );
    const originalWindow = Object.getOwnPropertyDescriptor(
        globalThis,
        'window',
    );
    context.after(() => {
        app?.unmount();
        for (const [name, descriptor] of [
            ['document', originalDocument],
            ['window', originalWindow],
        ]) {
            if (descriptor) Object.defineProperty(globalThis, name, descriptor);
            else Reflect.deleteProperty(globalThis, name);
        }
    });
    context.mock.method(globalThis, 'fetch', async (url, options) => {
        requests.push({ url, ...options });
        assert.ok(responses.length, `Unexpected request: ${url}`);
        const response = responses.shift();
        return new Response(JSON.stringify(response), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    });
    globalThis.document = {
        cookie: 'XSRF-TOKEN=checkout-csrf',
        activeElement: null,
    };
    globalThis.window = {
        location: {
            href: `http://localhost/shared/invoices/${shareToken}`,
            assign: (url) => navigations.push(url),
        },
        addEventListener() {},
        removeEventListener() {},
    };
    const renderer = createRenderer({
        createElement: node,
        createText: (text) => node('text', text),
        createComment: (text) => node('comment', text),
        setText: (target, text) => (target.text = text),
        setElementText: (target, text) => (target.text = text),
        patchProp: (target, key, previous, value) =>
            (target.props[key] = value),
        insert,
        remove: (target) => {
            target.parent.children.splice(
                target.parent.children.indexOf(target),
                1,
            );
        },
        parentNode: (target) => target.parent,
        nextSibling: (target) =>
            target.parent?.children[target.parent.children.indexOf(target) + 1],
        insertStaticContent: (content, parent, anchor) => {
            const target = node('static', content);
            insert(target, parent, anchor);
            return [target, target];
        },
    });
    const root = node('root');
    app = renderer.createApp(PublicInvoice, {
        token: shareToken,
        invoice: { ...invoice },
        checkout: { ...checkout },
    });
    app.directive('reveal', {});
    app.mount(root);
    return {
        root,
        requests,
        navigations,
        async submit() {
            await find(root, (target) => target.type === 'form').props.onSubmit(
                {
                    preventDefault() {},
                },
            );
            await nextTick();
        },
    };
}

test('guest checkout requests Laravel JSON then navigates to the unchanged PayPal approval URL', async (context) => {
    const approvalUrl =
        'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1&useraction=commit&locale.x=fr_FR';
    const view = mount(context, [
        {
            order_id: 'ORDER-1',
            approval_url: approvalUrl,
            amount: '100.00',
            currency: 'USD',
            status: 'pending',
        },
    ]);

    await view.submit();

    assert.equal(
        view.requests[0].url,
        `/shared/invoices/${shareToken}/paypal/orders`,
    );
    assert.equal(view.requests[0].method, 'POST');
    assert.equal(view.requests[0].headers.Accept, 'application/json');
    assert.equal(view.requests[0].headers['X-XSRF-TOKEN'], 'checkout-csrf');
    assert.equal(view.requests[0].credentials, 'same-origin');
    assert.deepEqual(view.navigations, [approvalUrl]);
});

test('an already verified checkout refreshes the invoice without returning to PayPal', async (context) => {
    const view = mount(context, [
        {
            order_id: 'ORDER-1',
            approval_url: null,
            amount: '100.00',
            currency: 'USD',
            status: 'completed',
        },
        {
            invoice: {
                ...invoice,
                status: 'paid',
                payments_received: '100.00',
                remaining_balance: '0.00',
            },
            checkout: { ...checkout, available: false },
        },
    ]);

    await view.submit();

    assert.deepEqual(view.navigations, []);
    assert.equal(view.requests.length, 2);
    assert.equal(view.requests[1].method, 'GET');
    assert.equal(view.requests[1].url, `/shared/invoices/${shareToken}`);
    assert.equal(
        textContent(
            find(view.root, (target) => target.props.role === 'status'),
        ),
        'Verified USD 100.00',
    );
    assert.equal(
        find(view.root, (target) => target.props.role === 'alert'),
        undefined,
    );
});

test('a buyer-approved checkout requests verified capture before showing payment success', async (context) => {
    const view = mount(context, [
        {
            order_id: 'ORDER-1',
            approval_url: null,
            amount: '100.00',
            currency: 'USD',
            status: 'pending',
            payer_approved: true,
        },
        { payment: { status: 'completed', amount: '100.00', currency: 'USD' } },
        {
            invoice: {
                ...invoice,
                status: 'paid',
                payments_received: '100.00',
                remaining_balance: '0.00',
            },
            checkout: { ...checkout, available: false },
        },
    ]);

    await view.submit();

    assert.deepEqual(view.navigations, []);
    assert.equal(
        view.requests[1].url,
        `/shared/invoices/${shareToken}/paypal/orders/ORDER-1/capture`,
    );
    assert.equal(view.requests[1].method, 'POST');
    assert.equal(
        textContent(
            find(view.root, (target) => target.props.role === 'status'),
        ),
        'Verified USD 100.00',
    );
    assert.equal(
        find(view.root, (target) => target.props.role === 'alert'),
        undefined,
    );
});

test('buyer approval alone does not display verified payment success', async (context) => {
    const view = mount(context, [
        {
            order_id: 'ORDER-1',
            approval_url: null,
            amount: '100.00',
            currency: 'USD',
            status: 'pending',
            payer_approved: true,
        },
        { payment: { status: 'pending', amount: '100.00', currency: 'USD' } },
    ]);

    await view.submit();

    assert.deepEqual(view.navigations, []);
    assert.equal(view.requests.length, 2);
    assert.equal(
        textContent(
            find(view.root, (target) => target.props.role === 'status'),
        ),
        'interface.your_payment_has_not_been_confirmed_you_can_check_again_without_starting_another_checkout',
    );
    assert.equal(
        find(
            view.root,
            (target) =>
                target.props.onClick &&
                textContent(target) === 'interface.check_payment_status',
        ).props.disabled,
        false,
    );
});
