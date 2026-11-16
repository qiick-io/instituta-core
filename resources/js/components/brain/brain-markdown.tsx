import { cn } from '@/lib/utils';

/**
 * A brain section as written (#241): the HTML the server renders from the stored markdown
 * (`SectionHtmlRendererService`: raw HTML escaped, unsafe links dropped), styled with theme
 * tokens only. Resolved wiki and relative links point at the reading view.
 */
export function BrainMarkdown({
    html,
    className,
}: {
    html: string;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'space-y-3 text-sm leading-relaxed break-words',
                '[&_a]:text-primary [&_a]:underline [&_a]:underline-offset-4',
                '[&_blockquote]:border-l-2 [&_blockquote]:border-border [&_blockquote]:pl-3 [&_blockquote]:text-muted-foreground',
                '[&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-xs',
                '[&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:border [&_pre]:border-sidebar-border/70 [&_pre]:bg-muted/40 [&_pre]:p-3 [&_pre_code]:bg-transparent [&_pre_code]:p-0',
                '[&_h3]:pt-2 [&_h3]:font-semibold [&_h4]:font-semibold [&_h5]:font-medium [&_h6]:font-medium',
                '[&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5',
                '[&_hr]:border-border [&_li>p]:inline',
                '[&_table]:w-full [&_table]:text-left [&_td]:border-b [&_td]:border-border [&_td]:px-2 [&_td]:py-1.5 [&_th]:border-b [&_th]:border-border [&_th]:px-2 [&_th]:py-1.5 [&_th]:font-medium',
                '[&_img]:max-h-96 [&_img]:rounded-lg [&_img]:border [&_img]:border-sidebar-border/70',
                className,
            )}
            // The server escapes raw HTML and drops unsafe links before sending it.
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}
