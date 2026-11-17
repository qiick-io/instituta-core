/**
 * The explorer's file tree (#253), after the shadcn "sidebar-11" block ("A sidebar with a
 * collapsible file tree", https://ui.shadcn.com/blocks/sidebar#sidebar-11): a recursive Tree of
 * `SidebarMenu` items, folders as `Collapsible`s with a rotating chevron, files as menu buttons.
 * Adapted to brain data: files link to their reading view, the open file is active and its
 * folders start open, entries that are not active show their status.
 */
import { Link } from '@inertiajs/react';
import {
    ChevronRight,
    FileDown,
    FileImage,
    FileText,
    Folder,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
} from '@/components/ui/sidebar';
import { show as explorerShow } from '@/routes/explorer';

export type BrainFileNode = {
    kind: 'file';
    id: string;
    name: string;
    path: string;
    stable_key: string;
    status: string;
    /** `image`, `document` (PDF) and `file` (download only) are assets (#311, #315); the rest are text items. */
    type: string;
};

export type BrainFolderNode = {
    kind: 'folder';
    name: string;
    path: string;
    children: BrainTreeNode[];
};

export type BrainTreeNode = BrainFileNode | BrainFolderNode;

/**
 * Whether the folder holds the open file somewhere below it.
 */
function containsFile(node: BrainTreeNode, fileId: string | null): boolean {
    if (fileId === null) {
        return false;
    }

    return node.kind === 'file'
        ? node.id === fileId
        : node.children.some((child) => containsFile(child, fileId));
}

function TreeEntry({
    node,
    activeFileId,
    isRoot,
}: {
    node: BrainTreeNode;
    activeFileId: string | null;
    isRoot: boolean;
}) {
    const { t } = useTranslation();

    if (node.kind === 'file') {
        return (
            <SidebarMenuItem>
                <SidebarMenuButton
                    asChild
                    isActive={node.id === activeFileId}
                    tooltip={node.path}
                >
                    <Link
                        href={explorerShow.url(node.stable_key)}
                        preserveScroll
                    >
                        {node.type === 'image' ? (
                            <FileImage />
                        ) : node.type === 'file' ? (
                            <FileDown />
                        ) : (
                            <FileText />
                        )}
                        <span>{node.name}</span>
                        {node.status !== 'active' ? (
                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                {t(`brain.itemStatus.${node.status}`, {
                                    defaultValue: node.status,
                                })}
                            </span>
                        ) : null}
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        );
    }

    return (
        <SidebarMenuItem>
            <Collapsible
                className="group/collapsible [&[data-state=open]>button>svg:first-child]:rotate-90"
                defaultOpen={isRoot || containsFile(node, activeFileId)}
            >
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton>
                        <ChevronRight className="transition-transform" />
                        <Folder />
                        <span>{node.name}</span>
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub className="mr-0 pr-0">
                        {node.children.map((child) => (
                            <TreeEntry
                                key={`${child.kind}:${child.path}`}
                                node={child}
                                activeFileId={activeFileId}
                                isRoot={false}
                            />
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </Collapsible>
        </SidebarMenuItem>
    );
}

export function BrainFileTree({
    nodes,
    activeFileId,
    label,
}: {
    nodes: BrainTreeNode[];
    activeFileId: string | null;
    label: string;
}) {
    return (
        <nav aria-label={label}>
            <SidebarMenu>
                {nodes.map((node) => (
                    <TreeEntry
                        key={`${node.kind}:${node.path}`}
                        node={node}
                        activeFileId={activeFileId}
                        isRoot
                    />
                ))}
            </SidebarMenu>
        </nav>
    );
}
