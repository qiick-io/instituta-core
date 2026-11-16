import { useForm } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    DrawerBody,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { PermissionEnum } from '@/enums/permission-enum';
import { useCan } from '@/hooks/use-can';
import { draftProposalMarkdown } from '@/lib/ai-chat';
import { STRING_LIMITS } from '@/lib/string-limits';
import { store as proposalsStore } from '@/routes/brain/proposals';

/**
 * What the drawer proposes: a new item, a whole existing item, or one section of it. The base
 * hashes are the versions the person is reading, sent back so a stale edit is refused (#198).
 */
export type ProposeChangeTarget =
    | {
          mode: 'create';
          area: string | null;
      }
    | {
          mode: 'item';
          stableKey: string;
          path: string;
          area: string;
          title: string;
          baseContentHash: string;
          markdown: string;
      }
    | {
          mode: 'section';
          stableKey: string;
          path: string;
          area: string;
          title: string;
          heading: string;
          sectionAnchor: string;
          baseSectionHash: string;
          markdown: string;
      };

type ProposeChangeForm = {
    action: 'create' | 'update';
    title: string;
    stable_key: string;
    path: string;
    area: string;
    body: string;
    base_content_hash: string;
    section_anchor: string;
    base_section_hash: string;
    /** '' keeps the current status (#219). */
    status: string;
    superseded_by: string;
};

function initialForm(
    target: ProposeChangeTarget,
    defaultTitle: string,
): ProposeChangeForm {
    if (target.mode === 'create') {
        return {
            action: 'create',
            title: '',
            stable_key: '',
            path: target.area ? `${target.area}/` : '',
            area: target.area ?? '',
            body: '',
            base_content_hash: '',
            section_anchor: '',
            base_section_hash: '',
            status: '',
            superseded_by: '',
        };
    }

    return {
        action: 'update',
        title: defaultTitle,
        stable_key: target.stableKey,
        path: target.path,
        area: target.area,
        body: target.markdown,
        base_content_hash: target.mode === 'item' ? target.baseContentHash : '',
        section_anchor: target.mode === 'section' ? target.sectionAnchor : '',
        base_section_hash:
            target.mode === 'section' ? target.baseSectionHash : '',
        status: '',
        superseded_by: '',
    };
}

/**
 * Drawer form to propose a change from the web UI (#200): it opens a proposal through the same
 * action as MCP and REST, never a direct write. Layout copied from the client form drawer.
 */
