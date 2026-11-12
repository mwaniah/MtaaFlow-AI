import { h, type VNodeChild } from 'vue';

function safeLink(value: string): string | null {
    const hasControlOrSpace = Array.from(value).some(
        (character) =>
            character.charCodeAt(0) <= 0x20 || character.charCodeAt(0) === 0x7f,
    );
    if (!/^https?:\/\//i.test(value) || hasControlOrSpace) {
        return null;
    }
    try {
        const url = new URL(value);
        return url.hostname &&
            !url.username &&
            !url.password &&
            ['http:', 'https:'].includes(url.protocol)
            ? url.href
            : null;
    } catch {
        return null;
    }
}

function isEscapable(character: string): boolean {
    const code = character.charCodeAt(0);
    return (
        (code >= 33 && code <= 47) ||
        (code >= 58 && code <= 64) ||
        (code >= 91 && code <= 96) ||
        (code >= 123 && code <= 126)
    );
}

function unescapeText(text: string): string {
    let output = '';
    for (let index = 0; index < text.length; index++) {
        if (text[index] === '\\' && isEscapable(text[index + 1] ?? '')) {
            index++;
        }
        output += text[index];
    }
    return output;
}

/** Keep source offsets intact while hiding escaped delimiters from token matching. */
function maskEscapes(text: string): string {
    const characters = text.split('');
    let inCode = false;
    for (let index = 0; index < text.length; index++) {
        if (text[index] === '`') {
            inCode = !inCode;
        } else if (
            !inCode &&
            text[index] === '\\' &&
            isEscapable(text[index + 1] ?? '')
        ) {
            characters[index] = '\ue000';
            characters[++index] = '\ue000';
        }
    }
    return characters.join('');
}

function inline(text: string, depth = 0): VNodeChild[] {
    if (depth >= 5) {
        return [unescapeText(text)];
    }
    const nodes: VNodeChild[] = [];
    const tokens =
        /`([^`\n]+)`|\*\*([^\n]+?)\*\*|__([^\n]+?)__|\*([^*\n]+)\*|_([^_\n]+)_|\[([^\]\n]+)\]\(([^\s)]+)\)/dg;
    let start = 0;
    for (const match of maskEscapes(text).matchAll(tokens)) {
        const index = match.index ?? 0;
        const capture = (group: number): string => {
            const bounds = match.indices?.[group];
            return bounds ? text.slice(bounds[0], bounds[1]) : '';
        };
        if (index > start) {
            nodes.push(unescapeText(text.slice(start, index)));
        }
        if (match[1] !== undefined) {
            nodes.push(h('code', {}, capture(1)));
        } else if (match[2] !== undefined || match[3] !== undefined) {
            nodes.push(
                h(
                    'strong',
                    {},
                    inline(capture(match[2] !== undefined ? 2 : 3), depth + 1),
                ),
            );
        } else if (match[4] !== undefined || match[5] !== undefined) {
            nodes.push(
                h(
                    'em',
                    {},
                    inline(capture(match[4] !== undefined ? 4 : 5), depth + 1),
                ),
            );
        } else {
            const href = safeLink(unescapeText(capture(7)));
            nodes.push(
                href
                    ? h(
                          'a',
                          {
                              href,
                              target: '_blank',
                              rel: 'noopener noreferrer',
                              referrerpolicy: 'no-referrer',
                          },
                          inline(capture(6), depth + 1),
                      )
                    : unescapeText(text.slice(index, index + match[0].length)),
            );
        }
        start = index + match[0].length;
    }
    if (start < text.length) {
        nodes.push(unescapeText(text.slice(start)));
    }
    return nodes;
}

/** Small, text-only Markdown subset: paragraphs, headings, flat lists, emphasis,
 * inline/fenced code and HTTP(S) links. Raw HTML and images are never interpreted.
 */
export function renderAssistantMarkdown(source: string): VNodeChild[] {
    const lines = source.replace(/\r\n?/g, '\n').split('\n');
    const blocks: VNodeChild[] = [];
    let index = 0;
    while (index < lines.length) {
        const line = lines[index];
        if (!line.trim()) {
            index++;
            continue;
        }
        if (/^\s*```/.test(line)) {
            const content: string[] = [];
            index++;
            while (index < lines.length && !/^\s*```\s*$/.test(lines[index])) {
                content.push(lines[index++]);
            }
            if (index < lines.length) {
                index++;
            }
            blocks.push(h('pre', {}, [h('code', {}, content.join('\n'))]));
            continue;
        }
        const heading = line.match(/^#{1,4}\s+(.+)$/);
        if (heading) {
            blocks.push(h('h3', {}, inline(heading[1])));
            index++;
            continue;
        }
        const ordered = line.match(/^\s*(\d+)[.)]\s+(.+)$/);
        const bullet = line.match(/^\s*[-*+]\s+(.+)$/);
        if (ordered || bullet) {
            const items: VNodeChild[] = [];
            const pattern = ordered
                ? /^\s*\d+[.)]\s+(.+)$/
                : /^\s*[-*+]\s+(.+)$/;
            while (index < lines.length) {
                const item = lines[index].match(pattern);
                if (!item) {
                    break;
                }
                items.push(h('li', {}, inline(item[1])));
                index++;
            }
            blocks.push(
                h(
                    ordered ? 'ol' : 'ul',
                    ordered ? { start: Number(ordered[1]) } : {},
                    items,
                ),
            );
            continue;
        }
        const paragraph: VNodeChild[] = [];
        while (index < lines.length && lines[index].trim()) {
            if (
                paragraph.length &&
                /^(?:\s*```|#{1,4}\s|\s*[-*+]\s|\s*\d+[.)]\s)/.test(
                    lines[index],
                )
            ) {
                break;
            }
            if (paragraph.length) {
                paragraph.push(h('br'));
            }
            paragraph.push(...inline(lines[index++]));
        }
        blocks.push(h('p', {}, paragraph));
    }
    return blocks;
}
