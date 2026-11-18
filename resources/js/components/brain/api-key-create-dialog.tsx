import { useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import KeysController from '@/actions/App/Http/Controllers/Settings/KeysController';
import { AreaLimitsFields } from '@/components/brain/area-limits-fields';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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

type ApiKeyForm = {
    name: string;
    principal: 'me' | 'agent';
    agent_name: string;
    role: string;
    max_visibility: string;
    can_write: boolean;
    expires_at: string;
    /** None chosen: every area the owner reads (#334). */
    areas: string[];
    path_prefix: string;
};

const EMPTY_API_KEY_FORM: ApiKeyForm = {
    name: '',
    principal: 'agent',
    agent_name: '',
    role: 'member',
    max_visibility: 'internal',
    can_write: false,
    expires_at: '',
    areas: [],
    path_prefix: '',
};

/**
 * Create-key dialog for owners (#201), copied from externa-core's API keys page: a header
 * primary button opens it, the plain secret comes back once on the list page. The key lives in
 * the current scope, for the owner or for a new agent principal they own, optionally limited to
 * some areas and a folder (#334).
 */
export function ApiKeyCreateDialog({
    open,
    onOpenChange,
    areaOptions,
}: {
    open: boolean;
    onOpenChange: (isOpen: boolean) => void;
    areaOptions: string[];
}) {
    const { t } = useTranslation();
    const form = useForm<ApiKeyForm>({ ...EMPTY_API_KEY_FORM });

    useEffect(() => {
        if (open) {
            form.setDefaults({ ...EMPTY_API_KEY_FORM });
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- Inertia form identity is unstable
    }, [open]);

    const selectField = (
        key: 'principal' | 'role' | 'max_visibility',
        label: string,
        options: { value: string; label: string }[],
        hint?: string,
    ) => (
        <Field data-invalid={form.errors[key] ? true : undefined}>
            <FieldLabel htmlFor={`api_key_${key}`}>{label} *</FieldLabel>
            <NativeSelect
                id={`api_key_${key}`}
                value={form.data[key]}
                onChange={(event) =>
                    form.setData(key, event.target.value as never)
                }
                aria-describedby={hint ? `api-key-${key}-hint` : undefined}
            >
                {options.map((option) => (
                    <NativeSelectOption key={option.value} value={option.value}>
                        {option.label}
                    </NativeSelectOption>
                ))}
            </NativeSelect>
            {hint ? (
                <FieldDescription id={`api-key-${key}-hint`}>
                    {hint}
                </FieldDescription>
            ) : null}
            <FieldError>{form.errors[key]}</FieldError>
        </Field>
    );

    return (
        <Dialog
            open={open}
            onOpenChange={(isOpen) => {
                if (!isOpen && form.processing) {
                    return;
                }

                onOpenChange(isOpen);
            }}
        >
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('brain.keys.createTitle')}</DialogTitle>
                    <DialogDescription>
                        {t('brain.keys.createDescription')}
                    </DialogDescription>
                </DialogHeader>

                <form
                    id="api-key-create-form"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(KeysController.store.url(), {
                            preserveScroll: true,
                            onSuccess: () => onOpenChange(false),
                        });
                    }}
                >
                    <FieldGroup className="gap-4">
                        <Field
                            data-invalid={form.errors.name ? true : undefined}
                        >
                            <FieldLabel htmlFor="api_key_name">
                                {t('brain.keys.colName')} *
                            </FieldLabel>
                            <Input
                                id="api_key_name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                                maxLength={STRING_LIMITS.NAME}
                                placeholder={t('brain.keys.namePlaceholder')}
                                aria-invalid={
                                    form.errors.name ? true : undefined
                                }
                                aria-describedby="api-key-name-error"
                            />
                            <FieldError id="api-key-name-error">
                                {form.errors.name}
                            </FieldError>
                        </Field>

                        {selectField(
                            'principal',
                            t('brain.keys.fieldPrincipal'),
                            [
                                {
                                    value: 'agent',
                                    label: t('brain.keys.principalAgent'),
                                },
                                {
                                    value: 'me',
                                    label: t('brain.keys.principalMe'),
                                },
                            ],
                            t('brain.keys.principalHint'),
                        )}

                        {form.data.principal === 'agent' ? (
                            <Field
                                data-invalid={
                                    form.errors.agent_name ? true : undefined
                                }
                            >
                                <FieldLabel htmlFor="api_key_agent_name">
                                    {t('brain.keys.fieldAgentName')} *
                                </FieldLabel>
                                <Input
                                    id="api_key_agent_name"
                                    value={form.data.agent_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'agent_name',
                                            event.target.value,
                                        )
                                    }
                                    required
                                    maxLength={STRING_LIMITS.NAME}
                                    placeholder={t(
                                        'brain.keys.agentNamePlaceholder',
                                    )}
                                    aria-invalid={
                                        form.errors.agent_name
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby="api-key-agent-name-error"
                                />
                                <FieldError id="api-key-agent-name-error">
                                    {form.errors.agent_name}
                                </FieldError>
                            </Field>
                        ) : null}

                        <div className="grid gap-4 sm:grid-cols-2">
                            {selectField(
                                'role',
                                t('brain.keys.colRole'),
                                ['owner', 'member', 'guest'].map((role) => ({
                                    value: role,
                                    label: t(`brain.keys.roles.${role}`),
                                })),
                            )}
                            {selectField(
                                'max_visibility',
                                t('brain.keys.fieldMaxVisibility'),
                                ['public', 'internal', 'restricted'].map(
                                    (visibility) => ({
                                        value: visibility,
                                        label: t(
                                            `brain.sources.visibilities.${visibility}`,
                                        ),
                                    }),
                                ),
                            )}
                        </div>

                        <Field
                            data-invalid={
                                form.errors.expires_at ? true : undefined
                            }
                        >
                            <FieldLabel htmlFor="api_key_expires_at">
                                {t('brain.keys.fieldExpiresAt')}
                            </FieldLabel>
                            <Input
                                id="api_key_expires_at"
                                type="date"
                                value={form.data.expires_at}
                                onChange={(event) =>
                                    form.setData(
                                        'expires_at',
                                        event.target.value,
                                    )
                                }
                                aria-describedby="api-key-expires-at-hint"
                            />
                            <FieldDescription id="api-key-expires-at-hint">
                                {t('brain.keys.expiresAtHint')}
                            </FieldDescription>
                            <FieldError>{form.errors.expires_at}</FieldError>
                        </Field>

                        <AreaLimitsFields
                            idPrefix="api_key"
                            areaOptions={areaOptions}
                            areas={form.data.areas}
                            onAreasChange={(areas) =>
                                form.setData('areas', areas)
                            }
                            pathPrefix={form.data.path_prefix}
                            onPathPrefixChange={(pathPrefix) =>
                                form.setData('path_prefix', pathPrefix)
                            }
                            areasError={
                                form.errors.areas ??
                                (form.errors as Record<string, string>)[
                                    'areas.0'
                                ]
                            }
                            pathPrefixError={form.errors.path_prefix}
                        />

                        <Field orientation="horizontal">
                            <Checkbox
                                id="api_key_can_write"
                                checked={form.data.can_write}
                                onCheckedChange={(checked) =>
                                    form.setData('can_write', checked === true)
                                }
                                aria-describedby="api-key-can-write-hint"
                            />
                            <div className="space-y-1">
                                <FieldLabel htmlFor="api_key_can_write">
                                    {t('brain.keys.fieldCanWrite')}
                                </FieldLabel>
                                <FieldDescription id="api-key-can-write-hint">
                                    {t('brain.keys.canWriteHint')}
                                </FieldDescription>
                            </div>
                        </Field>
                    </FieldGroup>
                </form>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => onOpenChange(false)}
                    >
                        {t('common.cancel')}
                    </Button>
                    <Button
                        type="submit"
                        form="api-key-create-form"
                        disabled={form.processing}
                    >
                        <KeyRound className="mr-2 size-4" />
                        {t('brain.keys.create')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
