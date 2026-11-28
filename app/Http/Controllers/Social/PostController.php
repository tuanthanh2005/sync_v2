<?php

namespace App\Http\Controllers\Social;

use App\Enums\ModerationStatus;
use App\Enums\SeoEntityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Social\LinkPreviewRequest;
use App\Http\Requests\Social\PostStoreRequest;
use App\Http\Requests\Social\PostUpdateRequest;
use App\Models\Post;
use App\Models\SeoContentTranslation;
use App\Services\Seo\CanonicalUrlService;
use App\Services\Seo\InternalLinkingService;
use App\Services\Seo\OrganicGrowthService;
use App\Services\Seo\SeoMetadataService;
use App\Services\Social\CommentService;
use App\Services\Social\FeedService;
use App\Services\Social\PostService;
use App\Services\Social\SafeLinkPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function __construct(
        private readonly PostService $postService,
        private readonly FeedService $feedService,
        private readonly CommentService $commentService,
        private readonly SafeLinkPreviewService $linkPreviewService,
        private readonly SeoMetadataService $seoMetadata,
        private readonly InternalLinkingService $internalLinking,
        private readonly CanonicalUrlService $canonicalUrls,
        private readonly OrganicGrowthService $organicGrowth,
    ) {}

    public function show(Request $request, Post $post): Response
    {
        return $this->renderPost($request, $post, null, null);
    }

    public function showLocalized(Request $request, string $locale, int $postId, ?string $slug = null): Response|RedirectResponse
    {
        $post = Post::withTrashed()->find($postId);

        if ($post === null) {
            abort(404);
        }

        if ($post->deleted_at !== null) {
            abort(410);
        }

        $translation = null;
        if ($locale !== ($post->language_code ?: 'en')) {
            $translation = SeoContentTranslation::query()
                ->where('entity_type', SeoEntityType::Post->value)
                ->where('entity_id', $post->id)
                ->where('target_locale', $locale)
                ->publishedApproved()
                ->first();

            // Never serve an unapproved or nonexistent localized URL as indexable duplicate
            if ($translation === null) {
                return redirect()->to($this->canonicalUrls->postPath($post, $post->language_code ?: 'en'), 301);
            }
        }

        $expectedSlug = $translation !== null
            ? $translation->localized_slug
            : $this->canonicalUrls->buildPostSlug($post);

        if ($slug !== null && $expectedSlug !== '' && $slug !== $expectedSlug) {
            return redirect()->to($this->canonicalUrls->postPath($post, $locale, $expectedSlug), 301);
        }

        return $this->renderPost($request, $post, $locale, $translation);
    }

    private function renderPost(Request $request, Post $post, ?string $locale, ?SeoContentTranslation $translation): Response
    {
        Gate::authorize('view', $post);

        $post->load([
            'author:id,name,username,avatar_url,status',
            'author.profile:id,user_id,headline,avatar_disk,avatar_path,is_verified_badge',
            'community:id,name,slug,visibility,status,members_count',
            'hashtags:id,name,slug,posts_count,is_restricted,is_spam',
            'media',
            'poll.options',
        ]);

        $effectiveLocale = $locale ?: ($post->language_code ?: 'en');
        $serializedPost = $this->feedService->serializeOne($post, $request->user());

        if ($translation !== null) {
            if (is_string($translation->translated_title) && $translation->translated_title !== '') {
                $serializedPost['title'] = $translation->translated_title;
            }
            $serializedPost['content'] = $translation->translated_body;
            $serializedPost['translation_locale'] = $translation->target_locale;
            $serializedPost['original_locale'] = $translation->original_language;
        }

        $comments = $this->commentService->forPost($post, $request->user());
        $seo = $this->seoMetadata->forPost($post, $effectiveLocale, $translation);
        $internalLinks = $this->internalLinking->forPost($post, $effectiveLocale);

        $this->organicGrowth->recordEvent('post_view', $request, $request->user(), 'post', $post->id, [
            'locale' => $effectiveLocale,
        ]);

        $heading = (string) ($serializedPost['title'] ?: Str::limit(strip_tags((string) $serializedPost['content']), 90));
        $ssrImages = $post->media->map(static fn ($m): array => [
            'url' => $m->url(),
            'alt' => $heading,
            'width' => $m->width,
            'height' => $m->height,
        ])->values()->all();

        $ssrComments = array_map(static fn (array $c): array => [
            'author' => is_array($c['author'] ?? null) ? (string) ($c['author']['name'] ?? 'Member') : 'Member',
            'body' => strip_tags((string) ($c['content'] ?? '')),
        ], array_slice($comments, 0, 10));

        $ssrLinks = array_merge(
            array_map(static fn (array $rp): array => ['label' => $rp['title'], 'href' => $rp['path']], $internalLinks['related_posts']),
            array_map(static fn (array $rt): array => ['label' => '#'.$rt['name'], 'href' => $rt['path']], $internalLinks['related_topics']),
        );

        $breadcrumbs = [
            ['label' => (string) config('app.name', 'SysNoxis'), 'href' => '/'],
        ];
        if ($internalLinks['related_community'] !== null) {
            $breadcrumbs[] = [
                'label' => $internalLinks['related_community']['name'],
                'href' => $internalLinks['related_community']['path'],
            ];
        }

        return Inertia::render('posts/Show', [
            'post' => $serializedPost,
            'comments' => $comments,
            'seoInternalLinks' => $internalLinks,
            'seoLanguage' => [
                'current' => $effectiveLocale,
                'original' => $post->language_code ?: 'en',
                'is_translation' => $translation !== null,
                'alternates' => $seo['hreflang'] ?? [],
            ],
        ])->withViewData([
            'seo' => $seo,
            'seoSsr' => [
                'type' => 'post',
                'heading' => $heading,
                'author' => $post->author?->name,
                'published_at' => ($post->published_at ?? $post->created_at)?->toIso8601String(),
                'body' => strip_tags((string) $serializedPost['content']),
                'images' => $ssrImages,
                'comments' => $ssrComments,
                'breadcrumbs' => $breadcrumbs,
                'links' => $ssrLinks,
            ],
        ]);
    }

    public function store(PostStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', Post::class);

        $user = $request->user();
        abort_if($user === null, 403);

        /** @var array<int, UploadedFile> $uploadedImages */
        $uploadedImages = $request->file('images', []);

        /** @var array{
         *     title?: string|null,
         *     content: string,
         *     community_id?: int|null,
         *     category?: string|null,
         *     visibility?: string|null,
         *     link_url?: string|null,
         *     image_alts?: list<string|null>|null,
         *     poll_question?: string|null,
         *     poll_options?: list<string>|null,
         *     poll_duration_hours?: int|null
         * } $validated
         */
        $validated = $request->validated();
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if (is_string($idempotencyKey) && trim($idempotencyKey) !== '') {
            $validated['idempotency_key'] = trim($idempotencyKey);
        }
        $validated['ip_address'] = $request->ip();

        $post = $this->postService->create($user, $validated, array_values($uploadedImages));

        if ($post->moderation_status === ModerationStatus::Pending) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => __('Your post has been submitted and is currently under moderation review before appearing publicly.'),
            ]);
        } elseif ($post->moderation_status === ModerationStatus::Rejected) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Your post could not be published because it violates community guidelines.'),
            ]);
        } else {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Your post has been published.'),
            ]);
        }

        return back();
    }

    public function update(PostUpdateRequest $request, Post $post): RedirectResponse
    {
        Gate::authorize('update', $post);

        $user = $request->user();
        abort_if($user === null, 403);

        /** @var array{title?: string|null, content: string, category?: string|null} $validated */
        $validated = $request->validated();
        $validated['ip_address'] = $request->ip();

        $updated = $this->postService->update($user, $post, $validated);

        if ($updated->moderation_status === ModerationStatus::Pending) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => __('Your edited post has been submitted for moderation review.'),
            ]);
        } else {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Post updated.'),
            ]);
        }

        return back();
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        Gate::authorize('delete', $post);

        $user = $request->user();
        abort_if($user === null, 403);

        $this->postService->delete($user, $post);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Post removed.'),
        ]);

        return to_route('home');
    }

    public function previewLink(LinkPreviewRequest $request): JsonResponse
    {
        $url = $request->string('url')->toString();

        if (! $this->linkPreviewService->isSafePublicUrl($url)) {
            return response()->json([
                'message' => __('The provided link URL is blocked or invalid.'),
            ], 422);
        }

        return response()->json([
            'preview' => $this->linkPreviewService->fetch($url),
        ]);
    }
}
