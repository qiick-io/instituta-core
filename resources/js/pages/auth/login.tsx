import { Form, Head, router, usePage } from '@inertiajs/react';
import { UserCancelledError } from '@laravel/passkeys';
import { usePasskeyVerify } from '@laravel/passkeys/react';
import { KeyRound } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import AuthLayout from '@/layouts/auth-layout';

/** qiick/laravel-oidc `SsoOnlyModeService::loginOptions()`. */
type OidcLoginOptions = {
    isSsoAvailable: boolean;
    isSsoOnly: boolean;
    isPasswordLoginVisible: boolean;
    buttonLabel: string;
    redirectUrl: string | null;
};

type Props = {
    status?: string;
    canResetPassword: boolean;
    canManagePasskeys?: boolean;
    oidc: OidcLoginOptions;
};

/**
 * User login form with optional SSO (qiick/laravel-oidc) and passkey sign-in.
 * Pattern from externa-core / excubia-app `pages/auth/login.tsx`.
 *
 * In SSO-only mode only the SSO button shows; `?local=1` brings back the local login for the
 * break-glass accounts (the server refuses everyone else either way).
 *
 * Conditional WebAuthn autofill is intentionally off (package isLoading can hang).
 */
export default function Login({
    status,
    canResetPassword,
    canManagePasskeys = false,
    oidc,
}: Props) {
    const { t } = useTranslation();
    // A refused SSO callback redirects here with an `email` error; the Form render-prop errors
    // only cover the password POST, so show it under the SSO button too.
    const pageEmailError = usePage().props.errors?.email;
    const canUseOidc = oidc.isSsoAvailable && oidc.redirectUrl !== null;
    const isLocalLoginVisible = oidc.isPasswordLoginVisible;
    const isPasskeyVisible = canManagePasskeys && isLocalLoginVisible;

    const layoutProps = {
        title: t('auth.login.title'),
        description: t('auth.login.description'),
    };

    const {
        verify,
        isLoading: verifyingPasskey,
        error: passkeyError,
        errorInstance: passkeyErrorInstance,
        isSupported: passkeysSupported,
    } = usePasskeyVerify({
        // ponytail: no conditional autofill — package isLoading blocks the CTA until the
        // hung ceremony resolves; upgrade path: call Passkeys.autofill() separately without
        // wiring its pending state to the button.
        autofill: false,
        onSuccess: (response) => {
            if (response.redirect) {
                router.visit(response.redirect);
            } else {
                router.visit('/');
            }
        },
    });

    const passkeyErrorMessage =
        passkeyErrorInstance instanceof UserCancelledError
            ? t('auth.login.passkeyCancelled')
            : passkeyError;

    const passkeyUnavailableHint = !passkeysSupported
        ? typeof window !== 'undefined' && !window.isSecureContext
            ? t('auth.login.passkeysNeedHttps')
            : t('auth.login.passkeysUnsupported')
        : undefined;

    const passkeyButton = (
        <Button
            type="button"
            variant="outline"
            className="w-full"
            disabled={!passkeysSupported || verifyingPasskey}
            data-test="passkey-login-button"
            aria-busy={verifyingPasskey}
            onClick={() => {
                if (passkeysSupported) {
                    void verify();
                }
            }}
        >
            {verifyingPasskey ? <Spinner /> : <KeyRound />}
            {t('auth.login.passkey')}
        </Button>
    );

    return (
        <AuthLayout {...layoutProps}>
            <Head title={t('auth.login.head')} />

            {(canUseOidc || isPasskeyVisible) && (
                <div className="mb-6 flex flex-col gap-3">
                    {canUseOidc && oidc.redirectUrl !== null && (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full"
                                data-test="oidc-login-button"
                                asChild
                            >
                                <a href={oidc.redirectUrl}>
                                    {oidc.buttonLabel.trim()
                                        ? oidc.buttonLabel
                                        : t('auth.login.oidc')}
                                </a>
                            </Button>
                            <FieldError
                                id="oidc-login-error"
                                data-test="oidc-login-error"
                            >
                                {pageEmailError}
                            </FieldError>
                        </>
                    )}
                    {isPasskeyVisible &&
                        (passkeyUnavailableHint ? (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span className="inline-flex w-full">
                                        {passkeyButton}
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {passkeyUnavailableHint}
                                </TooltipContent>
                            </Tooltip>
                        ) : (
                            passkeyButton
                        ))}
                    {isPasskeyVisible && passkeyErrorMessage ? (
                        <FieldError id="passkey-error">
                            {passkeyErrorMessage}
                        </FieldError>
                    ) : null}
                    {isLocalLoginVisible && (
                        <div className="relative py-1">
                            <div className="absolute inset-0 flex items-center">
                                <span className="w-full border-t" />
                            </div>
                            <div className="relative flex justify-center text-xs uppercase">
                                <span className="bg-background px-2 text-muted-foreground">
                                    {t('auth.login.orContinueWithEmail')}
                                </span>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {isLocalLoginVisible && (
                <Form
                    {...store.form()}
                    resetOnSuccess={['password']}
                    className="flex flex-col gap-6"
                    noValidate
                >
                    {({ processing, errors }) => (
                        <>
                            <FieldGroup className="gap-6">
                                <Field
                                    data-invalid={
                                        errors.email ? true : undefined
                                    }
                                >
                                    <FieldLabel htmlFor="email">
                                        {t('common.emailAddress')}
                                    </FieldLabel>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        autoFocus
                                        autoComplete="email"
                                        placeholder="email@example.com"
                                        aria-invalid={
                                            errors.email ? true : undefined
                                        }
                                        aria-describedby={
                                            errors.email
                                                ? 'email-error'
                                                : undefined
                                        }
                                    />
                                    <FieldError id="email-error">
                                        {errors.email}
                                    </FieldError>
                                </Field>

                                <Field
                                    data-invalid={
                                        errors.password ? true : undefined
                                    }
                                >
                                    <div className="flex items-center">
                                        <FieldLabel htmlFor="password">
                                            {t('common.password')}
                                        </FieldLabel>
                                        {canResetPassword && (
                                            <TextLink
                                                href={request()}
                                                className="ml-auto text-sm"
                                            >
                                                {t('auth.login.forgotPassword')}
                                            </TextLink>
                                        )}
                                    </div>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        autoComplete="current-password"
                                        placeholder={t('common.password')}
                                        aria-invalid={
                                            errors.password ? true : undefined
                                        }
                                        aria-describedby={
                                            errors.password
                                                ? 'password-error'
                                                : undefined
                                        }
                                    />
                                    <FieldError id="password-error">
                                        {errors.password}
                                    </FieldError>
                                </Field>

                                <Field orientation="horizontal">
                                    <Checkbox id="remember" name="remember" />
                                    <FieldLabel htmlFor="remember">
                                        {t('auth.login.rememberMe')}
                                    </FieldLabel>
                                </Field>

                                <Button
                                    type="submit"
                                    className="mt-4 w-full"
                                    disabled={processing}
                                    aria-busy={processing || undefined}
                                    data-test="login-button"
                                >
                                    {processing && <Spinner />}
                                    {processing ? (
                                        <span className="sr-only" role="status">
                                            {t('a11y.loading')}
                                        </span>
                                    ) : null}
                                    {t('auth.login.submit')}
                                </Button>
                            </FieldGroup>
                        </>
                    )}
                </Form>
            )}

            {status && (
                <div
                    className="mb-4 text-center text-sm font-medium text-success"
                    role="status"
                    aria-live="polite"
                >
                    {status}
                </div>
            )}
        </AuthLayout>
    );
}
