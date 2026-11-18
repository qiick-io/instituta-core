import { useTranslation } from 'react-i18next';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';

export type AreaLimitsFieldsProps = {
    /** Prefix for element ids, unique on the page (`group_brain`, `api_key`). */
    idPrefix: string;
    /** Areas that can be chosen. */
    areaOptions: string[];
    /** Chosen areas; none chosen means every area. */
    areas: string[];
    onAreasChange: (areas: string[]) => void;
    pathPrefix: string;
    onPathPrefixChange: (pathPrefix: string) => void;
    disabled?: boolean;
    areasError?: string;
    pathPrefixError?: string;
};

/**
 * Area and folder limits for brain access (#334), shared by the group drawer and the API key
 * dialog: the areas as checkboxes (none checked: every area) and one optional folder. The server
 * validates both; this only collects them.
 * @param {AreaLimitsFieldsProps} props - Component props.
 * @returns {JSX.Element}
 */
export function AreaLimitsFields({
    idPrefix,
    areaOptions,
    areas,
    onAreasChange,
    pathPrefix,
    onPathPrefixChange,
    disabled = false,
    areasError,
    pathPrefixError,
}: AreaLimitsFieldsProps) {
    const { t } = useTranslation();

    const toggleArea = (area: string, isChecked: boolean): void => {
        onAreasChange(
            isChecked
                ? [...areas, area]
                : areas.filter((chosenArea) => chosenArea !== area),
        );
    };

    return (
        <>
            <FieldSet data-invalid={areasError ? true : undefined}>
                <FieldLegend variant="label">
                    {t('brain.access.areasLabel')}
                </FieldLegend>
                <FieldDescription id={`${idPrefix}-areas-hint`}>
                    {t('brain.access.areasHint')}
                </FieldDescription>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {areaOptions.map((area) => (
                        <Field key={area} orientation="horizontal">
                            <Checkbox
                                id={`${idPrefix}_area_${area}`}
                                checked={areas.includes(area)}
                                disabled={disabled}
                                onCheckedChange={(checked) =>
                                    toggleArea(area, checked === true)
                                }
                            />
                            <FieldLabel
                                htmlFor={`${idPrefix}_area_${area}`}
                                className="font-normal"
                            >
                                {t(`brain.areas.${area}`, {
                                    defaultValue: area,
                                })}
                            </FieldLabel>
                        </Field>
                    ))}
                </div>
                <FieldError>{areasError}</FieldError>
            </FieldSet>

            <Field data-invalid={pathPrefixError ? true : undefined}>
                <FieldLabel htmlFor={`${idPrefix}_path_prefix`}>
                    {t('brain.access.pathPrefixLabel')}
                </FieldLabel>
                <Input
                    id={`${idPrefix}_path_prefix`}
                    value={pathPrefix}
                    disabled={disabled}
                    maxLength={255}
                    placeholder={t('brain.access.pathPrefixPlaceholder')}
                    onChange={(event) => onPathPrefixChange(event.target.value)}
                    aria-invalid={pathPrefixError ? true : undefined}
                    aria-describedby={`${idPrefix}-path-prefix-hint`}
                />
                <FieldDescription id={`${idPrefix}-path-prefix-hint`}>
                    {t('brain.access.pathPrefixHint')}
                </FieldDescription>
                <FieldError>{pathPrefixError}</FieldError>
            </Field>
        </>
    );
}
