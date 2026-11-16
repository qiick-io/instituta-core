import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
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
import { Textarea } from '@/components/ui/textarea';
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
