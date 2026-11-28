export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error' | 'loading';
    message: string;
    description?: string;
    duration?: number;
    persistent?: boolean;
    actionLabel?: string;
    actionUrl?: string;
};

export type PublicLanguageItem = {
    code: string;
    native_name: string;
    english_name: string;
    flag_code: string;
    is_rtl: boolean;
};

export type MemberBadgeItem = {
    type: string;
    label_key: string;
    icon: string;
    color: string;
    member_number: number | null;
};

export type AdminPendingCounts = {
    reports: number;
    ads: number;
    banners?: number;
    pending_orders?: number;
    appeals: number;
    verifications: number;
    ai_drafts?: number;
    fraud_reviews?: number;
};

export type BrandingPublicConfig = {
    version: string;
    logoLightUrl: string | null;
    logoDarkUrl: string | null;
    faviconUrl: string | null;
    ogImageUrl: string | null;
    defaults: {
        iconLight: string;
        iconDark: string;
        logoLight: string;
        logoDark: string;
        faviconSvg: string;
        faviconIco: string;
        favicon16: string;
        favicon32: string;
        appleTouchIcon: string;
        pwa192: string;
        ogImage: string;
    };
};

export type SystemSettingsPublic = {
    siteName: string;
    siteSlogan: string;
    siteDescription: string;
    contactEmail: string;
    defaultTheme: string;
    maintenanceEnabled: boolean;
    maintenanceMessage: string;
    branding?: BrandingPublicConfig;
    features: Record<string, boolean>;
    limits: {
        postMaxLength: number;
        postMaxImages: number;
        uploadMaxMb: number;
        uploadMaxBytes?: number;
        uploadMaxPostRequestBytes?: number;
        uploadLimitsByContext?: Record<string, number>;
        commentMaxLength: number;
    };
    views?: {
        minDurationMs: number;
        minVisibleRatio: number;
        dedupWindowSeconds: number;
        trackGuests: boolean;
        trackAi: boolean;
    };
    auth?: {
        mandatoryEmailVerification: boolean;
        emergencyRegistrationPause: boolean;
        turnstileEnabled: boolean;
        turnstileSiteKey: string;
        otpExpireMinutes: number;
        otpResendCooldown: number;
    };
};

export type PostViewAnalytics = {
    total_views: number;
    human_views: number;
    guest_views: number;
    ai_views: number;
    ai_content_accesses: number;
    unique_human_viewers: number;
    organic_views: number;
    promoted_human_views: number;
    invalid_views: number;
    legacy_views: number;
};

export type UnifiedBadgeType =
    | 'official_account'
    | 'official_staff'
    | 'moderator'
    | 'premium_member'
    | 'early_member'
    | 'ai_account'
    | 'founding_member'
    | 'referral_ambassador'
    | 'community_builder';

export type UnifiedBadgeItem = {
    type: UnifiedBadgeType | string;
    label_key: string;
    description_key?: string;
    icon?: string;
    color?: string;
    priority?: number;
    is_authority?: boolean;
    member_number?: number | null;
    ai_disclosure?: string | null;
    granted_at?: string | null;
};

export type SocialAuthor = {
    id: number;
    name: string;
    username: string;
    headline: string | null;
    avatar: string | null;
    is_verified: boolean;
    is_premium?: boolean;
    is_ai_agent?: boolean;
    ai_disclosure?: string | null;
    compact_badges?: UnifiedBadgeItem[];
    badges?: UnifiedBadgeItem[];
};

export type FeedMediaItem = {
    id: number;
    url: string;
    mime_type: string;
    width: number | null;
    height: number | null;
    alt_text: string | null;
};

export type FeedPollOption = {
    id: number;
    label: string;
    votes_count: number;
    percentage: number;
};

export type FeedPoll = {
    id: number;
    question: string;
    ends_at: string | null;
    is_expired: boolean;
    allows_multiple: boolean;
    total_votes: number;
    voted_option_ids: number[];
    options: FeedPollOption[];
};

export type FeedLinkMeta = {
    url: string;
    domain: string;
    title: string | null;
    description: string | null;
    image: string | null;
};

export type HashtagItem = {
    id: number;
    name: string;
    slug: string;
    posts_count: number;
};

export type CommunitySummary = {
    id: number;
    name: string;
    slug: string;
    category: string;
    category_label: string;
    description: string | null;
    avatar: string | null;
    cover: string | null;
    is_verified: boolean;
    members_count: number;
    posts_count: number;
    is_joined: boolean;
};

