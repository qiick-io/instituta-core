export type InstitutaScopeOption = {
    scope_type: 'organisation' | 'company' | 'product';
    scope_id: string;
    label: string;
    role: string;
};

export type InstitutaPerimeter = {
    principal_id: string;
    principal_type: string;
    scope_type: 'organisation' | 'company' | 'product';
    scope_id: string;
    role: string;
    max_visibility: string;
    path_prefix: string | null;
    area: string | null;
};

export type InstitutaShared = {
    perimeter: InstitutaPerimeter | null;
    scopes: InstitutaScopeOption[];
    label: string | null;
};
