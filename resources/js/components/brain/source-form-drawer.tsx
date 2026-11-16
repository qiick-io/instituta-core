import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import SourcesController from '@/actions/App/Http/Controllers/Brain/SourcesController';
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
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { STRING_LIMITS } from '@/lib/string-limits';

const PROVIDERS = ['local', 'git', 'github'] as const;
const ADAPTERS = [
    'instituta',
    'basic-memory',
    'markdown',
    'shadcn-registry',
] as const;
const VISIBILITIES = ['public', 'internal', 'restricted'] as const;

export type SourceFormRow = {
    id: string;
    name: string;
    provider: string;
    remote_url: string | null;
    local_path: string | null;
    ref: string | null;
    path: string | null;
    adapter: string;
    default_visibility: string;
    is_shared: boolean;
    sync_interval_minutes: number | null;
};

type SourceForm = {
    name: string;
    provider: string;
    remote_url: string;
    local_path: string;
    ref: string;
    path: string;
    adapter: string;
    default_visibility: string;
    is_shared: boolean;
    sync_interval_minutes: string;
};

const EMPTY_SOURCE_FORM: SourceForm = {
    name: '',
    provider: 'github',
    remote_url: '',
    local_path: '',
    ref: 'main',
    path: '',
    adapter: 'instituta',
    default_visibility: 'internal',
    is_shared: false,
    sync_interval_minutes: '',
};

/**
 * Drawer form to add or edit a source (#201), copied from the client form drawer. The server
 * checks the never-ingest denylist and returns refusals as field errors.
 */
