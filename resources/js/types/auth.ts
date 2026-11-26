export type UserRole = 'user' | 'moderator' | 'admin';

export type UserStatus = 'active' | 'suspended' | 'banned';

export type UserPermissions = {
    accessAdmin: boolean;
    accessModeration: boolean;
    reviewAds: boolean;
    managePolicies: boolean;
    viewAnalytics?: boolean;
    viewSystemHealth?: boolean;
    manageUsers?: boolean;
    manageRoles?: boolean;
    manageVerifications?: boolean;
    managePosts?: boolean;
    manageComments?: boolean;
    manageCommunities?: boolean;
    managePolls?: boolean;
    manageHashtags?: boolean;
    manageMedia?: boolean;
    manageAppeals?: boolean;
    manageKeywords?: boolean;
    manageFinance?: boolean;
    manageMonetization?: boolean;
    managePremium?: boolean;
    manageBanners?: boolean;
    manageBoosts?: boolean;
    manageAdSense?: boolean;
    manageSettings?: boolean;
    manageLanguages?: boolean;
    manageNotifications?: boolean;
    viewSecurity?: boolean;
    manageBackups?: boolean;
    viewSeo?: boolean;
    manageSeo?: boolean;
    auditSeo?: boolean;
    manageSeoIntegrations?: boolean;
    manageSeoTranslations?: boolean;
    manageSeoRedirects?: boolean;
    viewAi?: boolean;
    manageAi?: boolean;
    manageAiCredentials?: boolean;
    manageAiAgents?: boolean;
    manageAiPrompts?: boolean;
    reviewAi?: boolean;
    publishAi?: boolean;
    moderateAi?: boolean;
    emergencyStopAi?: boolean;
};

export type UserRestrictions = {
    post: boolean;
    comment: boolean;
    community: boolean;
    message: boolean;
    advertise: boolean;
};

export type User = {
    id: number;
    name: string;
    username: string;
    email: string;
    avatar?: string;
    is_verified?: boolean;
    is_premium?: boolean;
    premium_until?: string | null;
    role: UserRole;
    role_label: string;
    rbac_role?: string;
    hierarchy_level?: number;
    status: UserStatus;
    status_label: string;
    timezone: string;
    preferred_locale?: string | null;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string | null;
    updated_at: string | null;
    restrictions?: UserRestrictions;
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
