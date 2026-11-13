import { router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { InstitutaScopeOption } from '@/types';

function scopeValue(
    scope: Pick<InstitutaScopeOption, 'scope_type' | 'scope_id'>,
): string {
    return `${scope.scope_type}:${scope.scope_id}`;
}

/**
 * Header control that switches the session brain perimeter.
 */
export function ScopeSwitcher() {
    const { t } = useTranslation();
    const { instituta } = usePage().props;
    const scopes = instituta?.scopes ?? [];
    const perimeter = instituta?.perimeter;

    if (scopes.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('brain.scope.none')}
            </p>
        );
    }

    const current =
        perimeter !== null && perimeter !== undefined
            ? scopeValue(perimeter)
            : scopeValue(scopes[0]);

    return (
        <Select
            value={current}
            onValueChange={(value) => {
                const [scopeType, scopeId] = value.split(':');
                if (!scopeType || !scopeId) {
                    return;
                }

                router.post(
                    '/brain/scope',
                    {
                        scope_type: scopeType,
                        scope_id: scopeId,
                    },
                    {
                        preserveScroll: true,
                    },
                );
            }}
        >
            <SelectTrigger
                size="sm"
                aria-label={t('brain.scope.label')}
                className="max-w-56"
            >
                <SelectValue placeholder={t('brain.scope.label')} />
            </SelectTrigger>
            <SelectContent align="end">
                {scopes.map((scope) => (
                    <SelectItem
                        key={scopeValue(scope)}
                        value={scopeValue(scope)}
                    >
                        {scope.kind === 'client'
                            ? t('brain.scope.clientLabel', {
                                  name: scope.label,
                              })
                            : scope.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
