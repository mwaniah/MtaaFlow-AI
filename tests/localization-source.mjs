import ts from 'typescript';
import { parse } from '@vue/compiler-sfc';
import { parse as parseTemplate } from '@vue/compiler-dom';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

export function vueFiles(directory = 'resources/js') {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);
        return entry.isDirectory() &&
            !['actions', 'routes', 'wayfinder'].includes(entry.name)
            ? vueFiles(path)
            : entry.name.endsWith('.vue')
              ? [path]
              : [];
    });
}

const attributes = new Set([
    'title',
    'description',
    'placeholder',
    'aria-label',
    'alt',
    'label',
    'empty-text',
]);
const properties = new Set([
    'title',
    'description',
    'text',
    'label',
    'message',
    'headerName',
    'emptyText',
    'buttonText',
]);
const exempt =
    /^(?:MtaaFlow(?: AI)?|Ask MtaaFlow|ASK MTAAFLOW|AI|M|PayPal(?: Sandbox)?|English|Français|Deutsch|Español|SKU|PDF|USD|EUR|KES|GBP|JPY|INV-[\w-]+|Invoices\/Generate|\d[\d\s.,%+−–-]*|[\s\W]*)$/u;
const human = (value) =>
    /[A-Za-z]/.test(value) &&
    !exempt.test(value) &&
    !/^[a-z_]\w*(?:\.[a-z_]\w*)+\.?$|^[a-z_]+\.$/.test(value) &&
    !value.startsWith('@/');

export function extract(path, source = readFileSync(path, 'utf8')) {
    const { descriptor } = parse(source, { filename: path });
    const entries = [];
    const add = (start, end, value, type) => {
        value = value.replace(/\s+/g, ' ').trim();
        if (human(value)) entries.push({ start, end, value, type });
    };
    function literals(expression, offset, all = false) {
        const file = ts.createSourceFile(
            'expression.ts',
            expression,
            ts.ScriptTarget.Latest,
            true,
            ts.ScriptKind.TS,
        );
        function visit(node) {
            if (
                ts.isStringLiteral(node) ||
                ts.isNoSubstitutionTemplateLiteral(node)
            ) {
                const parent = node.parent;
                const property = ts.isPropertyAssignment(parent)
                    ? parent.name?.getText(file).replace(/['"]/g, '')
                    : '';
                if (
                    property === 'description' &&
                    ['ai', 'manual'].includes(node.text)
                )
                    return;
                const call =
                    ts.isCallExpression(parent) || ts.isNewExpression(parent)
                        ? parent.expression.getText(file)
                        : '';
                const assigned = ts.isBinaryExpression(parent)
                    ? parent.left.getText(file)
                    : '';
                if (
                    ts.isBinaryExpression(parent) &&
                    [
                        ts.SyntaxKind.EqualsEqualsEqualsToken,
                        ts.SyntaxKind.ExclamationEqualsEqualsToken,
                        ts.SyntaxKind.EqualsEqualsToken,
                        ts.SyntaxKind.ExclamationEqualsToken,
                    ].includes(parent.operatorToken.kind)
                )
                    return;
                if (
                    (all ||
                        properties.has(property) ||
                        /^(?:Error|confirm|alert|toast\.(?:success|error|info|warning))$/.test(
                            call,
                        ) ||
                        /(?:error|notice|message)\.value$/.test(assigned)) &&
                    !/^(?:t|\$t|i18n\.global\.t|__|route)$/.test(call)
                ) {
                    if (!all && properties.has(property))
                        add(
                            offset + parent.getStart(file),
                            offset + parent.end,
                            node.text,
                            'property:' + property,
                        );
                    else
                        add(
                            offset + node.getStart(file),
                            offset + node.end,
                            node.text,
                            'expression',
                        );
                }
            }
            ts.forEachChild(node, visit);
        }
        visit(file);
    }
    if (descriptor.template) {
        const template = descriptor.template;
        const ast = parseTemplate(template.content);
        const visit = (node) => {
            if (node.type === 2)
                add(
                    template.loc.start.offset + node.loc.start.offset,
                    template.loc.start.offset + node.loc.end.offset,
                    node.content,
                    'text',
                );
            if (node.type === 5)
                literals(
                    node.content.content,
                    template.loc.start.offset + node.content.loc.start.offset,
                    true,
                );
            for (const prop of node.props ?? []) {
                if (prop.type === 6 && prop.value && attributes.has(prop.name))
                    add(
                        template.loc.start.offset + prop.loc.start.offset,
                        template.loc.start.offset + prop.loc.end.offset,
                        prop.value.content,
                        'attribute:' + prop.name,
                    );
                if (
                    prop.type === 7 &&
                    prop.exp &&
                    prop.name === 'bind' &&
                    attributes.has(prop.arg?.content)
                )
                    literals(
                        prop.exp.content,
                        template.loc.start.offset + prop.exp.loc.start.offset,
                        true,
                    );
            }
            for (const child of node.children ?? [])
                if (typeof child === 'object') visit(child);
            if (node.type === 8)
                for (const child of node.children ?? [])
                    if (typeof child === 'object') visit(child);
            if (node.branches)
                for (const branch of node.branches) visit(branch);
        };
        visit(ast);
    }
    if (descriptor.scriptSetup)
        literals(
            descriptor.scriptSetup.content,
            descriptor.scriptSetup.loc.start.offset,
        );
    return [
        ...new Map(
            entries.map((entry) => [entry.start + ':' + entry.end, entry]),
        ).values(),
    ];
}
