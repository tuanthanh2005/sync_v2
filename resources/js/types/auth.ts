export type UserRole = 'user' | 'moderator' | 'admin';

export type UserStatus = 'active' | 'suspended' | 'banned';

export type UserPermissions = {
    accessAdmin: boolean;
    accessModeration: boolean;
    reviewAds: boolean;
    managePolicies: boolean;
};

export type User = {
    id: number;
    name: string;
    username: string;
    email: string;
    avatar?: string;
    role: UserRole;
    role_label: string;
    status: UserStatus;
    status_label: string;
    timezone: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string | null;
    updated_at: string | null;
    can: UserPermissions;
};

export type Auth = {
    user: User | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
