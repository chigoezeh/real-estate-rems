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
    };
    company: CompanySummary | null;
};
