import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import type { ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import InstanceSecuritySettingsController from '@/actions/App/Http/Controllers/Settings/InstanceSecuritySettingsController';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { edit as editSecurity } from '@/routes/instance/security';
import type { InstanceSettingsForm, PasswordPolicy } from '@/types';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

type SecurityInstanceSettings = Pick<
    InstanceSettingsForm,
    | 'password_policy'
    | 'login_max_attempts'
    | 'login_lockout_threshold'
    | 'login_lockout_minutes'
    | 'registration_enabled'
    | 'two_factor_required'
    | 'oidc_enabled'
    | 'oidc_issuer'
    | 'oidc_client_id'
    | 'has_oidc_client_secret'
    | 'oidc_button_label'
    | 'oidc_jit_provisioning'
    | 'oidc_auto_link'
    | 'oidc_only'
    | 'oidc_role_mapping_enabled'
    | 'oidc_organization'
>;

type Props = {
    instance: SecurityInstanceSettings;
    passwordPolicies: PasswordPolicy[];
    oidcCallbackUrl: string;
    oidcBackChannelLogoutUrl: string;
};

type SettingCheckboxProps = {
    name: string;
    label: string;
    hint?: string;
    isChecked: boolean;
    error?: string;
    onCheckedChange: (isChecked: boolean) => void;
};

/**
 * One boolean setting: checkbox, label, hint and error, laid out like the two-factor and
 * registration switches above it.
 */
function SettingCheckbox({
    name,
    label,
    hint,
    isChecked,
    error,
    onCheckedChange,
}: SettingCheckboxProps) {
    const elementId = name.replaceAll('_', '-');
    const describedBy = error
        ? `${elementId}-error`
        : hint
          ? `${elementId}-hint`
          : undefined;

    return (
        <Field data-invalid={error ? true : undefined}>
            <div className="flex items-center gap-3">
                <Checkbox
                    id={name}
                    checked={isChecked}
                    onCheckedChange={(checked) =>
                        onCheckedChange(checked === true)
                    }
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                />
                <FieldLabel htmlFor={name}>{label}</FieldLabel>
            </div>
            {hint ? (
                <FieldDescription id={`${elementId}-hint`} className="pl-8">
                    {hint}
                </FieldDescription>
            ) : null}
            <FieldError id={`${elementId}-error`}>{error}</FieldError>
        </Field>
    );
}

type SettingInputProps = Omit<
    ComponentProps<typeof Input>,
    'id' | 'name' | 'onChange'
> & {
    name: string;
    label: string;
    hint?: string;
    error?: string;
    onValueChange: (value: string) => void;
};

/**
 * One text setting: label, input, optional hint and error.
 */
function SettingInput({
    name,
    label,
    hint,
    error,
    onValueChange,
    ...inputProps
}: SettingInputProps) {
    const elementId = name.replaceAll('_', '-');
    const describedBy = error
        ? `${elementId}-error`
        : hint
          ? `${elementId}-hint`
          : undefined;

    return (
        <Field data-invalid={error ? true : undefined}>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            <Input
                {...inputProps}
                id={name}
                name={name}
                onChange={(event) => onValueChange(event.target.value)}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
            />
            {hint ? (
                <FieldDescription id={`${elementId}-hint`}>
                    {hint}
                </FieldDescription>
            ) : null}
            <FieldError id={`${elementId}-error`}>{error}</FieldError>
        </Field>
    );
}

function buildFormState(instance: SecurityInstanceSettings) {
    return {
        password_policy: instance.password_policy,
        login_max_attempts: instance.login_max_attempts,
        login_lockout_threshold: instance.login_lockout_threshold,
        login_lockout_minutes: instance.login_lockout_minutes,
        registration_enabled: instance.registration_enabled,
        two_factor_required: instance.two_factor_required,
        oidc_enabled: instance.oidc_enabled,
        oidc_issuer: instance.oidc_issuer ?? '',
        oidc_client_id: instance.oidc_client_id ?? '',
        oidc_client_secret: '',
        oidc_button_label: instance.oidc_button_label ?? '',
        oidc_jit_provisioning: instance.oidc_jit_provisioning,
        oidc_auto_link: instance.oidc_auto_link,
        oidc_only: instance.oidc_only,
        oidc_role_mapping_enabled: instance.oidc_role_mapping_enabled,
        oidc_organization: instance.oidc_organization ?? '',
    };
}

type SecurityFormState = ReturnType<typeof buildFormState>;

/** Hidden inputs that carry the checkbox state, which Radix checkboxes do not submit. */
const BOOLEAN_FIELDS = [
    'registration_enabled',
    'two_factor_required',
    'oidc_enabled',
    'oidc_jit_provisioning',
    'oidc_auto_link',
    'oidc_only',
    'oidc_role_mapping_enabled',
] as const;

/**
 * Settings → Authentication & security: password policy, login attempts per minute,
 * self-registration, required two-factor authentication and single sign-on. Copied from
 * Excubia / externa; the SSO section adds the package's auto-link, SSO-only mode and role
 * mapping to Excubia's.
 */
export default function InstanceSecuritySettingsPage({
    instance,
    passwordPolicies,
    oidcCallbackUrl,
    oidcBackChannelLogoutUrl,
}: Props) {
    const { t } = useTranslation();
    const layoutProps = {
        breadcrumbs: [
            {
                title: t('settings.layout.authSecurity'),
                href: editSecurity(),
            },
        ],
    };
    const [form, setForm] = useState(() => buildFormState(instance));

    function updateForm<FieldName extends keyof SecurityFormState>(
        fieldName: FieldName,
        value: SecurityFormState[FieldName],
    ) {
        setForm((current) => ({ ...current, [fieldName]: value }));
    }

    return (
        <AppLayout {...layoutProps}>
            <SettingsLayout>
                <Head title={t('settings.instance.securityNav.head')} />

                <h1 className="sr-only">
                    {t('settings.instance.securityNav.head')}
                </h1>

                <Form
                    {...InstanceSecuritySettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-12"
                    noValidate
                >
                    {({ processing, recentlySuccessful, errors }) => (
                        <>
                            {BOOLEAN_FIELDS.map((fieldName) => (
                                <input
                                    key={fieldName}
                                    type="hidden"
                                    name={fieldName}
                                    value={form[fieldName] ? '1' : '0'}
                                />
                            ))}
                            <input
                                type="hidden"
                                name="password_policy"
                                value={form.password_policy}
                            />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t('settings.instance.securityTitle')}
                                    description={t(
                                        'settings.instance.securityDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <Field
                                        data-invalid={
                                            errors.password_policy
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="password_policy">
                                            {t(
                                                'settings.instance.passwordPolicy',
                                            )}
                                        </FieldLabel>
                                        <Select
                                            value={form.password_policy}
                                            onValueChange={(value) => {
                                                if (
                                                    value === 'weak' ||
                                                    value === 'medium' ||
                                                    value === 'strong'
                                                ) {
                                                    setForm((current) => ({
                                                        ...current,
                                                        password_policy: value,
                                                    }));
                                                }
                                            }}
                                        >
                                            <SelectTrigger
                                                id="password_policy"
                                                className="w-full"
                                                aria-invalid={
                                                    errors.password_policy
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.password_policy
                                                        ? 'password-policy-error'
                                                        : undefined
                                                }
                                            >
                                                <SelectValue>
                                                    {t(
                                                        `settings.instance.passwordPolicies.${form.password_policy}.label`,
                                                    )}
                                                </SelectValue>
                                            </SelectTrigger>
                                            <SelectContent>
                                                {passwordPolicies.map(
                                                    (policy) => (
                                                        <SelectItem
                                                            key={policy}
                                                            value={policy}
                                                            textValue={t(
                                                                `settings.instance.passwordPolicies.${policy}.label`,
                                                            )}
                                                            className="items-start py-2"
                                                        >
                                                            <span className="flex flex-col gap-0.5 text-left">
                                                                <span>
                                                                    {t(
                                                                        `settings.instance.passwordPolicies.${policy}.label`,
                                                                    )}
                                                                </span>
                                                                <span className="text-xs font-normal whitespace-normal text-muted-foreground">
                                                                    {t(
                                                                        `settings.instance.passwordPolicies.${policy}.description`,
                                                                    )}
                                                                </span>
                                                            </span>
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <ul className="list-disc space-y-1 pl-6 text-sm text-muted-foreground">
                                            {passwordPolicies.map((policy) => (
                                                <li key={`hint-${policy}`}>
                                                    <span className="font-medium text-foreground">
                                                        {t(
                                                            `settings.instance.passwordPolicies.${policy}.label`,
                                                        )}
                                                        :
                                                    </span>{' '}
                                                    {t(
                                                        `settings.instance.passwordPolicies.${policy}.description`,
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                        <FieldError id="password-policy-error">
                                            {errors.password_policy}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.login_max_attempts
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="login_max_attempts">
                                            {t(
                                                'settings.instance.loginMaxAttempts',
                                            )}
                                        </FieldLabel>
                                        <Input
                                            id="login_max_attempts"
                                            name="login_max_attempts"
                                            type="number"
                                            min={1}
                                            max={100}
                                            value={form.login_max_attempts}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    login_max_attempts: Number(
                                                        event.target.value,
                                                    ),
                                                }))
                                            }
                                            aria-invalid={
                                                errors.login_max_attempts
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.login_max_attempts
                                                    ? 'login-max-attempts-error'
                                                    : undefined
                                            }
                                        />
                                        <FieldError id="login-max-attempts-error">
                                            {errors.login_max_attempts}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.login_lockout_threshold
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="login_lockout_threshold">
                                            {t(
                                                'settings.instance.loginLockoutThreshold',
                                            )}
                                        </FieldLabel>
                                        <FieldDescription>
                                            {t(
                                                'settings.instance.loginLockoutThresholdDescription',
                                            )}
                                        </FieldDescription>
                                        <Input
                                            id="login_lockout_threshold"
                                            name="login_lockout_threshold"
                                            type="number"
                                            min={1}
                                            max={100}
                                            value={form.login_lockout_threshold}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    login_lockout_threshold:
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                }))
                                            }
                                            aria-invalid={
                                                errors.login_lockout_threshold
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <FieldError>
                                            {errors.login_lockout_threshold}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.login_lockout_minutes
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="login_lockout_minutes">
                                            {t(
                                                'settings.instance.loginLockoutMinutes',
                                            )}
                                        </FieldLabel>
                                        <FieldDescription>
                                            {t(
                                                'settings.instance.loginLockoutMinutesDescription',
                                            )}
                                        </FieldDescription>
                                        <Input
                                            id="login_lockout_minutes"
                                            name="login_lockout_minutes"
                                            type="number"
                                            min={1}
                                            max={1440}
                                            value={form.login_lockout_minutes}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    login_lockout_minutes:
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                }))
                                            }
                                            aria-invalid={
                                                errors.login_lockout_minutes
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <FieldError>
                                            {errors.login_lockout_minutes}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.two_factor_required
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="two_factor_required"
                                                checked={
                                                    form.two_factor_required
                                                }
                                                onCheckedChange={(checked) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        two_factor_required:
                                                            checked === true,
                                                    }))
                                                }
                                                aria-invalid={
                                                    errors.two_factor_required
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.two_factor_required
                                                        ? 'two-factor-required-error'
                                                        : 'two-factor-required-hint'
                                                }
                                            />
                                            <FieldLabel htmlFor="two_factor_required">
                                                {t(
                                                    'settings.instance.twoFactorRequired',
                                                )}
                                            </FieldLabel>
                                        </div>
                                        <FieldDescription
                                            id="two-factor-required-hint"
                                            className="pl-8"
                                        >
                                            {t(
                                                'settings.instance.twoFactorRequiredHint',
                                            )}
                                        </FieldDescription>
                                        <FieldError id="two-factor-required-error">
                                            {errors.two_factor_required}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </div>

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t(
                                        'settings.instance.registrationTitle',
                                    )}
                                    description={t(
                                        'settings.instance.registrationDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <Field
                                        data-invalid={
                                            errors.registration_enabled
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="registration_enabled"
                                                checked={
                                                    form.registration_enabled
                                                }
                                                onCheckedChange={(checked) =>
                                                    setForm((current) => ({
                                                        ...current,
                                                        registration_enabled:
                                                            checked === true,
                                                    }))
                                                }
                                                aria-invalid={
                                                    errors.registration_enabled
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.registration_enabled
                                                        ? 'registration-enabled-error'
                                                        : 'registration-enabled-hint'
                                                }
                                            />
                                            <FieldLabel htmlFor="registration_enabled">
                                                {t(
                                                    'settings.instance.registrationEnabled',
                                                )}
                                            </FieldLabel>
                                        </div>
                                        <FieldDescription
                                            id="registration-enabled-hint"
                                            className="pl-8"
                                        >
                                            {t(
                                                'settings.instance.registrationEnabledHint',
                                            )}
                                        </FieldDescription>
                                        <FieldError id="registration-enabled-error">
                                            {errors.registration_enabled}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </div>

                            <Separator />

                            <div className="space-y-6">
                                <Heading
                                    variant="small"
                                    title={t('settings.instance.oidcTitle')}
                                    description={t(
                                        'settings.instance.oidcDescription',
                                    )}
                                />

                                <FieldGroup className="gap-6">
                                    <SettingCheckbox
                                        name="oidc_enabled"
                                        label={t(
                                            'settings.instance.oidcEnabled',
                                        )}
                                        isChecked={form.oidc_enabled}
                                        error={errors.oidc_enabled}
                                        onCheckedChange={(isChecked) =>
                                            updateForm(
                                                'oidc_enabled',
                                                isChecked,
                                            )
                                        }
                                    />

                                    <SettingInput
                                        name="oidc_issuer"
                                        type="url"
                                        placeholder="https://id.example.com"
                                        label={t(
                                            'settings.instance.oidcIssuer',
                                        )}
                                        hint={t(
                                            'settings.instance.oidcIssuerHint',
                                        )}
                                        value={form.oidc_issuer}
                                        error={errors.oidc_issuer}
                                        onValueChange={(value) =>
                                            updateForm('oidc_issuer', value)
                                        }
                                    />

                                    <SettingInput
                                        name="oidc_client_id"
                                        label={t(
                                            'settings.instance.oidcClientId',
                                        )}
                                        value={form.oidc_client_id}
                                        error={errors.oidc_client_id}
                                        onValueChange={(value) =>
                                            updateForm('oidc_client_id', value)
                                        }
                                    />

                                    <SettingInput
                                        name="oidc_client_secret"
                                        type="password"
                                        autoComplete="new-password"
                                        placeholder={
                                            instance.has_oidc_client_secret
                                                ? '••••••••'
                                                : undefined
                                        }
                                        label={t(
                                            'settings.instance.oidcClientSecret',
                                        )}
                                        hint={
                                            instance.has_oidc_client_secret
                                                ? t(
                                                      'settings.instance.oidcSecretConfiguredHint',
                                                  )
                                                : t(
                                                      'settings.instance.oidcSecretEmptyHint',
                                                  )
                                        }
                                        value={form.oidc_client_secret}
                                        error={errors.oidc_client_secret}
                                        onValueChange={(value) =>
                                            updateForm(
                                                'oidc_client_secret',
                                                value,
                                            )
                                        }
                                    />

                                    <SettingInput
                                        name="oidc_button_label"
                                        placeholder={t(
                                            'settings.instance.oidcButtonLabelPlaceholder',
                                        )}
                                        label={t(
                                            'settings.instance.oidcButtonLabel',
                                        )}
                                        value={form.oidc_button_label}
                                        error={errors.oidc_button_label}
                                        onValueChange={(value) =>
                                            updateForm(
                                                'oidc_button_label',
                                                value,
                                            )
                                        }
                                    />

                                    <SettingCheckbox
                                        name="oidc_jit_provisioning"
                                        label={t(
                                            'settings.instance.oidcJitProvisioning',
                                        )}
                                        hint={t(
                                            'settings.instance.oidcJitProvisioningHint',
                                        )}
                                        isChecked={form.oidc_jit_provisioning}
                                        error={errors.oidc_jit_provisioning}
                                        onCheckedChange={(isChecked) =>
                                            updateForm(
                                                'oidc_jit_provisioning',
                                                isChecked,
                                            )
                                        }
                                    />

                                    <SettingCheckbox
                                        name="oidc_auto_link"
                                        label={t(
                                            'settings.instance.oidcAutoLink',
                                        )}
                                        hint={t(
                                            'settings.instance.oidcAutoLinkHint',
                                        )}
                                        isChecked={form.oidc_auto_link}
                                        error={errors.oidc_auto_link}
                                        onCheckedChange={(isChecked) =>
                                            updateForm(
                                                'oidc_auto_link',
                                                isChecked,
                                            )
                                        }
                                    />

                                    <SettingCheckbox
                                        name="oidc_only"
                                        label={t('settings.instance.oidcOnly')}
                                        hint={t(
                                            'settings.instance.oidcOnlyHint',
                                        )}
                                        isChecked={form.oidc_only}
                                        error={errors.oidc_only}
                                        onCheckedChange={(isChecked) =>
                                            updateForm('oidc_only', isChecked)
                                        }
                                    />

                                    <SettingCheckbox
                                        name="oidc_role_mapping_enabled"
                                        label={t(
                                            'settings.instance.oidcRoleMapping',
                                        )}
                                        hint={t(
                                            'settings.instance.oidcRoleMappingHint',
                                        )}
                                        isChecked={
                                            form.oidc_role_mapping_enabled
                                        }
                                        error={errors.oidc_role_mapping_enabled}
                                        onCheckedChange={(isChecked) =>
                                            updateForm(
                                                'oidc_role_mapping_enabled',
                                                isChecked,
                                            )
                                        }
                                    />

                                    <SettingInput
                                        name="oidc_organization"
                                        placeholder="acme"
                                        label={t(
                                            'settings.instance.oidcOrganization',
                                        )}
                                        hint={t(
                                            'settings.instance.oidcOrganizationHint',
                                        )}
                                        value={form.oidc_organization}
                                        error={errors.oidc_organization}
                                        onValueChange={(value) =>
                                            updateForm(
                                                'oidc_organization',
                                                value,
                                            )
                                        }
                                    />
                                </FieldGroup>

                                <p className="text-sm break-all text-muted-foreground">
                                    {t('settings.instance.oidcRedirectHint', {
                                        callbackUrl: oidcCallbackUrl,
                                        backChannelLogoutUrl:
                                            oidcBackChannelLogoutUrl,
                                    })}
                                </p>
                            </div>

                            <SettingsFormActions
                                processing={processing}
                                recentlySuccessful={recentlySuccessful}
                                data-test="instance-security-settings-save"
                            />
                        </>
                    )}
                </Form>
            </SettingsLayout>
        </AppLayout>
    );
}