export type CommunityMemberItem = {
    id: number;
    role: 'owner' | 'moderator' | 'member';
    role_label: string;
    points: number;
    joined_at: string;
    user: SocialAuthor | null;
};

export type CommunityDetail = CommunitySummary & {
    rules: string[];
    visibility: 'public' | 'private';
    owner_id: number;
    is_owner: boolean;
    can_moderate: boolean;
    member_role: 'owner' | 'moderator' | 'member' | null;
};

export type NotificationItem = {
    id: string;
    event: string;
    title: string;
    url: string;
    actor_name: string | null;
    actor_username: string | null;
    read_at: string | null;
    created_at: string | null;
};

export type ConversationUser = {
    id: number;
    name: string;
    username: string;
    avatar: string | null;
    is_premium?: boolean;
    is_ai_agent?: boolean;
    compact_badges?: UnifiedBadgeItem[];
    badges?: UnifiedBadgeItem[];
};

export type ConversationSummary = {
    id: number;
    other_user: ConversationUser | null;
    last_message: string | null;
    last_message_at: string | null;
    unread_count: number;
};

export type MessageItem = {
    id: number;
    sender_id: number;
    is_mine: boolean;
    body: string;
    created_at: string;
};

export type ActiveConversation = {
    id: number;
    other_user: ConversationUser | null;
    messages: MessageItem[];
};

export type ReportQueueItem = {
    id: number;
    target_type: string;
    target_id: number;
    target_excerpt: string;
    reason: string;
    reason_label: string;
    details: string | null;
    status:
        | 'pending'
        | 'under_review'
        | 'resolved'
        | 'rejected'
        | 'dismissed'
        | 'appealed';
    status_label: string;
    priority?: string;
    assigned_to?: number | null;
    internal_note?: string | null;
    reporter_username: string | null;
    reviewer_username: string | null;
    resolution_note: string | null;
    created_at: string | null;
};

export type ModerationLogItem = {
    id: number;
    action: string;
    actor_username: string | null;
    target_type: string;
    target_id: number;
    reason: string | null;
    created_at: string;
};

export type ActiveBannerDisplay = {
    id: number;
    placement_code: string;
    aspect_ratio: string;
    platform: string;
    name: string;
    business_name: string | null;
    headline: string | null;
    cta_label: string;
    alt_text: string;
    desktop_image_url: string | null;
    mobile_image_url: string | null;
    logo_url: string | null;
    click_url: string;
};

export type AdSensePageConfig = {
    enabled: boolean;
    publisher_id: string;
    slots: Record<string, string>;
    consent_required: boolean;
};

export type PremiumPlanItem = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    duration_days: number;
    price_usd_cents: number;
    price_vnd: number;
    promo_price_usd_cents: number | null;
    promo_price_vnd: number | null;
    effective_usd_cents: number;
    effective_vnd: number;
    benefits: string[];
    ad_reduction_mode: 'none' | 'reduced' | 'ad_free';
    sort_order: number;
    is_active: boolean;
    is_featured: boolean;
};

export type BannerPlacementItem = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    platform: 'desktop' | 'mobile' | 'both';
    recommended_width: number;
    recommended_height: number;
    aspect_ratio: string;
    delivery_mode: 'exclusive' | 'rotation';
    delivery_mode_label: string;
    max_active_banners: number;
    price_7d_vnd: number;
    price_15d_vnd: number;
    price_30d_vnd: number;
    price_90d_vnd: number;
    price_7d_usd_cents: number;
    price_15d_usd_cents: number;
    price_30d_usd_cents: number;
    price_90d_usd_cents: number;
    is_active: boolean;
    sort_order: number;
    active_now?: number;
    is_available_now?: boolean;
};

export type BannerCampaignItem = {
    id: number;
    user_id: number;
    owner_name?: string | null;
    owner_username?: string | null;
    banner_placement_id: number;
    placement_code?: string | null;
    placement_name?: string | null;
    payment_id: number | null;
    name: string;
    business_name: string | null;
    headline: string | null;
    cta_label: string | null;
    target_url: string;
    alt_text: string;
    desktop_image_url: string | null;
    mobile_image_url: string | null;
    logo_url: string | null;
    duration_days: number;
    starts_at: string;
    ends_at: string;
    currency: string;
    price_amount: number;
    payment_status: string;
    status: string;
    status_label: string;
    impressions_count: number;
    clicks_count: number;
    ctr_percent: number;
    rejection_reason: string | null;
    emergency_paused_at: string | null;
    created_at: string | null;
};

export type BoostPackageItem = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    duration_hours: number;
    price_usd_cents: number;
    price_vnd: number;
    is_featured_slot: boolean;
    priority_weight: number;
    benefits: string[];
    is_active: boolean;
    sort_order: number;
};

