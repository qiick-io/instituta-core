import { Form, Head } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { login } from '@/routes';
import { email } from '@/routes/password';
import AuthLayout from '@/layouts/auth-layout';

/**
 * Request a password reset link.
 * Copied from excubia-app `pages/auth/forgot-password.tsx` (layout via setLayoutProps).
 */
export default function ForgotPassword({ status }: { status?: string }) {
    const { t } = useTranslation();

    const layoutProps = {
        title: t('auth.forgotPassword.title'),
        description: t('auth.forgotPassword.description'),
    };

    return (
        <AuthLayout {...layoutProps}>
            <Head title={t('auth.forgotPassword.head')} />

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-success">
                    {status}
                </div>
            )}

            <div className="space-y-6">
                <Form {...email.form()} noValidate>
                    {({ processing, errors }) => (
                        <FieldGroup>
                            <Field
                                data-invalid={errors.email ? true : undefined}
                            >
                                <FieldLabel htmlFor="email">
                                    {t('common.emailAddress')}
                                </FieldLabel>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    autoComplete="off"
                                    autoFocus
                                    placeholder="email@example.com"
                                    aria-invalid={
                                        errors.email ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.email ? 'email-error' : undefined
                                    }
                                />
                                <FieldError id="email-error">
                                    {errors.email}
                                </FieldError>
                            </Field>

                            <div className="my-6 flex items-center justify-start">
                                <Button
                                    className="w-full"
                                    disabled={processing}
                                    data-test="email-password-reset-link-button"
                                >
                                    {processing && (
                                        <LoaderCircle className="h-4 w-4 animate-spin" />
                                    )}
                                    {t('auth.forgotPassword.submit')}
                                </Button>
                            </div>
                        </FieldGroup>
                    )}
                </Form>

                <div className="space-x-1 text-center text-sm text-muted-foreground">
                    <span>{t('auth.forgotPassword.orReturn')}</span>
                    <TextLink href={login()}>
                        {t('auth.forgotPassword.logIn')}
                    </TextLink>
                </div>
            </div>
        </AuthLayout>
    );
}
