import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { pathToFileURL } from 'node:url';
import ts from 'typescript';
import { createI18n } from 'vue-i18n';
import {
    detectLocale,
    resolveLocale,
    formatMoney,
    formatDate,
} from '../resources/js/lib/locale.ts';
import { vueFiles, extract } from './localization-source.mjs';

const languages = ['en', 'fr', 'de', 'es'];
const catalogs = Object.fromEntries(
    languages.map((locale) => [
        locale,
        JSON.parse(readFileSync(`resources/js/locales/${locale}.json`, 'utf8')),
    ]),
);
const placeholders = (message) =>
    [...message.matchAll(/\{(\w+)\}/g)]
        .map((match) => match[1])
        .sort((left, right) => left.localeCompare(right));

test('every application message has translations with the same named parameters', () => {
    const keys = Object.keys(catalogs.en).sort();
    for (const locale of languages) {
        assert.deepEqual(Object.keys(catalogs[locale]).sort(), keys, locale);
        for (const key of keys) {
            const value = catalogs[locale][key];
            assert.equal(typeof value, 'string', `${locale}:${key}`);
            assert.ok(value.trim(), `${locale}:${key}`);
            assert.deepEqual(
                placeholders(value),
                placeholders(catalogs.en[key]),
                `${locale}:${key}`,
            );
        }
    }
});

test('every catalog message compiles and interpolates without localization errors', () => {
    const errors = [];
    const i18n = createI18n({
        legacy: false,
        flatJson: true,
        locale: 'en',
        fallbackLocale: 'en',
        messages: structuredClone(catalogs),
        onWarn: (message) => errors.push(message),
    });
    for (const locale of languages) {
        i18n.global.locale.value = locale;
        for (const [key, message] of Object.entries(catalogs[locale])) {
            const params = Object.fromEntries(
                placeholders(message).map((name) => [name, 'VALUE']),
            );
            assert.doesNotThrow(
                () => i18n.global.t(key, params),
                `${locale}:${key}`,
            );
            assert.notEqual(
                i18n.global.t(key, params),
                key,
                `${locale}:${key}`,
            );
        }
    }
    assert.deepEqual(errors, []);
});

test('all public authentication business and shared Vue interface literals use translation keys', () => {
    const remaining = vueFiles().flatMap((path) =>
        extract(path).map((entry) => `${path}: ${entry.value}`),
    );
    assert.deepEqual(remaining, []);
});

test('browser language detection handles regions priority and unsupported languages', () => {
    assert.equal(detectLocale(null, []), 'en');
    for (const [browser, locale] of [
        ['en-US', 'en'],
        ['fr-FR', 'fr'],
        ['de-DE', 'de'],
        ['es-MX', 'es'],
        ['ko-KR', 'en'],
    ]) {
        assert.equal(detectLocale(null, [browser]), locale);
    }
    assert.equal(detectLocale(null, ['ko-KR', 'fr-CA', 'es']), 'fr');
    assert.equal(detectLocale('de', ['fr-FR']), 'de');
    assert.equal(detectLocale('unsupported', ['fr-FR']), 'en');
    assert.equal(resolveLocale('es', 'de', ['fr-FR']), 'es');
    assert.equal(resolveLocale(null, 'de', ['fr-FR']), 'de');
});

test('locale formatting retains the supplied currency amount and calendar date', () => {
    for (const locale of languages) {
        const amount = '1234.50';
        const currency = 'USD';
        assert.equal(
            formatMoney(amount, currency, locale),
            new Intl.NumberFormat(locale, {
                style: 'currency',
                currency,
            }).format(1234.5),
        );
        assert.equal(
            formatDate('2026-11-11', locale),
            new Intl.DateTimeFormat(locale, {
                dateStyle: 'medium',
                timeZone: 'UTC',
            }).format(new Date('2026-11-11T00:00:00Z')),
        );
        assert.equal(amount, '1234.50');
        assert.equal(currency, 'USD');
    }
});

