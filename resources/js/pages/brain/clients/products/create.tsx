import { Head, Link, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import ClientProductsController from '@/actions/App/Http/Controllers/Brain/ClientProductsController';
import InputError from '@/components/input-error';
import { PageLayout } from '@/components/layout/page-layout';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ClientSummary = {
    id: string;
    name: string;
    slug: string;
};

type Props = {
    client: ClientSummary;
};

/**
 * Create a product under a client company.
 */
export default function BrainClientProductsCreate({ client }: Props) {
    const { t } = useTranslation();
    const form = useForm({
        name: '',
        slug: '',
        inherits_from_parent: true,
    });

    return (
        <>
            <Head title={t('brain.products.createTitle')} />
            <PageLayout
                description={t('brain.products.createDescription', {
                    client: client.name,
                })}
            >
                <div className="space-y-1 pb-2">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('brain.products.createTitle')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('brain.products.createDescription', {
                            client: client.name,
                        })}
                    </p>
                </div>

                <form
                    className="max-w-lg space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(
                            ClientProductsController.store.url(client.id),
                        );
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="name">
                            {t('brain.products.fieldName')}
                        </Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            required
                            maxLength={255}
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="slug">
                            {t('brain.products.fieldSlug')}
                        </Label>
                        <Input
                            id="slug"
                            value={form.data.slug}
                            onChange={(event) =>
                                form.setData('slug', event.target.value)
                            }
                            required
                            maxLength={64}
                            className="font-mono text-sm"
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('brain.products.slugHint')}
                        </p>
                        <InputError message={form.errors.slug} />
                    </div>

                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="inherits_from_parent"
                            checked={form.data.inherits_from_parent}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'inherits_from_parent',
                                    checked === true,
                                )
                            }
                        />
                        <div className="space-y-1">
                            <Label htmlFor="inherits_from_parent">
                                {t('brain.products.fieldInherit')}
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                {t('brain.products.inheritHint')}
                            </p>
                        </div>
                    </div>
                    <InputError message={form.errors.inherits_from_parent} />

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={form.processing}>
                            {t('brain.products.saveCreate')}
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href={`/brain/clients/${client.id}/products`}>
                                {t('brain.products.cancel')}
                            </Link>
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </>
    );
}

BrainClientProductsCreate.layout = {
    breadcrumbs: [
        {
            title: 'Clients',
            href: '/brain/clients',
        },
        {
            title: 'Products',
            href: '/brain/clients',
        },
        {
            title: 'Create',
            href: '/brain/clients',
        },
    ],
};
