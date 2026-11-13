import { Form } from '@inertiajs/react';
import { useEffect, useState } from 'react';
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

type ProductFormState = typeof EMPTY_PRODUCT_FORM;

/**
 * Drawer form for creating and editing products under a client company.
 * Uses Inertia Form + Wayfinder `.form()` like settings and auth pages.
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
    const [form, setForm] = useState<ProductFormState>({
        ...EMPTY_PRODUCT_FORM,
    });
    const isCreate = editing === null;

    useEffect(() => {
        if (!open) {
            return;
        }

        if (editing) {
            setForm({
                name: editing.name,
                slug: editing.slug,
                inherits_from_parent: editing.inherits_from_parent,
            });
        } else {
            setForm({ ...EMPTY_PRODUCT_FORM });
        }
    }, [editing, open]);

    const formProps = editing
        ? ClientProductsController.update.form({
              company: clientId,
              product: editing.id,
          })
        : ClientProductsController.store.form(clientId);

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

            <Form
                key={editing?.id ?? 'create'}
                {...formProps}
                options={{ preserveScroll: true }}
                className="flex min-h-0 flex-1 flex-col overflow-hidden"
                noValidate
                onSuccess={() => {
                    setForm({ ...EMPTY_PRODUCT_FORM });
                    onSuccess?.();
                }}
            >
                {({ processing, errors }) => (
                    <>
                        <DrawerBody>
                            <p className="mb-4 text-sm text-muted-foreground">
                                {t('brain.products.requiredHint')}
                            </p>
                            <FieldGroup className="gap-4">
                                <Field
                                    data-invalid={
                                        errors.name ? true : undefined
                                    }
                                >
                                    <FieldLabel htmlFor="product_name">
                                        {t('brain.products.fieldName')} *
                                    </FieldLabel>
                                    <Input
                                        id="product_name"
                                        name="name"
                                        value={form.name}
                                        onChange={(event) =>
                                            setForm((current) => ({
                                                ...current,
                                                name: event.target.value,
                                            }))
                                        }
                                        required
                                        maxLength={STRING_LIMITS.NAME}
                                        aria-invalid={
                                            errors.name ? true : undefined
                                        }
                                        aria-describedby={
                                            errors.name
                                                ? 'product-name-error'
                                                : undefined
                                        }
                                    />
                                    <FieldError id="product-name-error">
                                        {errors.name}
                                    </FieldError>
                                </Field>

                                <Field
                                    data-invalid={
                                        errors.slug ? true : undefined
                                    }
                                >
                                    <FieldLabel htmlFor="product_slug">
                                        {t('brain.products.fieldSlug')} *
                                    </FieldLabel>
                                    <Input
                                        id="product_slug"
                                        name="slug"
                                        value={form.slug}
                                        onChange={(event) =>
                                            setForm((current) => ({
                                                ...current,
                                                slug: event.target.value,
                                            }))
                                        }
                                        required
                                        maxLength={64}
                                        className="font-mono text-sm"
                                        aria-invalid={
                                            errors.slug ? true : undefined
                                        }
                                        aria-describedby={
                                            errors.slug
                                                ? 'product-slug-error product-slug-hint'
                                                : 'product-slug-hint'
                                        }
                                    />
                                    <FieldDescription id="product-slug-hint">
                                        {t('brain.products.slugHint')}
                                    </FieldDescription>
                                    <FieldError id="product-slug-error">
                                        {errors.slug}
                                    </FieldError>
                                </Field>

                                <Field
                                    data-invalid={
                                        errors.inherits_from_parent
                                            ? true
                                            : undefined
                                    }
                                >
                                    <input
                                        type="hidden"
                                        name="inherits_from_parent"
                                        value={
                                            form.inherits_from_parent
                                                ? '1'
                                                : '0'
                                        }
                                    />
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id="product_inherits_from_parent"
                                            checked={form.inherits_from_parent}
                                            onCheckedChange={(checked) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    inherits_from_parent:
                                                        checked === true,
                                                }))
                                            }
                                        />
                                        <div className="space-y-1">
                                            <FieldLabel htmlFor="product_inherits_from_parent">
                                                {t(
                                                    'brain.products.fieldInherit',
                                                )}
                                            </FieldLabel>
                                            <FieldDescription>
                                                {t(
                                                    'brain.products.inheritHint',
                                                )}
                                            </FieldDescription>
                                        </div>
                                    </div>
                                    <FieldError id="product-inherit-error">
                                        {errors.inherits_from_parent}
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
                            <Button type="submit" disabled={processing}>
                                {isCreate
                                    ? t('brain.products.saveCreate')
                                    : t('brain.products.saveEdit')}
                            </Button>
                        </DrawerFooter>
                    </>
                )}
            </Form>
        </DrawerContent>
    );
}
