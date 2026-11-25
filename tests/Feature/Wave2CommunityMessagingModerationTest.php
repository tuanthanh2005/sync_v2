<?php

use App\Enums\ModerationStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Hashtag;
use App\Models\Message;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('users can create communities, join, post with hashtags, pin posts, and enforce private community anti-idor', function (): void {
    $owner = User::factory()->create(['username' => 'comm_owner']);
    $member = User::factory()->create(['username' => 'comm_member']);
    $outsider = User::factory()->create(['username' => 'comm_outsider']);

    $this->actingAs($owner)
        ->post(route('communities.store'), [
            'name' => 'Laravel Engineering Club',
            'category' => 'technology',
            'description' => 'Deep architectural discussions.',
            'visibility' => 'public',
            'rules' => ['Be constructive', 'Share benchmarks'],
        ])
        ->assertRedirect();

    $community = Community::query()->where('name', 'Laravel Engineering Club')->firstOrFail();

    expect($community->owner_id)->toBe($owner->id)
        ->and($community->members_count)->toBe(1);

    $this->actingAs($member)
        ->post(route('communities.membership', $community->slug))
        ->assertRedirect();

    expect($community->fresh()->members_count)->toBe(2);

    $this->actingAs($member)
        ->post(route('posts.store'), [
            'title' => 'Queue tuning tips',
            'content' => 'Always benchmark #Laravel and #MySQL workers.',
            'category' => 'technology',
            'community_id' => $community->id,
        ])
        ->assertRedirect();

    $post = Post::query()->where('title', 'Queue tuning tips')->firstOrFail();

    expect($post->community_id)->toBe($community->id)
        ->and(Hashtag::query()->where('slug', 'laravel')->exists())->toBeTrue()
        ->and(Hashtag::query()->where('slug', 'mysql')->exists())->toBeTrue();

    // Non-moderator member cannot pin posts
    $this->actingAs($member)
        ->post(route('communities.posts.pin', [$community->slug, $post->id]))
        ->assertForbidden();

    // Owner can pin posts
    $this->actingAs($owner)
        ->post(route('communities.posts.pin', [$community->slug, $post->id]))
        ->assertRedirect();

    expect($post->fresh()->is_pinned)->toBeTrue();

    // Community view renders tabs, leaderboard, and trending hashtags
    $this->actingAs($member)
        ->get(route('communities.show', ['community' => $community->slug, 'tab' => 'posts']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('communities/Show')
            ->where('community.id', $community->id)
            ->has('posts', 1)
            ->has('leaderboard')
            ->has('trending_hashtags'),
        );

    // Search filters by hashtag and matches community
    $this->get(route('search', ['hashtag' => 'laravel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Search')
            ->where('hashtag', 'laravel')
            ->has('posts', 1),
        );

    // Private community anti-IDOR check
    $privateCommunity = Community::factory()->for($owner, 'owner')->create([
        'name' => 'Private Founders Circle',
        'visibility' => 'private',
    ]);

    $this->actingAs($outsider)
        ->get(route('communities.show', $privateCommunity->slug))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->post(route('posts.store'), [
            'content' => 'Unauthorized community injection attempt',
            'community_id' => $privateCommunity->id,
        ])
        ->assertSessionHasErrors('community_id');
});

test('interactions create database notifications while respecting user blocks', function (): void {
    $author = User::factory()->create(['username' => 'notif_author']);
    $commenter = User::factory()->create(['username' => 'notif_commenter']);
    $blockedUser = User::factory()->create(['username' => 'notif_blocked']);

    $post = Post::factory()->for($author, 'author')->create(['visibility' => 'public']);

    UserBlock::query()->create([
        'blocker_id' => $author->id,
        'blocked_id' => $blockedUser->id,
    ]);

    // Blocked user cannot view or comment on post and triggers no notification
    $this->actingAs($blockedUser)
        ->post(route('comments.store', $post->id), ['content' => 'Blocked comment'])
        ->assertForbidden();

    expect($author->notifications()->count())->toBe(0);

    // Normal user commenting triggers database notification
    $this->actingAs($commenter)
        ->post(route('comments.store', $post->id), ['content' => 'Helpful post!'])
        ->assertRedirect();

    expect($author->unreadNotifications()->count())->toBe(1);

    $this->actingAs($author)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Notifications')
            ->has('notifications', 1),
        );

    $this->actingAs($author)
        ->post(route('notifications.read-all'))
        ->assertRedirect();

    expect($author->fresh()->unreadNotifications()->count())->toBe(0);
});

test('direct messages enforce participant anti-idor, read tracking, and blocks', function (): void {
    $alice = User::factory()->create(['username' => 'msg_alice']);
    $bob = User::factory()->create(['username' => 'msg_bob']);
    $eve = User::factory()->create(['username' => 'msg_eve']);

    $this->actingAs($alice)
        ->post(route('messages.start', $bob->username))
        ->assertRedirect();

    $conversation = Conversation::query()->firstOrFail();

    $this->actingAs($alice)
        ->post(route('messages.send', $conversation->id), [
            'body' => 'Hello Bob, how is the architecture review?',
        ])
        ->assertRedirect();

    expect(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(1);

    // Unrelated user cannot view or send into the conversation (IDOR protection)
    $this->actingAs($eve)
        ->get(route('messages.show', $conversation->id))
        ->assertForbidden();

    $this->actingAs($eve)
        ->post(route('messages.send', $conversation->id), [
            'body' => 'Eavesdropping injection',
        ])
        ->assertForbidden();

    // Recipient opens conversation and marks message read
    $this->actingAs($bob)
        ->get(route('messages.show', $conversation->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Messages')
            ->where('activeConversation.id', $conversation->id)
            ->has('activeConversation.messages', 1),
        );

    expect(Message::query()->where('conversation_id', $conversation->id)->whereNull('read_at')->count())->toBe(0);

    // UserBlock prevents sending new messages
    UserBlock::query()->create([
        'blocker_id' => $bob->id,
        'blocked_id' => $alice->id,
    ]);

    $this->actingAs($alice)
        ->post(route('messages.send', $conversation->id), [
            'body' => 'Should be blocked',
        ])
        ->assertSessionHasErrors('body');
});

test('moderation queue enforces authorization, hides reported content, and blocks suspending admins', function (): void {
    $member = User::factory()->create(['username' => 'report_member', 'role' => UserRole::User]);
    $offender = User::factory()->create(['username' => 'report_offender', 'role' => UserRole::User]);
    $moderator = User::factory()->moderator()->withTwoFactor()->create(['username' => 'mod_reviewer']);
    $adminWithout2fa = User::factory()->admin()->create(['username' => 'admin_no_2fa']);
    $adminTarget = User::factory()->admin()->withTwoFactor()->create(['username' => 'admin_target']);

    $badPost = Post::factory()->for($offender, 'author')->create([
        'title' => 'Phishing Scam Link',
        'visibility' => 'public',
    ]);

    // Member reports post
    $this->actingAs($member)
        ->post(route('reports.store'), [
            'target_type' => 'post',
            'target_id' => $badPost->id,
            'reason' => ReportReason::Spam->value,
            'details' => 'Looks like phishing.',
        ])
        ->assertRedirect();

    $report = Report::query()->where('reportable_id', $badPost->id)->firstOrFail();

    // Member cannot access moderation queue or resolve
    $this->actingAs($member)->get(route('admin.moderation.index'))->assertForbidden();
    $this->actingAs($member)->post(route('admin.moderation.resolve', $report->id), ['decision' => 'dismiss'])->assertForbidden();

    // Admin without 2FA is redirected by EnsureAdminHasTwoFactor
    $this->actingAs($adminWithout2fa)->get(route('admin.moderation.index'))->assertRedirect(route('security.edit'));

    // Moderator hides content
    $this->actingAs($moderator)
        ->post(route('admin.moderation.resolve', $report->id), [
            'decision' => 'hide_content',
            'note' => 'Removed phishing post.',
        ])
        ->assertRedirect();

    expect($badPost->fresh()->moderation_status)->toBe(ModerationStatus::Hidden)
        ->and($report->fresh()->status)->toBe(ReportStatus::Resolved);

    // Cannot suspend an Admin via moderation queue
    $this->actingAs($member)
        ->post(route('reports.store'), [
            'target_type' => 'user',
            'target_id' => $adminTarget->id,
            'reason' => ReportReason::Other->value,
        ])
        ->assertRedirect();

    $adminReport = Report::query()
        ->where('reportable_type', User::class)
        ->where('reportable_id', $adminTarget->id)
        ->firstOrFail();

    $this->actingAs($moderator)
        ->post(route('admin.moderation.resolve', $adminReport->id), [
            'decision' => 'suspend_user',
        ])
        ->assertSessionHasErrors('decision');

    expect($adminTarget->fresh()->status)->toBe(UserStatus::Active);

    // Can suspend regular user
    $this->actingAs($member)
        ->post(route('reports.store'), [
            'target_type' => 'user',
            'target_id' => $offender->id,
            'reason' => ReportReason::Harassment->value,
        ])
        ->assertRedirect();

    $userReport = Report::query()
        ->where('reportable_type', User::class)
        ->where('reportable_id', $offender->id)
        ->firstOrFail();

    $this->actingAs($moderator)
        ->post(route('admin.moderation.resolve', $userReport->id), [
            'decision' => 'suspend_user',
            'note' => 'Repeated violations.',
        ])
        ->assertRedirect();

    expect($offender->fresh()->status)->toBe(UserStatus::Suspended);
});
