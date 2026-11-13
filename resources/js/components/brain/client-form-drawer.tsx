import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import ClientsController from '@/actions/App/Http/Controllers/Brain/ClientsController';
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
import { STRING_LIMITS } from '@/lib/string-limits';

const EMPTY_CLIENT_FORM = {
    name: '',
    slug: '',
};

export type ClientFormRow = {
    id: string;
    name: string;
    slug: string;
};

export type ClientFormDrawerProps = {
    editing: ClientFormRow | null;
    open?: boolean;
    onCancel?: () => void;
    onSuccess?: () => void;
};

/**
 * Drawer form for creating and editing agency client companies.
 */
export function ClientFormDrawer({
    editing,
    open = true,
    onCancel,
    onSuccess,
}: ClientFormDrawerProps) {
    const { t } = useTranslation();
    const form = useForm({ ...EMPTY_CLIENT_FORM });
    const isCreate = editing === null;

    useEffect(() => {
        if (!open) {
            return;
        }

        if (editing) {
            const payload = {
                name: editing.name,
                slug: editing.slug,
            };
            form.setData(payload);
            form.setDefaults({ ...payload });
        } else {
            form.setDefaults({ ...EMPTY_CLIENT_FORM });
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- Inertia form identity is unstable
    }, [editing, open]);

    const submit = (): void => {
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onSuccess?.();
            },
        };

        if (editing) {
            form.put(ClientsController.update.url(editing.id), opts);
        } else {
            form.post(ClientsController.store.url(), opts);
        }
    };

    const title = isCreate
        ? t('brain.clients.createTitle')
        : t('brain.clients.editTitle');

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>{title}</DrawerTitle>
                <DrawerDescription>
                    {isCreate
                        ? t('brain.clients.createDescription')
                        : t('brain.clients.editDescription')}
                </DrawerDescription>
            </DrawerHeader>

            <form
                className="flex min-h-0 flex-1 flex-col overflow-hidden"
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <DrawerBody>
                    <p className="mb-4 text-sm text-muted-foreground">
                        {t('brain.clients.requiredHint')}
                    </p>
                    <FieldGroup className="gap-4">
                        <Field
                            data-invalid={form.errors.name ? true : undefined}
                        >
                            <FieldLabel htmlFor="client_name">
                                {t('brain.clients.fieldName')} *
                            </FieldLabel>
                            <Input
                                id="client_name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                                maxLength={STRING_LIMITS.NAME}
                                autoComplete="organization"
                                placeholder={t('brain.clients.namePlaceholder')}
                                aria-invalid={
                                    form.errors.name ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.name
                                        ? 'client-name-error'
                                        : undefined
                                }
                            />
                            <FieldError id="client-name-error">
                                {form.errors.name}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={form.errors.slug ? true : undefined}
                        >
                            <FieldLabel htmlFor="client_slug">
                                {t('brain.clients.fieldSlug')} *
                            </FieldLabel>
                            <Input
                                id="client_slug"
                                value={form.data.slug}
                                onChange={(event) =>
                                    form.setData('slug', event.target.value)
                                }
                                required
                                maxLength={64}
                                className="font-mono text-sm"
                                placeholder={t('brain.clients.slugPlaceholder')}
                                aria-invalid={
                                    form.errors.slug ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.slug
                                        ? 'client-slug-error client-slug-hint'
                                        : 'client-slug-hint'
                                }
                            />
                            <FieldDescription id="client-slug-hint">
                                {t('brain.clients.slugHint')}
                            </FieldDescription>
                            <FieldError id="client-slug-error">
                                {form.errors.slug}
                            </FieldError>
                        </Field>
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter className="flex flex-row justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onCancel?.()}
                    >
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {isCreate
                            ? t('brain.clients.saveCreate')
                            : t('brain.clients.saveEdit')}
                    </Button>
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}
