import { Form, Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientsController from '@/actions/App/Http/Controllers/Brain/ClientsController';
import InputError from '@/components/input-error';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Create a client company under the current organisation.
 */
export default function BrainClientsCreate() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('brain.clients.createTitle')} />
            <PageLayout description={t('brain.clients.createDescription')}>
                <div className="space-y-1 pb-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('brain.clients.createTitle')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('brain.clients.createDescription')}
                    </p>
                </div>

                <Form
                    {...ClientsController.store.form()}
                    className="max-w-lg space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('brain.clients.fieldName')}
                                </Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    maxLength={255}
                                    autoComplete="organization"
                                    placeholder={t(
                                        'brain.clients.namePlaceholder',
                                    )}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="slug">
                                    {t('brain.clients.fieldSlug')}
                                </Label>
                                <Input
                                    id="slug"
                                    name="slug"
                                    required
                                    maxLength={64}
                                    className="font-mono text-sm"
                                    placeholder={t(
                                        'brain.clients.slugPlaceholder',
                                    )}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('brain.clients.slugHint')}
                                </p>
                                <InputError message={errors.slug} />
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <Button type="submit" disabled={processing}>
                                    {t('brain.clients.saveCreate')}
                                </Button>
                                <Button type="button" variant="outline" asChild>
                                    <Link href="/brain/clients">
                                        {t('brain.clients.cancel')}
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PageLayout>
        </>
    );
}

BrainClientsCreate.layout = {
    breadcrumbs: [
        {
            title: 'Clients',
            href: '/brain/clients',
        },
        {
            title: 'Create',
            href: '/brain/clients/create',
        },
    ],
};
