// Escape any dynamic text before putting it inside a cellRenderer HTML string.
export const esc = (value: unknown): string =>
    String((value ?? '') as string | number | boolean).replace(
        /[&<>"']/g,
        (c) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[c]!,
    );
