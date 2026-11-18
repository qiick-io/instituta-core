export type InstitutaScopeOption = {
    scope_type: 'organisation' | 'company' | 'product';
    scope_id: string;
    label: string;
    role: string;
    kind?: 'own' | 'client' | null;
};

export type InstitutaPerimeter = {
    principal_id: string;
    principal_type: string;
    scope_type: 'organisation' | 'company' | 'product';
    scope_id: string;
    role: string;
    max_visibility: string;
    /** Allowed folders (#334); empty: every path. */
    path_prefixes: string[];
    /** Allowed areas (#334); empty: every area. */
    areas: string[];
};

export type InstitutaShared = {
    perimeter: InstitutaPerimeter | null;
    scopes: InstitutaScopeOption[];
    label: string | null;
};
