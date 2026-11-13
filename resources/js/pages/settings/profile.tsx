import { Form, Head, Link, usePage } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import { Alert, AlertTitle } from '@/components/ui/alert';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

/**
 * User profile settings: first name, last name and email (qiick shape).
 */
export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

    if (user === null) {
        return null;
    }

    return (
        <>
            <Head title={t('settings.profile.head')} />

            <h1 className="sr-only">{t('settings.profile.head')}</h1>

            <div className="space-y-12">
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title={t('settings.profile.title')}
                        description={t('settings.profile.description')}
                    />

                    <Form
                        {...ProfileController.update.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        className="space-y-6"
                        noValidate
                    >
                        {({ processing, recentlySuccessful, errors }) => (
                            <>
                                <FieldGroup className="gap-6">
                                    <Field
                                        data-invalid={
                                            errors.first_name ? true : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="first_name">
                                            {t('common.firstName')}
                                        </FieldLabel>

                                        <Input
                                            id="first_name"
                                            className="mt-1 block w-full"
                                            defaultValue={user.first_name}
                                            name="first_name"
                                            required
                                            autoComplete="given-name"
                                            placeholder={t('common.firstName')}
                                            aria-invalid={
                                                errors.first_name
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.first_name
                                                    ? 'first-name-error'
                                                    : undefined
                                            }
                                        />

                                        <FieldError id="first-name-error">
                                            {errors.first_name}
                                        </FieldError>
                                    </Field>

                                    <Field
                                        data-invalid={
                                            errors.last_name ? true : undefined
                                        }
                                    >
                                        <FieldLabel htmlFor="last_name">
                                            {t('common.lastName')}
                                        </FieldLabel>

                                        <Input
                                            id="last_name"
                                            className="mt-1 block w-full"
                                            defaultValue={user.last_name ?? ''}
                                            name="last_name"
                                            autoComplete="family-name"
                                            placeholder={t('common.lastName')}
                                            aria-invalid={
                                                errors.last_name
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.last_name
                                                    ? 'last-name-error'
                                                    : undefined
                                            }
                                        />

                                        <FieldError id="last-name-error">
                                            {errors.last_name}
                                        </FieldError>
                                    </Field>

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
                                            className="mt-1 block w-full"
                                            defaultValue={user.email}
                                            name="email"
                                            required
                                            autoComplete="username"
                                            placeholder={t(
                                                'common.emailAddress',
                                            )}
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
                                </FieldGroup>

                                {mustVerifyEmail &&
                                    user.email_verified_at === null && (
                                        <div>
                                            <p className="-mt-4 text-sm text-muted-foreground">
                                                {t(
                                                    'settings.profile.unverified',
                                                )}{' '}
                                                <Link
                                                    href={send()}
                                                    as="button"
                                                    className="text-foreground underline decoration-muted-foreground underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                                                >
                                                    {t(
                                                        'settings.profile.resend',
                                                    )}
                                                </Link>
                                            </p>

                                            {status ===
                                                'verification-link-sent' && (
                                                <Alert
                                                    variant="success"
                                                    role="status"
                                                    className="mt-2"
                                                >
                                                    <CircleCheck />
                                                    <AlertTitle className="line-clamp-none">
                                                        {t(
                                                            'settings.profile.linkSent',
                                                        )}
                                                    </AlertTitle>
                                                </Alert>
                                            )}
                                        </div>
                                    )}

                                <SettingsFormActions
                                    processing={processing}
                                    recentlySuccessful={recentlySuccessful}
                                    data-test="update-profile-button"
                                />
                            </>
                        )}
                    </Form>
                </div>

                <Separator />

                <DeleteUser />
            </div>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