export type PaymentMethodPublic = {
    code: string;
    name: string;
    category: 'fiat' | 'crypto' | 'sandbox';
    supported_currencies: string[];
    fee_percent_bps: number;
    fee_fixed_vnd: number;
    fee_fixed_usd_cents: number;
    is_live_ready: boolean;
    instructions: Record<string, string | number | boolean | null>;
};

export type PlatformCapabilities = {
    platform: 'web' | 'pwa' | 'android_play' | 'ios_appstore';
    allow_external_web_checkout: boolean;
    allow_premium_purchase: boolean;
    allow_banner_display: boolean;
    allow_boost_purchase: boolean;
    require_native_iap_for_digital: boolean;
    allowed_payment_methods: string[];
    policy_note: string | null;
};

export type MonetizationQuickShared = {
    boostPackages: BoostPackageItem[];
    paymentMethods: PaymentMethodPublic[];
    maxPendingOrders: number;
};

export type SidebarNotableAccount = {
    id: number;
    name: string;
    username: string;
    headline: string | null;
    avatar: string | null;
    is_verified: boolean;
    is_premium: boolean;
    is_ai_agent: boolean;
    ai_disclosure?: string | null;
    compact_badges?: UnifiedBadgeItem[];
    badges?: UnifiedBadgeItem[];
    followers_count: number;
};

export type SidebarHighlights = {
    trendingHashtags: HashtagItem[];
    featuredCommunities: CommunitySummary[];
    notableAccounts?: SidebarNotableAccount[];
    banner?: ActiveBannerDisplay | null;
};

export type SponsoredAdMeta = {
    id: number;
    cta_label: string;
    click_url: string;
    has_external_url: boolean;
};

export type WalletAccountSummary = {
    currency: string;
    balance: number;
    promo_balance?: number;
    held_balance: number;
    total_deposited: number;
    total_spent: number;
};

export type WalletLedgerEntry = {
    id: number;
    type: 'deposit' | 'hold' | 'release' | 'spend' | 'refund';
    type_label: string;
    amount: number;
    balance_after: number;
    held_after: number;
    description: string;
    ad_campaign_id: number | null;
    created_at: string;
};

export type PaymentTransactionItem = {
    id: number;
    order_code?: string;
    service_type?: 'topup' | 'premium' | 'banner' | 'boost';
    service_type_label?: string;
    provider: string;
    provider_reference: string | null;
    amount: number;
    fee_amount?: number;
    total_amount?: number;
    refunded_amount?: number;
    currency: string;
    exchange_rate_snapshot?: number | null;
    status:
        | 'pending'
        | 'processing'
        | 'paid'
        | 'failed'
        | 'cancelled'
        | 'expired'
        | 'refunded';
    status_label: string;
    platform?: string;
    metadata?: Record<string, unknown>;
    can_cancel?: boolean;
    paid_at: string | null;
    expires_at?: string | null;
    cancelled_at?: string | null;
    refunded_at?: string | null;
    refund_reason?: string | null;
    created_at: string | null;
};

export type AdEligiblePostItem = {
    id: number;
    title: string | null;
    content: string;
    category: string;
    category_label: string;
    reactions_count: number;
    comments_count: number;
    shares_count: number;
    views_count?: number;
    organic_views_count?: number;
    promoted_views_count?: number;
    unique_viewers_count?: number;
    view_analytics?: PostViewAnalytics | null;
    published_at: string | null;
    image: string | null;
};

export type AdCampaignItem = {
    id: number;
    post_id: number;
    name: string;
    objective: 'reach' | 'engagement' | 'traffic';
    objective_label: string;
    target_topics: string[];
    target_url: string | null;
    cta_label: string | null;
    schedule_mode: 'immediate' | 'scheduled';
    starts_at: string | null;
    ends_at: string | null;
    budget_type: 'daily' | 'total';
    daily_budget: number | null;
    duration_days: number;
    total_budget: number;
    held_amount: number;
    spent_amount: number;
    impressions_count: number;
    clicks_count: number;
    ctr_percent: number;
    status:
        | 'pending_review'
        | 'active'
        | 'paused'
        | 'completed'
        | 'rejected'
        | 'cancelled';
    status_label: string;
    rejection_reason: string | null;
    created_at: string | null;
    owner_name?: string | null;
    owner_username?: string | null;
    reviewer_username?: string | null;
    post: {
        id: number;
        title: string | null;
        excerpt: string;
        image: string | null;
    } | null;
};