export function ProposeChangeDrawer({
    target,
    open,
    onCancel,
}: {
    target: ProposeChangeTarget | null;
    open: boolean;
    onCancel: () => void;
}) {
    const { t } = useTranslation();
    const form = useForm<ProposeChangeForm>(
        initialForm({ mode: 'create', area: null }, ''),
    );
    const isCreate = target === null || target.mode === 'create';
    const { can } = useCan();
    const canDraftWithAi = can(PermissionEnum.CanUseAi);
    const [draftRequest, setDraftRequest] = useState('');
    const [draftError, setDraftError] = useState<string | null>(null);
    const [isDrafting, setIsDrafting] = useState(false);

    /** Fills the markdown with an AI draft (#203); the person still reviews and submits it. */
    const draftWithAi = (): void => {
        setIsDrafting(true);
        setDraftError(null);
        void draftProposalMarkdown(
            draftRequest,
            form.data.action === 'update' ? form.data.stable_key : null,
            form.data.section_anchor !== '' ? form.data.section_anchor : null,
        )
            .then((markdown) => form.setData('body', markdown))
            .catch((error: Error) => setDraftError(error.message))
            .finally(() => setIsDrafting(false));
    };

    useEffect(() => {
        if (!open || target === null) {
            return;
        }

        const defaultTitle =
            target.mode === 'section'
                ? t('brain.propose.defaultSectionTitle', {
                      heading: target.heading,
                      title: target.title,
                  })
                : target.mode === 'item'
                  ? t('brain.propose.defaultItemTitle', {
                        title: target.title,
                    })
                  : '';
        const payload = initialForm(target, defaultTitle);
        form.setDefaults({ ...payload });
        form.setData(payload);
        form.clearErrors();
        setDraftRequest('');
        setDraftError(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- Inertia form identity is unstable
    }, [target, open]);

    const description =
        target?.mode === 'section'
            ? t('brain.propose.sectionDescription', {
                  heading: target.heading,
              })
            : target?.mode === 'item'
              ? t('brain.propose.itemDescription')
              : t('brain.propose.createDescription');

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>
                    {isCreate
                        ? t('brain.propose.createTitle')
                        : t('brain.propose.editTitle')}
                </DrawerTitle>
                <DrawerDescription>{description}</DrawerDescription>
            </DrawerHeader>

            <form
                className="flex min-h-0 flex-1 flex-col overflow-hidden"
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    // Metadata changes travel as `frontmatter` (#219).
                    form.transform((data) => {
                        const frontmatter: Record<string, string> = {};

                        if (data.status !== '') {
                            frontmatter.status = data.status;
                        }

                        if (data.superseded_by.trim() !== '') {
                            frontmatter.superseded_by =
                                data.superseded_by.trim();
                        }

                        return Object.keys(frontmatter).length > 0
                            ? { ...data, frontmatter }
                            : data;
                    });
                    form.post(proposalsStore.url(), { preserveScroll: true });
                }}
            >
                <DrawerBody>
                    <FieldGroup className="gap-4">
                        <Field
                            data-invalid={form.errors.title ? true : undefined}
                        >
                            <FieldLabel htmlFor="proposal_title">
                                {t('brain.propose.fieldTitle')} *
                            </FieldLabel>
                            <Input
                                id="proposal_title"
                                value={form.data.title}
                                onChange={(event) =>
                                    form.setData('title', event.target.value)
                                }
                                required
                                maxLength={STRING_LIMITS.NAME}
                                aria-invalid={
                                    form.errors.title ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.title
                                        ? 'proposal-title-error'
                                        : undefined
                                }
                            />
                            <FieldError id="proposal-title-error">
                                {form.errors.title}
                            </FieldError>
                        </Field>

                        {isCreate ? (
                            <>
                                <Field
                                    data-invalid={
                                        form.errors.path ? true : undefined
                                    }
                                >
                                    <FieldLabel htmlFor="proposal_path">
                                        {t('brain.propose.fieldPath')} *
                                    </FieldLabel>
                                    <Input
                                        id="proposal_path"
                                        value={form.data.path}
                                        onChange={(event) =>
                                            form.setData(
                                                'path',
                                                event.target.value,
                                            )
                                        }
                                        required
                                        className="font-mono text-sm"
                                        placeholder="rules/naming.md"
                                        aria-invalid={
                                            form.errors.path ? true : undefined
                                        }
                                        aria-describedby="proposal-path-hint proposal-path-error"
                                    />
                                    <FieldDescription id="proposal-path-hint">
                                        {t('brain.propose.pathHint')}
                                    </FieldDescription>
                                    <FieldError id="proposal-path-error">
                                        {form.errors.path}
                                    </FieldError>
                                </Field>

                                <Field
                                    data-invalid={
                                        form.errors.stable_key
                                            ? true
                                            : undefined
                                    }
                                >
                                    <FieldLabel htmlFor="proposal_stable_key">
                                        {t('brain.propose.fieldStableKey')} *
                                    </FieldLabel>
                                    <Input
                                        id="proposal_stable_key"
                                        value={form.data.stable_key}
                                        onChange={(event) =>
                                            form.setData(
                                                'stable_key',
                                                event.target.value,
                                            )
                                        }
                                        required
                                        className="font-mono text-sm"
                                        placeholder="rules/naming"
                                        aria-invalid={
                                            form.errors.stable_key
                                                ? true
                                                : undefined
                                        }
                                        aria-describedby="proposal-stable-key-error"
                                    />
                                    <FieldError id="proposal-stable-key-error">
                                        {form.errors.stable_key}
                                    </FieldError>
                                </Field>
                            </>
                        ) : (
                            <p className="font-mono text-xs text-muted-foreground">
                                {form.data.path}
                                {form.data.section_anchor
                                    ? ` #${form.data.section_anchor}`
                                    : ''}
                            </p>
                        )}

                        {target?.mode === 'item' ? (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field>
                                    <FieldLabel htmlFor="proposal_status">
                                        {t('brain.propose.fieldStatus')}
                                    </FieldLabel>
                                    <NativeSelect
                                        id="proposal_status"
                                        value={form.data.status}
                                        onChange={(event) =>
                                            form.setData(
                                                'status',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <NativeSelectOption value="">
                                            {t('brain.propose.statusUnchanged')}
                                        </NativeSelectOption>
                                        {(
                                            [
                                                'active',
                                                'draft',
                                                'deprecated',
                                                'superseded',
                                            ] as const
                                        ).map((status) => (
                                            <NativeSelectOption
                                                key={status}
                                                value={status}
                                            >
                                                {t(
                                                    `brain.itemStatus.${status}`,
                                                )}
                                            </NativeSelectOption>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field>
                                    <FieldLabel htmlFor="proposal_superseded_by">
                                        {t('brain.propose.fieldSupersededBy')}
                                    </FieldLabel>
                                    <Input
                                        id="proposal_superseded_by"
                                        value={form.data.superseded_by}
                                        onChange={(event) =>
                                            form.setData(
                                                'superseded_by',
                                                event.target.value,
                                            )
                                        }
                                        className="font-mono text-sm"
                                        aria-describedby="proposal-superseded-by-hint"
                                    />
                                    <FieldDescription id="proposal-superseded-by-hint">
                                        {t('brain.propose.supersededByHint')}
                                    </FieldDescription>
                                </Field>
                            </div>
                        ) : null}

                        {canDraftWithAi ? (
                            <Field
                                data-invalid={draftError ? true : undefined}
                                className="rounded-lg border border-ai-border bg-ai-bg p-3"
                            >
                                <FieldLabel htmlFor="proposal_ai_request">
                                    <Sparkles className="size-4 text-ai-text" />
                                    {t('brain.propose.aiLabel')}
                                </FieldLabel>
                                <div className="flex gap-2">
                                    <Input
                                        id="proposal_ai_request"
                                        value={draftRequest}
                                        onChange={(event) =>
                                            setDraftRequest(event.target.value)
                                        }
                                        placeholder={t(
                                            'brain.propose.aiPlaceholder',
                                        )}
                                        aria-describedby="proposal-ai-hint proposal-ai-error"
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={
                                            isDrafting ||
                                            draftRequest.trim() === ''
                                        }
                                        onClick={draftWithAi}
                                    >
                                        {isDrafting
                                            ? t('brain.propose.aiDrafting')
                                            : t('brain.propose.aiDraft')}
                                    </Button>
                                </div>
                                <FieldDescription id="proposal-ai-hint">
                                    {t('brain.propose.aiHint')}
                                </FieldDescription>
                                <FieldError id="proposal-ai-error">
                                    {draftError}
                                </FieldError>
                            </Field>
                        ) : null}

                        <Field
                            data-invalid={form.errors.body ? true : undefined}
                        >
                            <FieldLabel htmlFor="proposal_body">
                                {t('brain.propose.fieldMarkdown')} *
                            </FieldLabel>
                            <Textarea
                                id="proposal_body"
                                value={form.data.body}
                                onChange={(event) =>
                                    form.setData('body', event.target.value)
                                }
                                required
                                rows={16}
                                className="font-mono text-sm"
                                aria-invalid={
                                    form.errors.body ? true : undefined
                                }
                                aria-describedby="proposal-body-hint proposal-body-error"
                            />
                            <FieldDescription id="proposal-body-hint">
                                {t('brain.propose.markdownHint')}
                            </FieldDescription>
                            <FieldError id="proposal-body-error">
                                {form.errors.body}
                            </FieldError>
                        </Field>
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter className="flex flex-row justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onCancel}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {t('brain.propose.submit')}
                    </Button>
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}