export function SourceFormDrawer({
    editing,
    open,
    onCancel,
    onSuccess,
}: {
    editing: SourceFormRow | null;
    open: boolean;
    onCancel: () => void;
    onSuccess: () => void;
}) {
    const { t } = useTranslation();
    const form = useForm<SourceForm>({ ...EMPTY_SOURCE_FORM });
    const isCreate = editing === null;
    const isLocal = form.data.provider === 'local';

    useEffect(() => {
        if (!open) {
            return;
        }

        const payload: SourceForm = editing
            ? {
                  name: editing.name,
                  provider: editing.provider,
                  remote_url: editing.remote_url ?? '',
                  local_path: editing.local_path ?? '',
                  ref: editing.ref ?? '',
                  path: editing.path ?? '',
                  adapter: editing.adapter,
                  default_visibility: editing.default_visibility,
                  is_shared: editing.is_shared,
                  sync_interval_minutes:
                      editing.sync_interval_minutes === null
                          ? '0'
                          : String(editing.sync_interval_minutes),
              }
            : { ...EMPTY_SOURCE_FORM };
        form.setDefaults({ ...payload });
        form.setData(payload);
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- Inertia form identity is unstable
    }, [editing, open]);

    const submit = (): void => {
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onSuccess();
            },
        };

        if (editing) {
            form.put(SourcesController.update.url(editing.id), options);
        } else {
            form.post(SourcesController.store.url(), options);
        }
    };

    const textField = (
        key: 'name' | 'remote_url' | 'local_path' | 'ref' | 'path',
        label: string,
        options: {
            isRequired?: boolean;
            hint?: string;
            placeholder?: string;
            isMono?: boolean;
        } = {},
    ) => (
        <Field data-invalid={form.errors[key] ? true : undefined}>
            <FieldLabel htmlFor={`source_${key}`}>
                {label}
                {options.isRequired ? ' *' : ''}
            </FieldLabel>
            <Input
                id={`source_${key}`}
                value={form.data[key]}
                onChange={(event) => form.setData(key, event.target.value)}
                required={options.isRequired}
                maxLength={STRING_LIMITS.NAME}
                placeholder={options.placeholder}
                className={options.isMono ? 'font-mono text-sm' : undefined}
                aria-invalid={form.errors[key] ? true : undefined}
                aria-describedby={`source-${key}-hint source-${key}-error`}
            />
            {options.hint ? (
                <FieldDescription id={`source-${key}-hint`}>
                    {options.hint}
                </FieldDescription>
            ) : null}
            <FieldError id={`source-${key}-error`}>
                {form.errors[key]}
            </FieldError>
        </Field>
    );

    const selectField = (
        key: 'provider' | 'adapter' | 'default_visibility',
        label: string,
        values: readonly string[],
        labelFor: (value: string) => string,
    ) => (
        <Field data-invalid={form.errors[key] ? true : undefined}>
            <FieldLabel htmlFor={`source_${key}`}>{label} *</FieldLabel>
            <NativeSelect
                id={`source_${key}`}
                value={form.data[key]}
                onChange={(event) => form.setData(key, event.target.value)}
                aria-invalid={form.errors[key] ? true : undefined}
            >
                {values.map((value) => (
                    <NativeSelectOption key={value} value={value}>
                        {labelFor(value)}
                    </NativeSelectOption>
                ))}
            </NativeSelect>
            <FieldError>{form.errors[key]}</FieldError>
        </Field>
    );

    return (
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>
                    {isCreate
                        ? t('brain.sources.createTitle')
                        : t('brain.sources.editTitle')}
                </DrawerTitle>
                <DrawerDescription>
                    {t('brain.sources.formDescription')}
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
                    <FieldGroup className="gap-4">
                        {textField('name', t('brain.sources.colName'), {
                            isRequired: true,
                        })}
                        {selectField(
                            'provider',
                            t('brain.sources.colProvider'),
                            PROVIDERS,
                            (value) => t(`brain.sources.providers.${value}`),
                        )}
                        {isLocal
                            ? textField(
                                  'local_path',
                                  t('brain.sources.fieldLocalPath'),
                                  {
                                      isRequired: true,
                                      isMono: true,
                                      hint: t('brain.sources.localPathHint'),
                                  },
                              )
                            : textField(
                                  'remote_url',
                                  t('brain.sources.fieldRemoteUrl'),
                                  {
                                      isRequired: true,
                                      isMono: true,
                                      placeholder:
                                          'https://github.com/acme/brain',
                                  },
                              )}
                        {isLocal
                            ? null
                            : textField('ref', t('brain.sources.fieldRef'), {
                                  isMono: true,
                                  placeholder: 'main',
                              })}
                        {textField('path', t('brain.sources.fieldPath'), {
                            isMono: true,
                            hint: t('brain.sources.pathHint'),
                        })}
                        {selectField(
                            'adapter',
                            t('brain.sources.fieldAdapter'),
                            ADAPTERS,
                            (value) => t(`brain.sources.adapters.${value}`),
                        )}
                        {selectField(
                            'default_visibility',
                            t('brain.sources.fieldVisibility'),
                            VISIBILITIES,
                            (value) => t(`brain.sources.visibilities.${value}`),
                        )}

                        <Field
                            data-invalid={
                                form.errors.sync_interval_minutes
                                    ? true
                                    : undefined
                            }
                        >
                            <FieldLabel htmlFor="source_sync_interval_minutes">
                                {t('brain.sources.fieldSyncInterval')}
                            </FieldLabel>
                            <Input
                                id="source_sync_interval_minutes"
                                type="number"
                                min={0}
                                max={1440}
                                value={form.data.sync_interval_minutes}
                                onChange={(event) =>
                                    form.setData(
                                        'sync_interval_minutes',
                                        event.target.value,
                                    )
                                }
                                aria-describedby="source-sync-interval-hint"
                            />
                            <FieldDescription id="source-sync-interval-hint">
                                {t('brain.sources.syncIntervalHint')}
                            </FieldDescription>
                            <FieldError>
                                {form.errors.sync_interval_minutes}
                            </FieldError>
                        </Field>

                        <Field orientation="horizontal">
                            <Checkbox
                                id="source_is_shared"
                                checked={form.data.is_shared}
                                onCheckedChange={(checked) =>
                                    form.setData('is_shared', checked === true)
                                }
                            />
                            <FieldLabel htmlFor="source_is_shared">
                                {t('brain.sources.fieldShared')}
                            </FieldLabel>
                        </Field>
                    </FieldGroup>
                </DrawerBody>

                <DrawerFooter className="flex flex-row justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onCancel}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {isCreate
                            ? t('brain.sources.saveCreate')
                            : t('brain.sources.saveEdit')}
                    </Button>
                </DrawerFooter>
            </form>
        </DrawerContent>
    );
}
