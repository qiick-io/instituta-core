import { Form, Head, Link, usePage } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import NotificationPreferencesController from '@/actions/App/Http/Controllers/Settings/NotificationPreferencesController';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import { SettingsFormActions } from '@/components/settings-form-actions';
import { Alert, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import {
    playTestSound,
    unlockNotificationSound,
} from '@/lib/notification-sound';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';

type PageProps = {
    auth: Auth;
    availableLocales: Record<string, string>;
    notifications: {
        unread_count: number;
        sound_enabled: boolean;
    };
};

/**
 * User profile settings: name, email, locale and timezone (qiick shape).
 */
export default function Profile({
    mustVerifyEmail,
    status,
    timezones,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    timezones: string[];
}) {
    const { t } = useTranslation();
    const layoutProps = {
        breadcrumbs: [
            {
                title: t('settings.layout.profile'),
                href: edit(),
            },
        ],
    };
    const { auth, availableLocales, notifications } =
        usePage<PageProps>().props;
    const user = auth.user;
    const [isSoundEnabled, setIsSoundEnabled] = useState(
        notifications?.sound_enabled ?? false,
    );

    if (user === null) {
        return null;
    }

    return (
        <AppLayout {...layoutProps}>
            <SettingsLayout>
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
                                                errors.first_name
                                                    ? true
                                                    : undefined
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
                                                placeholder={t(
                                                    'common.firstName',
                                                )}
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
                                                errors.last_name
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="last_name">
                                                {t('common.lastName')}
                                            </FieldLabel>

                                            <Input
                                                id="last_name"
                                                className="mt-1 block w-full"
                                                defaultValue={
                                                    user.last_name ?? ''
                                                }
                                                name="last_name"
                                                autoComplete="family-name"
                                                placeholder={t(
                                                    'common.lastName',
                                                )}
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
                                                    errors.email
                                                        ? true
                                                        : undefined
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
                                                errors.locale ? true : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="locale">
                                                {t('settings.profile.locale')}
                                            </FieldLabel>

                                            <NativeSelect
                                                id="locale"
                                                name="locale"
                                                required
                                                defaultValue={
                                                    user.locale ?? 'en'
                                                }
                                                className="w-full"
                                                aria-invalid={
                                                    errors.locale
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.locale
                                                        ? 'locale-error'
                                                        : undefined
                                                }
                                            >
                                                {Object.entries(
                                                    availableLocales,
                                                ).map(([code, label]) => (
                                                    <NativeSelectOption
                                                        key={code}
                                                        value={code}
                                                    >
                                                        {label}
                                                    </NativeSelectOption>
                                                ))}
                                            </NativeSelect>

                                            <FieldError id="locale-error">
                                                {errors.locale}
                                            </FieldError>
                                        </Field>

                                        <Field
                                            data-invalid={
                                                errors.timezone
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="timezone">
                                                {t('settings.profile.timezone')}
                                            </FieldLabel>

                                            <NativeSelect
                                                id="timezone"
                                                name="timezone"
                                                required
                                                defaultValue={
                                                    user.timezone ?? 'UTC'
                                                }
                                                className="w-full"
                                                aria-invalid={
                                                    errors.timezone
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    errors.timezone
                                                        ? 'timezone-error'
                                                        : undefined
                                                }
                                            >
                                                {timezones.map((timezone) => (
                                                    <NativeSelectOption
                                                        key={timezone}
                                                        value={timezone}
                                                    >
                                                        {timezone}
                                                    </NativeSelectOption>
                                                ))}
                                            </NativeSelect>

                                            <FieldError id="timezone-error">
                                                {errors.timezone}
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

                    <div className="space-y-6">
                        <Heading
                            variant="small"
                            title={t('settings.notificationSounds.title')}
                            description={t(
                                'settings.notificationSounds.description',
                            )}
                        />

                        <Form
                            {...NotificationPreferencesController.update.form()}
                            options={{ preserveScroll: true }}
                            className="space-y-6"
                        >
                            {({ processing, recentlySuccessful, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="sound_notifications_enabled"
                                        value={isSoundEnabled ? '1' : '0'}
                                    />

                                    <Field
                                        orientation="horizontal"
                                        data-invalid={
                                            errors.sound_notifications_enabled
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <Checkbox
                                            id="sound_notifications_enabled"
                                            checked={isSoundEnabled}
                                            onCheckedChange={(value) => {
                                                const enabled = value === true;
                                                setIsSoundEnabled(enabled);

                                                if (enabled) {
                                                    unlockNotificationSound();
                                                }
                                            }}
                                            aria-invalid={
                                                errors.sound_notifications_enabled
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.sound_notifications_enabled
                                                    ? 'notification-sounds-error'
                                                    : 'sound-notifications-hint'
                                            }
                                        />
                                        <div className="grid gap-1">
                                            <FieldLabel
                                                htmlFor="sound_notifications_enabled"
                                                className="font-normal"
                                            >
                                                {t(
                                                    'settings.notificationSounds.notifications',
                                                )}
                                            </FieldLabel>
                                            <FieldDescription id="sound-notifications-hint">
                                                {t(
                                                    'settings.notificationSounds.notificationsHint',
                                                )}
                                            </FieldDescription>
                                        </div>
                                    </Field>

                                    <FieldError id="notification-sounds-error">
                                        {errors.sound_notifications_enabled}
                                    </FieldError>

                                    <div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            data-test="notification-sounds-test"
                                            onClick={() => {
                                                playTestSound();
                                            }}
                                        >
                                            {t(
                                                'settings.notificationSounds.test',
                                            )}
                                        </Button>
                                    </div>

                                    <SettingsFormActions
                                        processing={processing}
                                        recentlySuccessful={recentlySuccessful}
                                        data-test="notification-sounds-save"
                                    />
                                </>
                            )}
                        </Form>
                    </div>

                    <Separator />

                    <DeleteUser />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