export type AdDailyStatItem = {
    date: string;
    impressions: number;
    clicks: number;
    spent: number;
};

export type AdStatsSummary = {
    active_campaigns: number;
    total_impressions: number;
    total_clicks: number;
    total_spent: number;
    ctr_percent: number;
};

export type AdObjectiveOption = {
    value: 'reach' | 'engagement' | 'traffic';
    label: string;
    description: string;
};

export type AdPricingConfig = {
    min_daily_budget_vnd: number;
    max_daily_budget_vnd: number;
    min_total_budget_vnd: number;
    max_total_budget_vnd: number;
    impression_cost_vnd: number;
    click_cost_vnd: number;
};

export type SocialDirectoryUserItem = {
    id: number;
    name: string;
    username: string;
    role: string;
    role_label: string;
    headline: string | null;
    bio: string | null;
    location: string | null;
    avatar: string | null;
    is_verified: boolean;
    is_premium: boolean;
    is_ai_agent: boolean;
    ai_disclosure: string | null;
    compact_badges?: UnifiedBadgeItem[];
    badges: UnifiedBadgeItem[];
    is_self: boolean;
    is_following: boolean;
    can_follow: boolean;
    reaction_type?: 'like' | 'love' | 'insightful';
    reacted_at?: string | null;
    followed_at?: string | null;
};

export type PublicFeedPost = {
    id: number;
    slug?: string | null;
    language_code?: string | null;
    category: string;
    category_label: string;
    title: string | null;
    content: string;
    link_url: string | null;
    link_meta: FeedLinkMeta | null;
    visibility: 'public' | 'community' | 'private';
    is_pinned: boolean;
    moderation_status: string;
    lifecycle_status?: string;
    moderation_reason?: string | null;
    spam_risk_score?: number | null;
    reactions_count: number;
    reaction_summary?: Partial<Record<'like' | 'love' | 'insightful', number>>;
    comments_count: number;
    bookmarks_count?: number;
    shares_count: number;
    views_count?: number;
    organic_views_count?: number;
    promoted_views_count?: number;
    unique_viewers_count?: number;
    view_analytics?: PostViewAnalytics | null;
    published_at: string | null;
    edited_at?: string | null;
    edit_count?: number;
    content_origin?: string;
    is_ai_assisted?: boolean;
    viewer_reaction: 'like' | 'love' | 'insightful' | null;
    is_bookmarked: boolean;
    can_edit: boolean;
    can_delete: boolean;
    can_boost?: boolean;
    boost_summary?: {
        id: number;
        status: string;
        status_label: string;
        impressions_count: number;
        clicks_count: number;
        ends_at: string | null;
        is_featured_slot: boolean;
    } | null;
    is_sponsored?: boolean;
    ad_campaign?: SponsoredAdMeta | null;
    author: SocialAuthor | null;
    community?: {
        id: number;
        name: string;
        slug: string;
    } | null;
    hashtags?: Array<{
        id: number;
        name: string;
        slug: string;
    }>;
    media: FeedMediaItem[];
    poll: FeedPoll | null;
};

export type CommentReplyItem = {
    id: number;
    parent_id: number | null;
    content: string;
    is_ai_generated?: boolean;
    created_at: string | null;
    can_delete: boolean;
    author: SocialAuthor | null;
};

export type CommentItem = {
    id: number;
    content: string;
    is_ai_generated?: boolean;
    created_at: string | null;
    can_delete: boolean;
    author: SocialAuthor | null;
    replies: CommentReplyItem[];
};

export type CategoryOption = {
    value: string;
    label: string;
    icon?: string;
};

export type ProfileViewUser = {
    id: number;
    name: string;
    username: string;
    role_label: string;
    headline: string | null;
    bio: string | null;
    location: string | null;
    website_url: string | null;
    avatar: string | null;
    cover: string | null;
    is_verified: boolean;
    is_premium?: boolean;
    account_type?: string;
    is_ai_agent?: boolean;
    ai_disclosure?: string | null;
    compact_badges?: UnifiedBadgeItem[];
    profile_badges?: UnifiedBadgeItem[];
    badges?: UnifiedBadgeItem[];
    followers_count: number;
    following_count: number;
    posts_count: number;
    joined_at: string | null;
    is_owner: boolean;
    is_following: boolean;
    is_blocked: boolean;
};

export type SearchUserResult = {
    id: number;
    name: string;
    username: string;
    headline: string | null;
    avatar: string | null;
    is_verified: boolean;
    is_premium?: boolean;
    account_type?: string;
    is_ai_agent?: boolean;
    ai_disclosure?: string | null;
    compact_badges?: UnifiedBadgeItem[];
    profile_badges?: UnifiedBadgeItem[];
    badges?: UnifiedBadgeItem[];
    followers_count: number;
};

