import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import { useTranslation } from 'react-i18next';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { SettingsFormActions } from '@/components/settings-form-actions';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Separator } from '@/components/ui/separator';
import { edit } from '@/routes/security';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

/**
 * Password, two-factor, and passkey security settings.
 */
export default function Security(props: Props) {
    const { t } = useTranslation();
    const layoutProps = {
        breadcrumbs: [
            {
                title: t('settings.layout.security'),
                href: edit(),
            },
        ],
    };
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <AppLayout {...layoutProps}>
            <SettingsLayout>
                <Head title={t('settings.security.head')} />

                <h1 className="sr-only">{t('settings.security.head')}</h1>

                <div className="space-y-12">
                    <div className="space-y-6">
                        <Heading
                            variant="small"
                            title={t('settings.security.passwordTitle')}
                            description={t(
                                'settings.security.passwordDescription',
                            )}
                        />

                        <Form
                            {...SecurityController.update.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            resetOnError={[
                                'password',
                                'password_confirmation',
                                'current_password',
                            ]}
                            resetOnSuccess
                            onError={(formErrors) => {
                                if (formErrors.password) {
                                    passwordInput.current?.focus();
                                }

                                if (formErrors.current_password) {
                                    currentPasswordInput.current?.focus();
                                }
                            }}
                            className="space-y-6"
                            noValidate
                        >
                            {({
                                errors: formErrors,
                                processing,
                                recentlySuccessful,
                            }) => (
                                <>
                                    <FieldGroup className="gap-6">
                                        <Field
                                            data-invalid={
                                                formErrors.current_password
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="current_password">
                                                {t(
                                                    'settings.security.currentPassword',
                                                )}
                                            </FieldLabel>

                                            <PasswordInput
                                                id="current_password"
                                                ref={currentPasswordInput}
                                                name="current_password"
                                                className="mt-1"
                                                autoComplete="current-password"
                                                placeholder={t(
                                                    'settings.security.currentPassword',
                                                )}
                                                aria-invalid={
                                                    formErrors.current_password
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    formErrors.current_password
                                                        ? 'current-password-error'
                                                        : undefined
                                                }
                                            />

                                            <FieldError id="current-password-error">
                                                {formErrors.current_password}
                                            </FieldError>
                                        </Field>

                                        <Field
                                            data-invalid={
                                                formErrors.password
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="password">
                                                {t(
                                                    'settings.security.newPassword',
                                                )}
                                            </FieldLabel>

                                            <PasswordInput
                                                id="password"
                                                ref={passwordInput}
                                                name="password"
                                                className="mt-1"
                                                autoComplete="new-password"
                                                placeholder={t(
                                                    'settings.security.newPassword',
                                                )}
                                                passwordrules={
                                                    props.passwordRules
                                                }
                                                aria-invalid={
                                                    formErrors.password
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    formErrors.password
                                                        ? 'password-error'
                                                        : undefined
                                                }
                                            />

                                            <FieldError id="password-error">
                                                {formErrors.password}
                                            </FieldError>
                                        </Field>

                                        <Field
                                            data-invalid={
                                                formErrors.password_confirmation
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="password_confirmation">
                                                {t('common.confirmPassword')}
                                            </FieldLabel>

                                            <PasswordInput
                                                id="password_confirmation"
                                                name="password_confirmation"
                                                className="mt-1"
                                                autoComplete="new-password"
                                                placeholder={t(
                                                    'common.confirmPassword',
                                                )}
                                                passwordrules={
                                                    props.passwordRules
                                                }
                                                aria-invalid={
                                                    formErrors.password_confirmation
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    formErrors.password_confirmation
                                                        ? 'password-confirmation-error'
                                                        : undefined
                                                }
                                            />

                                            <FieldError id="password-confirmation-error">
                                                {
                                                    formErrors.password_confirmation
                                                }
                                            </FieldError>
                                        </Field>
                                    </FieldGroup>

                                    <SettingsFormActions
                                        processing={processing}
                                        recentlySuccessful={recentlySuccessful}
                                        data-test="update-password-button"
                                    />
                                </>
                            )}
                        </Form>
                    </div>

                    {(props.canManageTwoFactor ?? false) ? (
                        <>
                            <Separator />
                            <ManageTwoFactor
                                canManageTwoFactor={props.canManageTwoFactor}
                                requiresConfirmation={
                                    props.requiresConfirmation
                                }
                                twoFactorEnabled={props.twoFactorEnabled}
                            />
                        </>
                    ) : null}

                    {(props.canManagePasskeys ?? false) ? (
                        <>
                            <Separator />
                            <ManagePasskeys
                                canManagePasskeys={props.canManagePasskeys}
                                passkeys={props.passkeys}
                            />
                        </>
                    ) : null}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
