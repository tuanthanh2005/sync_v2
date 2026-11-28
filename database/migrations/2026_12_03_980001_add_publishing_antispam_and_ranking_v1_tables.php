<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('posts')) {
            Schema::table('posts', function (Blueprint $table): void {
                if (! Schema::hasColumn('posts', 'lifecycle_status')) {
                    $table->string('lifecycle_status', 24)->default('published')->after('moderation_status')->index();
                }
                if (! Schema::hasColumn('posts', 'edited_at')) {
                    $table->timestamp('edited_at')->nullable()->after('published_at');
                }
                if (! Schema::hasColumn('posts', 'edit_count')) {
                    $table->unsignedSmallInteger('edit_count')->default(0)->after('edited_at');
                }
                if (! Schema::hasColumn('posts', 'spam_risk_score')) {
                    $table->unsignedSmallInteger('spam_risk_score')->default(0)->after('is_spam');
                }
                if (! Schema::hasColumn('posts', 'link_fingerprint')) {
                    $table->char('link_fingerprint', 64)->nullable()->after('content_fingerprint')->index();
                }
                if (! Schema::hasColumn('posts', 'bookmarks_count')) {
                    $table->unsignedInteger('bookmarks_count')->default(0)->after('shares_count');
                }
                if (! Schema::hasColumn('posts', 'valid_reactions_count')) {
                    $table->unsignedInteger('valid_reactions_count')->default(0)->after('bookmarks_count');
                }
                if (! Schema::hasColumn('posts', 'valid_comments_count')) {
                    $table->unsignedInteger('valid_comments_count')->default(0)->after('valid_reactions_count');
                }
                if (! Schema::hasColumn('posts', 'valid_bookmarks_count')) {
                    $table->unsignedInteger('valid_bookmarks_count')->default(0)->after('valid_comments_count');
                }
                if (! Schema::hasColumn('posts', 'valid_shares_count')) {
                    $table->unsignedInteger('valid_shares_count')->default(0)->after('valid_bookmarks_count');
                }
                if (! Schema::hasColumn('posts', 'trending_raw_score')) {
                    $table->decimal('trending_raw_score', 12, 4)->default(0)->after('valid_shares_count');
                }
                if (! Schema::hasColumn('posts', 'trending_score')) {
                    $table->decimal('trending_score', 14, 6)->default(0)->after('trending_raw_score');
                }
                if (! Schema::hasColumn('posts', 'trending_calculated_at')) {
                    $table->timestamp('trending_calculated_at')->nullable()->after('trending_score');
                }
                if (! Schema::hasColumn('posts', 'needs_rerank')) {
                    $table->boolean('needs_rerank')->default(false)->after('trending_calculated_at');
                }
            });

            Schema::table('posts', function (Blueprint $table): void {
                $table->index(['visibility', 'moderation_status', 'trending_score', 'published_at', 'id'], 'posts_trending_feed_idx');
                $table->index(['needs_rerank', 'moderation_status', 'published_at'], 'posts_rerank_idx');
                $table->index(['user_id', 'content_fingerprint', 'created_at'], 'posts_user_fingerprint_idx');
                $table->index(['user_id', 'link_fingerprint', 'created_at'], 'posts_user_link_idx');
            });

            // Non-destructively sync lifecycle_status from existing moderation/delete/publish columns.
            DB::table('posts')->whereNotNull('deleted_at')->update(['lifecycle_status' => 'deleted']);
            DB::table('posts')->whereNull('deleted_at')->where('moderation_status', 'pending')->update(['lifecycle_status' => 'pending_review']);
            DB::table('posts')->whereNull('deleted_at')->where('moderation_status', 'rejected')->update(['lifecycle_status' => 'rejected']);
            DB::table('posts')->whereNull('deleted_at')->where('moderation_status', 'hidden')->update(['lifecycle_status' => 'hidden']);
            DB::table('posts')->whereNull('deleted_at')->where('moderation_status', 'removed')->update(['lifecycle_status' => 'rejected']);
            DB::table('posts')->whereNull('deleted_at')->where('moderation_status', 'approved')->whereNull('published_at')->update(['lifecycle_status' => 'draft']);

            // Safely backfill authentic bookmark counters & valid organic ranking metrics in bounded batches.
            if (Schema::hasTable('bookmarks')) {
                $bookmarkCounts = DB::table('bookmarks')
                    ->selectRaw('post_id, COUNT(*) as total')
                    ->groupBy('post_id')
                    ->pluck('total', 'post_id');

                foreach ($bookmarkCounts as $postId => $total) {
                    DB::table('posts')->where('id', (int) $postId)->update([
                        'bookmarks_count' => (int) $total,
                        'valid_bookmarks_count' => (int) $total,
                    ]);
                }
            }

            DB::table('posts')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->chunkById(100, static function ($posts): void {
                    $now = now();
                    foreach ($posts as $post) {
                        $humanViews = (int) ($post->human_views_count ?? 0)
                            + (int) ($post->guest_views_count ?? 0)
                            + (int) ($post->legacy_views_count ?? 0);
                        $validReactions = (int) ($post->reactions_count ?? 0);
                        $validComments = (int) ($post->comments_count ?? 0);
                        $validBookmarks = (int) ($post->valid_bookmarks_count ?? 0);
                        $validShares = (int) ($post->shares_count ?? 0);

                        $rawScore = round(
                            ($humanViews * 0.1)
                            + ($validReactions * 2.0)
                            + ($validComments * 4.0)
                            + ($validBookmarks * 5.0)
                            + ($validShares * 6.0),
                            4,
                        );
                        $publishedAt = $post->published_at !== null
                            ? Carbon::parse((string) $post->published_at)
                            : $now;
                        $ageHours = max(0.0, $publishedAt->diffInSeconds($now) / 3600.0);
                        $decay = pow(1.0 + ($ageHours / 24.0), 1.5);
                        $trendingScore = $decay > 0.0 ? round($rawScore / $decay, 6) : 0.0;

                        DB::table('posts')->where('id', (int) $post->id)->update([
                            'valid_reactions_count' => $validReactions,
                            'valid_comments_count' => $validComments,
                            'valid_shares_count' => $validShares,
                            'trending_raw_score' => $rawScore,
                            'trending_score' => $trendingScore,
                            'trending_calculated_at' => $now,
                        ]);
                    }
                });
        }

        if (! Schema::hasTable('post_idempotencies')) {
            Schema::create('post_idempotencies', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('action', 32);
                $table->string('idempotency_key', 96);
                $table->char('payload_hash', 64);
                $table->string('status', 20)->default('processing');
                $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
                $table->unsignedSmallInteger('response_code')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();

                $table->unique(['user_id', 'action', 'idempotency_key'], 'post_idemp_user_action_key_uq');
            });
        }

        if (! Schema::hasTable('publishing_quota_events')) {
            Schema::create('publishing_quota_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('action_type', 24);
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('trust_tier', 24)->default('standard_member');
                $table->char('ip_hash', 64)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['user_id', 'action_type', 'created_at'], 'pub_quota_user_action_created_idx');
                $table->index(['ip_hash', 'action_type', 'created_at'], 'pub_quota_ip_action_created_idx');
            });
        }

        if (! Schema::hasTable('spam_events')) {
            Schema::create('spam_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event_type', 32);
                $table->string('action_context', 32)->default('post.create');
                $table->unsignedSmallInteger('risk_score')->default(0);
                $table->string('risk_level', 24)->default('low');
                $table->string('decision', 24)->default('allow');
                $table->json('signals')->nullable();
                $table->string('trust_tier', 24)->nullable();
                $table->char('content_hash', 64)->nullable();
                $table->char('ip_hash', 64)->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['event_type', 'created_at'], 'spam_events_type_created_idx');
                $table->index(['user_id', 'created_at'], 'spam_events_user_created_idx');
                $table->index(['risk_level', 'created_at'], 'spam_events_risk_created_idx');
            });
        }

        if (! Schema::hasTable('post_shares')) {
            Schema::create('post_shares', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('post_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->char('actor_hash', 64);
                $table->string('share_channel', 32)->default('copy_link');
                $table->boolean('is_valid')->default(true);
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['post_id', 'actor_hash'], 'post_shares_post_actor_uq');
            });
        }

        if (! Schema::hasTable('user_hidden_posts')) {
            Schema::create('user_hidden_posts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('post_id')->constrained()->cascadeOnDelete();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['user_id', 'post_id'], 'user_hidden_posts_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_hidden_posts');
        Schema::dropIfExists('post_shares');
        Schema::dropIfExists('spam_events');
        Schema::dropIfExists('publishing_quota_events');
        Schema::dropIfExists('post_idempotencies');

        if (Schema::hasTable('posts')) {
            Schema::table('posts', function (Blueprint $table): void {
                $table->dropIndex('posts_trending_feed_idx');
                $table->dropIndex('posts_rerank_idx');
                $table->dropIndex('posts_user_fingerprint_idx');
                $table->dropIndex('posts_user_link_idx');
            });

            Schema::table('posts', function (Blueprint $table): void {
                $cols = array_filter([
                    'lifecycle_status',
                    'edited_at',
                    'edit_count',
                    'spam_risk_score',
                    'link_fingerprint',
                    'bookmarks_count',
                    'valid_reactions_count',
                    'valid_comments_count',
                    'valid_bookmarks_count',
                    'valid_shares_count',
                    'trending_raw_score',
                    'trending_score',
                    'trending_calculated_at',
                    'needs_rerank',
                ], static fn (string $c): bool => Schema::hasColumn('posts', $c));

                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