export type AdminPlatformStatus = {
    app_env: string;
    locale: string;
    hashing_driver: string;
    session_driver: string;
    cache_store: string;
    queue_connection: string;
    media_disk: string;
    ssr_enabled: boolean;
    admin_two_factor_enforced: boolean;
    published_policies: number;
    draft_policies: number;
};

export type AccountChecklist = {
    emailVerified: boolean;
    twoFactorEnabled: boolean;
    usernameConfigured: boolean;
    timezoneConfigured: boolean;
};

export type AdminUserRecord = {
    id: number;
    name: string;
    username: string;
    role: 'user' | 'moderator' | 'admin';
    role_label: string;
    status: 'active' | 'suspended' | 'banned';
    status_label: string;
    email_verified: boolean;
    created_at: string | null;
};

export type AdminAuditRecord = {
    id: number;
    action: string;
    actor_username: string | null;
    ip_address: string | null;
    created_at: string;
};

export type AdminMetrics = {
    total_users: number;
    active_users: number;
    verified_users: number;
    staff_users: number;
    suspended_users: number;
};

export type FooterLinkItem = {
    id: number;
    label: string;
    href: string;
    group: string;
    group_label: string;
    placement?: 'main' | 'more';
    open_in_new_tab: boolean;
};

export type FooterGroupSection = {
    key: string;
    label: string;
    items: FooterLinkItem[];
};

export type FooterSharedData = {
    year: number;
    supportEmail?: string | null;
    contactInfo?: string | null;
    copyrightText?: string | null;
    links: FooterLinkItem[];
    mainLinks?: FooterLinkItem[];
    moreLinks?: FooterLinkItem[];
    groups: FooterGroupSection[];
};

export type BrowserSessionItem = {
    is_current_device: boolean;
    ip_address: string;
    user_agent: string;
    last_active_at: string;
};

export type LoginHistoryItem = {
    id: number;
    event_type: string;
    ip_address: string;
    user_agent: string;
    created_at: string;
};

export type PolicyPublicView = {
    id: number;
    slug: string;
    page_type?: string;
    title: string;
    summary: string | null;
    content: string | null;
    status: 'draft' | 'published' | 'archived';
    status_label: string;
    is_visible: boolean;
    og_image?: string | null;
    effective_at?: string | null;
    published_at: string | null;
    updated_at: string | null;
    active_locale?: string;
    available_locales?: Array<{
        code: string;
        native_name: string;
        href: string;
    }>;
};

export type AdminPolicyTranslationRecord = {
    locale: string;
    title: string;
    summary: string | null;
    content: string | null;
    seo_title: string | null;
    meta_description: string | null;
    status: 'draft' | 'published';
    published_at: string | null;
    updated_at: string | null;
};

export type AdminPolicyRevisionRecord = {
    id: number;
    locale: string;
    version_number: number;
    title: string;
    change_note: string | null;
    author_name: string | null;
    created_at: string | null;
};

export type FooterGroupValue =
    | 'company'
    | 'platform'
    | 'support'
    | 'legal'
    | 'business';

export type AdminPolicyRecord = {
    id: number;
    slug: string;
    page_type: 'company' | 'support' | 'legal' | 'business' | 'general';
    title_vi: string;
    title_en: string;
    summary_vi: string | null;
    summary_en: string | null;
    content_vi: string | null;
    content_en: string | null;
    seo_title: string | null;
    meta_description: string | null;
    canonical_url: string | null;
    og_image: string | null;
    index_eligible: boolean;
    status: 'draft' | 'published' | 'archived';
    status_label: string;
    is_visible: boolean;
    footer_group: FooterGroupValue;
    placement: 'main' | 'more';
    sort_order: number;
    published_at: string | null;
    effective_at: string | null;
    archived_at: string | null;
    updated_at: string | null;
    translations: Record<string, AdminPolicyTranslationRecord>;
    revisions: AdminPolicyRevisionRecord[];
};

export type AdminFooterLinkRecord = {
    id: number;
    policy_page_id: number | null;
    policy_slug: string | null;
    policy_status: 'draft' | 'published' | 'archived' | null;
    label_vi: string;
    label_en: string;
    translations?: Record<string, string>;
    url: string | null;
    footer_group: FooterGroupValue;
    placement: 'main' | 'more';
    sort_order: number;
    is_visible: boolean;
    open_in_new_tab: boolean;
};
