import { reactive } from 'vue';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';

type FollowToggleResponse = {
    following: boolean;
    followers_count: number;
    viewer_following_count: number;
    target_user_id: number;
    message?: string;
};

type ProfileCountOverride = {
    followers_count?: number;
    following_count?: number;
};

// Shared reactive follow state so modal interactions stay in sync with profile headers & other open lists.
const followOverrides = reactive<Record<number, boolean>>({});
const pendingUsers = reactive<Record<number, boolean>>({});
const profileCountOverrides = reactive<Record<number, ProfileCountOverride>>(
    {},
);

function readXsrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

export function isUserFollowed(userId: number, fallback: boolean): boolean {
    if (userId in followOverrides) {
        return followOverrides[userId];
    }

    return fallback;
}

export function isUserFollowPending(userId: number): boolean {
    return Boolean(pendingUsers[userId]);
}

export function getLiveFollowersCount(
    userId: number,
    fallback: number,
): number {
    const override = profileCountOverrides[userId]?.followers_count;

    return override !== undefined ? override : fallback;
}

export function getLiveFollowingCount(
    userId: number,
    fallback: number,
): number {
    const override = profileCountOverrides[userId]?.following_count;

    return override !== undefined ? override : fallback;
}

export function syncProfileCounts(
    userId: number,
    counts: { followers?: number; following?: number },
): void {
    const existing = profileCountOverrides[userId] ?? {};
    profileCountOverrides[userId] = {
        followers_count:
            counts.followers !== undefined
                ? counts.followers
                : existing.followers_count,
        following_count:
            counts.following !== undefined
                ? counts.following
                : existing.following_count,
    };
}

export async function toggleUserFollow(params: {
    targetUserId: number;
    targetUsername: string;
    currentlyFollowing: boolean;
    viewerUserId: number | null;
}): Promise<{ ok: boolean; following: boolean; message?: string }> {
    const { targetUserId, targetUsername, currentlyFollowing, viewerUserId } =
        params;

    if (pendingUsers[targetUserId]) {
        return { ok: false, following: currentlyFollowing };
    }

    const previousState = isUserFollowed(targetUserId, currentlyFollowing);
    const optimisticState = !previousState;

    pendingUsers[targetUserId] = true;
    followOverrides[targetUserId] = optimisticState;

    try {
        const xsrf = readXsrfToken();
        const response = await fetch(
            ProfileViewController.toggleFollow.url(targetUsername),
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
                },
                body: JSON.stringify({}),
            },
        );

        if (!response.ok) {
            followOverrides[targetUserId] = previousState;
            let errorMessage: string | undefined;
            try {
                const errPayload = (await response.json()) as {
                    message?: string;
                };
                errorMessage = errPayload.message;
            } catch {
                // Keep generic error fallback
            }

            return {
                ok: false,
                following: previousState,
                message: errorMessage,
            };
        }

        const data = (await response.json()) as FollowToggleResponse;
        followOverrides[targetUserId] = Boolean(data.following);

        if (typeof data.followers_count === 'number') {
            syncProfileCounts(targetUserId, {
                followers: data.followers_count,
            });
        }

        if (
            viewerUserId !== null &&
            typeof data.viewer_following_count === 'number'
        ) {
            syncProfileCounts(viewerUserId, {
                following: data.viewer_following_count,
            });
        }

        return {
            ok: true,
            following: Boolean(data.following),
            message: data.message,
        };
    } catch {
        followOverrides[targetUserId] = previousState;

        return { ok: false, following: previousState };
    } finally {
        pendingUsers[targetUserId] = false;
    }
}
