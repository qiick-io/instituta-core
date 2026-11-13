import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import ClientProductsController from '@/actions/App/Http/Controllers/Brain/ClientProductsController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

const EMPTY_PRODUCT_FORM = {
    name: '',
    slug: '',
    inherits_from_parent: true,
};

export type ProductFormRow = {
    id: string;
    name: string;
    slug: string;
    inherits_from_parent: boolean;
};

export type ProductFormDrawerProps = {
    clientId: string;
    clientName: string;
    editing: ProductFormRow | null;
    open?: boolean;
    onCancel?: () => void;
    onSuccess?: () => void;
};

/**
 * Drawer form for creating and editing products under a client company.
 */
export function ProductFormDrawer({
    clientId,
    clientName,
    editing,
    open = true,
    onCancel,
    onSuccess,
}: ProductFormDrawerProps) {
    const { t } = useTranslation();
    const form = useForm({ ...EMPTY_PRODUCT_FORM });
    const isCreate = editing === null;

    useEffect(() => {
        if (!open) {
            return;
        }

        if (editing) {
            const payload = {
                name: editing.name,
                slug: editing.slug,
                inherits_from_parent: editing.inherits_from_parent,
            };
            form.setData(payload);
            form.setDefaults({ ...payload });
        } else {
            form.setDefaults({ ...EMPTY_PRODUCT_FORM });
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
            form.put(
                ClientProductsController.update.url({
                    company: clientId,
                    product: editing.id,
                }),
                opts,
            );
        } else {
            form.post(ClientProductsController.store.url(clientId), opts);
        }
    };

    const title = isCreate
        ? t('brain.products.createTitle')
        : t('brain.products.editTitle');

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>{title}</DrawerTitle>
                <DrawerDescription>
                    {isCreate
                        ? t('brain.products.createDescription', {
                              client: clientName,
                          })
                        : t('brain.products.editDescription', {
                              client: clientName,
                          })}
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
                        {t('brain.products.requiredHint')}
                    </p>
                    <FieldGroup className="gap-4">
                        <Field
                            data-invalid={form.errors.name ? true : undefined}
                        >
                            <FieldLabel htmlFor="product_name">
                                {t('brain.products.fieldName')} *
                            </FieldLabel>
                            <Input
                                id="product_name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                                maxLength={STRING_LIMITS.NAME}
                                aria-invalid={
                                    form.errors.name ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.name
                                        ? 'product-name-error'
                                        : undefined
                                }
                            />
                            <FieldError id="product-name-error">
                                {form.errors.name}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={form.errors.slug ? true : undefined}
                        >
                            <FieldLabel htmlFor="product_slug">
                                {t('brain.products.fieldSlug')} *
                            </FieldLabel>
                            <Input
                                id="product_slug"
                                value={form.data.slug}
                                onChange={(event) =>
                                    form.setData('slug', event.target.value)
                                }
                                required
                                maxLength={64}
                                className="font-mono text-sm"
                                aria-invalid={
                                    form.errors.slug ? true : undefined
                                }
                                aria-describedby={
                                    form.errors.slug
                                        ? 'product-slug-error product-slug-hint'
                                        : 'product-slug-hint'
                                }
                            />
                            <FieldDescription id="product-slug-hint">
                                {t('brain.products.slugHint')}
                            </FieldDescription>
                            <FieldError id="product-slug-error">
                                {form.errors.slug}
                            </FieldError>
                        </Field>

                        <Field
                            data-invalid={
                                form.errors.inherits_from_parent
                                    ? true
                                    : undefined
                            }
                        >
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="product_inherits_from_parent"
                                    checked={form.data.inherits_from_parent}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'inherits_from_parent',
                                            checked === true,
                                        )
                                    }
                                />
                                <div className="space-y-1">
                                    <FieldLabel htmlFor="product_inherits_from_parent">
                                        {t('brain.products.fieldInherit')}
                                    </FieldLabel>
                                    <FieldDescription>
                                        {t('brain.products.inheritHint')}
                                    </FieldDescription>
                                </div>
                            </div>
                            <FieldError id="product-inherit-error">
                                {form.errors.inherits_from_parent}
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
                            ? t('brain.products.saveCreate')
                            : t('brain.products.saveEdit')}
                    </Button>
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}
