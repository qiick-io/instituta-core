import { Form, Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientsController from '@/actions/App/Http/Controllers/Brain/ClientsController';
import InputError from '@/components/input-error';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ClientProps = {
    id: string;
    name: string;
    slug: string;
};

type Props = {
    client: ClientProps;
};

/**
 * Edit an owned client company's name and slug.
 */
export default function BrainClientsEdit({ client }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('brain.clients.editTitle')} />
            <PageLayout description={t('brain.clients.editDescription')}>
                <div className="space-y-1 pb-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('brain.clients.editTitle')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('brain.clients.editDescription')}
                    </p>
                </div>

                <Form
                    {...ClientsController.update.form(client.id)}
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
                                    defaultValue={client.name}
                                    autoComplete="organization"
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
                                    defaultValue={client.slug}
                                    className="font-mono text-sm"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('brain.clients.slugHint')}
                                </p>
                                <InputError message={errors.slug} />
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <Button type="submit" disabled={processing}>
                                    {t('brain.clients.saveEdit')}
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

BrainClientsEdit.layout = {
    breadcrumbs: [
        {
            title: 'Clients',
            href: '/brain/clients',
        },
        {
            title: 'Edit',
            href: '/brain/clients',
        },
    ],
};
