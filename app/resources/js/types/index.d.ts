export interface User {
    id: string;
    name: string;
    email: string;
    email_verified_at?: string;
}

export interface CompanySummary {
    id: string;
    name: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
        canManageUsers: boolean;
        canViewInventory: boolean;
        canViewClients: boolean;
        canViewSubscriptions: boolean;
        canViewPayments: boolean;
        canViewInspections: boolean;
        canViewTenancies: boolean;
        canViewRentPayments: boolean;
        canViewMaintenance: boolean;
    };
    company: CompanySummary | null;
};
