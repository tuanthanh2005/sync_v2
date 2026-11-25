export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type SocialAuthor = {
    id: number;
    name: string;
    username: string;
    headline: string | null;
    avatar: string | null;
    is_verified: boolean;
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
    status: 'pending' | 'resolved' | 'dismissed';
    status_label: string;
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

export type SidebarHighlights = {
    trendingHashtags: HashtagItem[];
    featuredCommunities: CommunitySummary[];
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
    provider: string;
    provider_reference: string | null;
    amount: number;
    currency: string;
    status: 'pending' | 'paid' | 'failed' | 'cancelled';
    status_label: string;
    paid_at: string | null;
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

export type PublicFeedPost = {
    id: number;
    category: string;
    category_label: string;
    title: string | null;
    content: string;
    link_url: string | null;
    link_meta: FeedLinkMeta | null;
    visibility: 'public' | 'community' | 'private';
    is_pinned: boolean;
    moderation_status: string;
    reactions_count: number;
    comments_count: number;
    shares_count: number;
    published_at: string | null;
    viewer_reaction: 'like' | 'love' | 'insightful' | null;
    is_bookmarked: boolean;
    can_edit: boolean;
    can_delete: boolean;
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
    created_at: string | null;
    can_delete: boolean;
    author: SocialAuthor | null;
};

export type CommentItem = {
    id: number;
    content: string;
    created_at: string | null;
    can_delete: boolean;
    author: SocialAuthor | null;
    replies: CommentReplyItem[];
};

export type CategoryOption = {
    value: string;
    label: string;
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
    open_in_new_tab: boolean;
};

export type FooterGroupSection = {
    key: string;
    label: string;
    items: FooterLinkItem[];
};

export type FooterSharedData = {
    year: number;
    links: FooterLinkItem[];
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
    title: string;
    summary: string | null;
    content: string | null;
    status: 'draft' | 'published';
    status_label: string;
    is_visible: boolean;
    published_at: string | null;
    updated_at: string | null;
};

export type AdminPolicyRecord = {
    id: number;
    slug: string;
    title_vi: string;
    title_en: string;
    summary_vi: string | null;
    summary_en: string | null;
    content_vi: string | null;
    content_en: string | null;
    status: 'draft' | 'published';
    status_label: string;
    is_visible: boolean;
    footer_group: 'platform' | 'legal' | 'support';
    sort_order: number;
    published_at: string | null;
    updated_at: string | null;
};

export type AdminFooterLinkRecord = {
    id: number;
    policy_page_id: number | null;
    policy_slug: string | null;
    policy_status: 'draft' | 'published' | null;
    label_vi: string;
    label_en: string;
    url: string | null;
    footer_group: 'platform' | 'legal' | 'support';
    sort_order: number;
    is_visible: boolean;
    open_in_new_tab: boolean;
};
