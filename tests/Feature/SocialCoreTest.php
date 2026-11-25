<?php

use App\Models\Comment;
use App\Models\Follow;
use App\Models\Post;
use App\Models\PostPoll;
use App\Models\PostPollOption;
use App\Models\User;
use App\Services\Social\HostIpResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests can read public posts, paginated feed, and public profiles without logging in', function (): void {
    $author = User::factory()->create(['username' => 'public_author']);
    $publicPost = Post::factory()->for($author, 'author')->create([
        'title' => 'Public Market Guide',
        'content' => 'Helpful public insights for everyone.',
        'visibility' => 'public',
        'category' => 'business',
    ]);
    $privatePost = Post::factory()->for($author, 'author')->create([
        'title' => 'Private Draft Note',
        'visibility' => 'private',
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('posts', 1)
            ->where('posts.0.id', $publicPost->id),
        );

    $this->getJson(route('feed.more', ['tab' => 'latest']))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->get(route('posts.show', $publicPost))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('posts/Show')
            ->where('post.id', $publicPost->id),
        );

    $this->get(route('posts.show', $privatePost))->assertForbidden();

    $this->get(route('users.show', $author->username))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('profile_user.username', 'public_author')
            ->has('posts', 1),
        );
});

test('verified users can publish posts with safely re-encoded images and dangerous uploads are rejected', function (): void {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('posts.store'), [
            'title' => 'Post With Image',
            'content' => 'Uploaded photo test',
            'category' => 'technology',
            'images' => [
                UploadedFile::fake()->image('photo.jpg', 800, 600),
            ],
        ])
        ->assertRedirect();

    $post = Post::query()->where('title', 'Post With Image')->with('media')->firstOrFail();

    expect($post->media)->toHaveCount(1);
    Storage::disk('public')->assertExists($post->media[0]->path);

    $this->actingAs($user)
        ->post(route('posts.store'), [
            'content' => 'Malicious script attempt',
            'images' => [
                UploadedFile::fake()->create('shell.php', 10, 'application/x-php'),
            ],
        ])
        ->assertSessionHasErrors('images.0');
});

test('ssrf protection blocks internal and metadata urls while sanitizing safe external link previews', function (): void {
    $user = User::factory()->create();

    foreach ([
        'http://127.0.0.1/admin',
        'http://169.254.169.254/latest/meta-data/',
        'http://10.0.0.5/internal',
        'http://localhost:8000',
    ] as $unsafeUrl) {
        $this->actingAs($user)
            ->postJson(route('posts.link-preview'), ['url' => $unsafeUrl])
            ->assertUnprocessable();
    }

    app()->instance(HostIpResolver::class, new class extends HostIpResolver
    {
        public function resolve(string $host): array
        {
            return $host === 'internal.evil' ? ['127.0.0.1'] : ['93.184.216.34'];
        }
    });

    $this->actingAs($user)
        ->postJson(route('posts.link-preview'), ['url' => 'https://internal.evil/secret'])
        ->assertUnprocessable();

    Http::fake([
        'https://example.org/*' => Http::response(
            '<html><head><title>Safe &lt;script&gt;alert(1)&lt;/script&gt; Guide</title><meta property="og:description" content="Clean <b>summary</b>"></head></html>',
            200,
        ),
    ]);

    $this->actingAs($user)
        ->postJson(route('posts.link-preview'), ['url' => 'https://example.org/post'])
        ->assertOk()
        ->assertJsonPath('preview.domain', 'example.org')
        ->assertJsonPath('preview.description', 'Clean summary');
});

test('polls enforce option ownership, active status, and accurate tallies', function (): void {
    $author = User::factory()->create();
    $voter = User::factory()->create();

    $this->actingAs($author)
        ->post(route('posts.store'), [
            'content' => 'Favorite backend stack?',
            'poll_question' => 'Pick one option',
            'poll_options' => ['Option A', 'Option B'],
            'poll_duration_hours' => 24,
        ])
        ->assertRedirect();

    $post = Post::query()->with('poll.options')->latest('id')->firstOrFail();
    /** @var PostPoll $poll */
    $poll = $post->poll;

    $otherPost = Post::factory()->for($author, 'author')->create();
    /** @var PostPoll $otherPoll */
    $otherPoll = $otherPost->poll()->create(['question' => 'Other']);
    /** @var PostPollOption $foreignOption */
    $foreignOption = $otherPoll->options()->create(['label' => 'Foreign', 'sort_order' => 0]);

    $this->actingAs($voter)
        ->post(route('posts.vote', $post), ['option_id' => $foreignOption->id])
        ->assertSessionHasErrors('option_id');

    $firstOptionId = $poll->options[0]->id;

    $this->actingAs($voter)
        ->post(route('posts.vote', $post), ['option_id' => $firstOptionId])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($poll->fresh()?->total_votes)->toBe(1);
});

test('reactions, bookmarks, threaded comments, and anti-IDOR policies enforce ownership', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $post = Post::factory()->for($owner, 'author')->create();
    $otherPost = Post::factory()->for($owner, 'author')->create();

    $this->actingAs($otherUser)
        ->post(route('posts.react', $post), ['type' => 'love'])
        ->assertRedirect();

    expect($post->fresh()?->reactions_count)->toBe(1);

    $this->actingAs($otherUser)
        ->post(route('posts.bookmark', $post))
        ->assertRedirect();

    $this->actingAs($otherUser)
        ->post(route('comments.store', $post), ['content' => 'Great insight!'])
        ->assertRedirect();

    $comment = Comment::query()->where('post_id', $post->id)->firstOrFail();

    // Cross-post parent_id injection is rejected
    $this->actingAs($owner)
        ->post(route('comments.store', $otherPost), [
            'content' => 'Cross post parent hack',
            'parent_id' => $comment->id,
        ])
        ->assertSessionHasErrors('parent_id');

    // Non-owner cannot edit or delete owner's post
    $this->actingAs($otherUser)
        ->patch(route('posts.update', $post), ['content' => 'Tampered'])
        ->assertForbidden();

    $this->actingAs($otherUser)
        ->delete(route('posts.destroy', $post))
        ->assertForbidden();
});

test('follow, block, and search respect user safety boundaries', function (): void {
    $alice = User::factory()->create(['name' => 'Alice Wonderland', 'username' => 'alice_global']);
    $bob = User::factory()->create(['name' => 'Bob Builder', 'username' => 'bob_global']);

    $bobsPost = Post::factory()->for($bob, 'author')->create([
        'title' => 'Global Engineering Topic',
        'content' => 'UniqueKeyword123 shared here',
    ]);

    $this->actingAs($alice)
        ->post(route('users.follow', $bob->username))
        ->assertRedirect();

    expect(Follow::query()->where('follower_id', $alice->id)->where('following_id', $bob->id)->exists())->toBeTrue();

    $this->actingAs($alice)
        ->get(route('search', ['q' => 'UniqueKeyword123']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Search')
            ->has('posts', 1),
        );

    $this->actingAs($alice)
        ->post(route('users.block', $bob->username))
        ->assertRedirect();

    expect(Follow::query()->where('follower_id', $alice->id)->where('following_id', $bob->id)->exists())->toBeFalse();

    $this->actingAs($alice)
        ->get(route('search', ['q' => 'UniqueKeyword123']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Search')
            ->has('posts', 0),
        );

    $this->actingAs($alice)
        ->get(route('posts.show', $bobsPost))
        ->assertForbidden();
});
