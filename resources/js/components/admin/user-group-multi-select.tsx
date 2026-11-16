import groups from '@/routes/groups';
import type { AdminRoleRef } from '@/types/admin';
import { PaginatedMultiSelect } from './paginated-multi-select';
import type {
    PaginatedMultiSelectProps,
    PickerOption,
} from './paginated-multi-select';

export type UserGroupMultiSelectProps = Omit<
    PaginatedMultiSelectProps,
    'fetchUrl' | 'placeholder'
> & {
    placeholder?: string;
    /** Selected groups, so the trigger can label them before the first search. */
    initialGroups?: AdminRoleRef[] | null;
};

/**
 * User group picker on the registry picker, searching `groups.index` (JSON).
 * Copied from externa-core.
 * @param {*} props - Component props.
 * @returns {JSX.Element}
 */
export function UserGroupMultiSelect({
    placeholder = 'Select groups…',
    initialGroups,
    initialOptions,
    ...props
}: UserGroupMultiSelectProps) {
    const resolvedInitialOptions: PickerOption[] =
        initialOptions ??
        initialGroups?.map((group) => ({ id: group.id, label: group.name })) ??
        [];

    return (
        <PaginatedMultiSelect
            fetchUrl={groups.index.url()}
            placeholder={placeholder}
            initialOptions={resolvedInitialOptions}
            {...props}
        />
    );
}
