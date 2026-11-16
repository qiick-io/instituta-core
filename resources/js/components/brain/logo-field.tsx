import { ImageUp, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { UserAvatar } from '@/components/admin/user-avatar';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';

/**
 * The optional logo of a client or product (#257): the avatar as it will look (the chosen file,
 * the current logo, or initials), a button to pick an image and one to remove it. PNG, JPEG or
 * WebP up to 1 MB; the server checks the same and refuses SVG.
 */
export function LogoField({
    id,
    avatarId,
    name,
    currentUrl,
    file,
    isRemoved,
    error,
    onFileChange,
    onRemove,
    inputName,
}: {
    /** In a native `<Form>`, the field names the file input and adds `remove_logo`. */
    inputName?: string;
    id: string;
    /** Stable id that picks the initials' colour. */
    avatarId: string;
    name: string;
    currentUrl: string | null;
    file: File | null;
    isRemoved: boolean;
    error?: string;
    onFileChange: (file: File | null) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslation();
    const inputRef = useRef<HTMLInputElement>(null);
    const previewUrl = useMemo(
        () => (file ? URL.createObjectURL(file) : null),
        [file],
    );

    useEffect(
        () => () => {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
        },
        [previewUrl],
    );

    const shownUrl = previewUrl ?? (isRemoved ? null : currentUrl);

    return (
        <Field data-invalid={error ? true : undefined}>
            <FieldLabel htmlFor={id}>{t('brain.logo.label')}</FieldLabel>
            <div className="flex items-center gap-3">
                <UserAvatar
                    userId={avatarId}
                    name={name || '?'}
                    imageUrl={shownUrl}
                    size="lg"
                />
                {inputName ? (
                    <input
                        type="hidden"
                        name="remove_logo"
                        value={isRemoved ? '1' : '0'}
                    />
                ) : null}
                <input
                    ref={inputRef}
                    id={id}
                    name={inputName}
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    className="sr-only"
                    aria-describedby={`${id}-hint`}
                    onChange={(event) =>
                        onFileChange(event.target.files?.[0] ?? null)
                    }
                />
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => inputRef.current?.click()}
                >
                    <ImageUp />
                    {shownUrl
                        ? t('brain.logo.replace')
                        : t('brain.logo.upload')}
                </Button>
                {shownUrl ? (
                    <Button
                        type="button"
                        variant="ghost"
                        className="text-destructive hover:text-destructive"
                        onClick={() => {
                            if (inputRef.current) {
                                inputRef.current.value = '';
                            }

                            onRemove();
                        }}
                    >
                        <Trash2 />
                        {t('brain.logo.remove')}
                    </Button>
                ) : null}
            </div>
            <FieldDescription id={`${id}-hint`}>
                {t('brain.logo.hint')}
            </FieldDescription>
            <FieldError>{error}</FieldError>
        </Field>
    );
}