test('switching languages immediately changes interface messages while retaining interpolated business data', () => {
    const i18n = createI18n({
        legacy: false,
        flatJson: true,
        locale: 'en',
        messages: structuredClone(catalogs),
    });
    for (const locale of languages) {
        i18n.global.locale.value = locale;
        assert.equal(
            i18n.global.t('nav.invoices'),
            catalogs[locale]['nav.invoices'],
        );
        const invoice = i18n.global.t('invoices.title', {
            number: 'INV-USD-123',
        });
        assert.ok(invoice.includes('INV-USD-123'));
        assert.equal(
            i18n.global.t('auth.password'),
            catalogs[locale]['auth.password'],
        );
        assert.equal(
            i18n.global.t(
                'interface.paypal_sandbox_test_checkout_use_a_sandbox_buyer_account_this_is_not_a_real_money_payment',
            ),
            catalogs[locale][
                'interface.paypal_sandbox_test_checkout_use_a_sandbox_buyer_account_this_is_not_a_real_money_payment'
            ],
        );
    }
});

test('guest preferences survive refresh account login logout and cached navigation without touching appearance', async () => {
    const original = Object.fromEntries(
        ['document', 'localStorage', 'navigator', 'location'].map((key) => [
            key,
            Object.getOwnPropertyDescriptor(globalThis, key),
        ]),
    );
    const storage = new Map();
    const cookies = [];
    const meta = { 'account-locale': '', 'account-id': '' };
    const document = {
        documentElement: { lang: '' },
        querySelector: (selector) => ({
            content: meta[/name="([^"]+)"/.exec(selector)?.[1]] ?? '',
        }),
        set cookie(value) {
            cookies.push(value);
        },
        get cookie() {
            return cookies.at(-1) ?? '';
        },
    };
    Object.defineProperties(globalThis, {
        document: { value: document, configurable: true },
        localStorage: {
            value: {
                getItem: (key) => storage.get(key) ?? null,
                setItem: (key, value) => storage.set(key, value),
            },
            configurable: true,
        },
        navigator: { value: { languages: ['fr-FR'] }, configurable: true },
        location: { value: { protocol: 'https:' }, configurable: true },
    });
    try {
        let source = readFileSync('resources/js/lib/i18n.ts', 'utf8');
        source = source.replace(
            /import \{ router \} from '@inertiajs\/vue3';/,
            'const router = { on: (event, callback) => { globalThis.__localeNavigate = callback; } };',
        );
        source = source.replace(
            /import (en|fr|de|es) from '@\/locales\/\w+\.json';/g,
            (_, name) => `const ${name} = ${JSON.stringify(catalogs[name])};`,
        );
        source = source.replace(
            "from 'vue-i18n'",
            `from ${JSON.stringify(import.meta.resolve('vue-i18n'))}`,
        );
        source = source.replace(
            "from './locale'",
            `from ${JSON.stringify(pathToFileURL(`${process.cwd()}/resources/js/lib/locale.ts`).href)}`,
        );
        const code = ts.transpileModule(source, {
            compilerOptions: {
                target: ts.ScriptTarget.ES2022,
                module: ts.ModuleKind.ESNext,
            },
        }).outputText;
        const runtime = await import(
            `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`
        );
        runtime.initializeLocale();
        assert.equal(document.documentElement.lang, 'fr');
        runtime.setLocale('es', true);
        assert.equal(storage.get('mtaaflow.locale'), 'es');
        runtime.initializeLocale();
        assert.equal(document.documentElement.lang, 'es');
        const navigate = (user) =>
            globalThis.__localeNavigate({
                detail: { page: { props: { auth: { user } } } },
            });
        navigate({ id: 42, locale: 'de' });
        assert.equal(document.documentElement.lang, 'de');
        assert.equal(storage.get('mtaaflow.locale'), 'de');
        runtime.setLocale('fr', true);
        navigate({ id: 42, locale: 'de' });
        assert.equal(
            document.documentElement.lang,
            'fr',
            'old cached props cannot revert a manual selection',
        );
        navigate(null);
        runtime.initializeLocale();
        assert.equal(document.documentElement.lang, 'fr');
        storage.clear();
        runtime.setLocale('de');
        runtime.initializeLocale();
        assert.equal(
            document.documentElement.lang,
            'de',
            'the locale cookie preserves a preference when local storage is unavailable',
        );
        meta['account-locale'] = 'es';
        meta['account-id'] = '84';
        runtime.initializeLocale();
        assert.equal(
            document.documentElement.lang,
            'es',
            'a saved account preference wins on refresh',
        );
        assert.ok(
            cookies.every(
                (value) =>
                    value.startsWith('locale=') &&
                    value.includes('SameSite=Lax'),
            ),
        );
    } finally {
        for (const [key, descriptor] of Object.entries(original)) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor);
            else delete globalThis[key];
        }
        delete globalThis.__localeNavigate;
    }
});
