<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('policy_pages', 'page_type')) {
                $table->string('page_type', 24)->default('legal')->after('slug');
            }
            if (! Schema::hasColumn('policy_pages', 'seo_title')) {
                $table->string('seo_title', 160)->nullable()->after('content_en');
            }
            if (! Schema::hasColumn('policy_pages', 'meta_description')) {
                $table->string('meta_description', 320)->nullable()->after('seo_title');
            }
            if (! Schema::hasColumn('policy_pages', 'canonical_url')) {
                $table->string('canonical_url', 255)->nullable()->after('meta_description');
            }
            if (! Schema::hasColumn('policy_pages', 'og_image_disk')) {
                $table->string('og_image_disk', 32)->nullable()->after('canonical_url');
            }
            if (! Schema::hasColumn('policy_pages', 'og_image_path')) {
                $table->string('og_image_path', 255)->nullable()->after('og_image_disk');
            }
            if (! Schema::hasColumn('policy_pages', 'index_eligible')) {
                $table->boolean('index_eligible')->default(true)->after('og_image_path');
            }
            if (! Schema::hasColumn('policy_pages', 'effective_at')) {
                $table->timestamp('effective_at')->nullable()->after('published_at');
            }
            if (! Schema::hasColumn('policy_pages', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('effective_at');
            }
        });

        if (! Schema::hasTable('policy_page_translations')) {
            Schema::create('policy_page_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_page_id')->constrained('policy_pages')->cascadeOnDelete();
                $table->string('locale', 12);
                $table->string('title', 180);
                $table->string('summary', 500)->nullable();
                $table->longText('content')->nullable();
                $table->string('seo_title', 160)->nullable();
                $table->string('meta_description', 320)->nullable();
                $table->string('status', 20)->default('draft');
                $table->timestamp('published_at')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['policy_page_id', 'locale'], 'policy_page_translations_unique');
                $table->index(['locale', 'status'], 'policy_page_translations_locale_status_idx');
            });
        }

        if (! Schema::hasTable('policy_page_revisions')) {
            Schema::create('policy_page_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_page_id')->constrained('policy_pages')->cascadeOnDelete();
                $table->string('locale', 12)->default('en');
                $table->unsignedInteger('version_number');
                $table->string('title', 180);
                $table->string('summary', 500)->nullable();
                $table->longText('content')->nullable();
                $table->string('change_note', 255)->nullable();
                $table->timestamp('effective_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();

                $table->index(['policy_page_id', 'locale', 'version_number'], 'policy_page_revisions_lookup_idx');
            });
        }

        Schema::table('footer_links', function (Blueprint $table): void {
            if (! Schema::hasColumn('footer_links', 'placement')) {
                $table->string('placement', 16)->default('main')->after('footer_group');
            }
            if (! Schema::hasColumn('footer_links', 'translations')) {
                $table->json('translations')->nullable()->after('label_en');
            }
        });
    }

    public function down(): void
    {
        Schema::table('footer_links', function (Blueprint $table): void {
            $columns = array_filter(
                ['placement', 'translations'],
                static fn (string $col): bool => Schema::hasColumn('footer_links', $col),
            );
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::dropIfExists('policy_page_revisions');
        Schema::dropIfExists('policy_page_translations');

        Schema::table('policy_pages', function (Blueprint $table): void {
            $columns = array_filter(
                [
                    'page_type',
                    'seo_title',
                    'meta_description',
                    'canonical_url',
                    'og_image_disk',
                    'og_image_path',
                    'index_eligible',
                    'effective_at',
                    'archived_at',
                ],
                static fn (string $col): bool => Schema::hasColumn('policy_pages', $col),
            );
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
