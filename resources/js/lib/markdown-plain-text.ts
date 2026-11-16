/**
 * One line of readable text from a markdown summary (#241), for lists and page descriptions
 * where markup would show as raw symbols: drops quote markers, emphasis, inline code ticks and
 * heading hashes, and keeps the text of wiki and markdown links.
 */
export function markdownToPlainText(markdown: string): string {
    return markdown
        .replace(/\[\[([^\]|]+)\|([^\]]+)\]\]/g, '$2')
        .replace(/\[\[([^\]]+)\]\]/g, '$1')
        .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/^\s{0,3}(?:>\s?|#{1,6}\s+|[-*+]\s+)/gm, '')
        .replace(/(\*\*|__|\*|_|~~|`)/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}
