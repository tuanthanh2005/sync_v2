<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            if (! Schema::hasColumn('posts', 'slug')) {
                $table->string('slug', 160)->nullable()->after('title')->index();
            }
            if (! Schema::hasColumn('posts', 'language_code')) {
                $table->string('language_code', 12)->default('en')->after('slug')->index();
            }
            if (! Schema::hasColumn('posts', 'content_fingerprint')) {
                $table->char('content_fingerprint', 64)->nullable()->after('language_code')->index();
            }
            if (! Schema::hasColumn('posts', 'is_ai_assisted')) {
                $table->boolean('is_ai_assisted')->default(false)->after('is_spam');
            }
            if (! Schema::hasColumn('posts', 'seo_noindex')) {
                $table->boolean('seo_noindex')->default(false)->after('is_ai_assisted')->index();
            }
            if (! Schema::hasColumn('posts', 'seo_quality_score')) {
                $table->unsignedSmallInteger('seo_quality_score')->nullable()->after('seo_noindex');
            }
        });

        Schema::table('communities', function (Blueprint $table): void {
            if (! Schema::hasColumn('communities', 'language_code')) {
                $table->string('language_code', 12)->default('en')->after('slug')->index();
            }
            if (! Schema::hasColumn('communities', 'seo_noindex')) {
                $table->boolean('seo_noindex')->default(false)->after('is_featured');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'trust_level')) {
                $table->unsignedTinyInteger('trust_level')->default(1)->after('violations_count')->index();
            }
            if (! Schema::hasColumn('users', 'acquisition_source')) {
                $table->string('acquisition_source', 40)->nullable()->after('preferred_locale')->index();
            }
            if (! Schema::hasColumn('users', 'acquisition_landing_url')) {
                $table->string('acquisition_landing_url', 255)->nullable()->after('acquisition_source');
            }
            if (! Schema::hasColumn('users', 'acquisition_locale')) {
                $table->string('acquisition_locale', 12)->nullable()->after('acquisition_landing_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $cols = array_filter(
                ['slug', 'language_code', 'content_fingerprint', 'is_ai_assisted', 'seo_noindex', 'seo_quality_score'],
                static fn (string $c): bool => Schema::hasColumn('posts', $c),
            );
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('communities', function (Blueprint $table): void {
            $cols = array_filter(
                ['language_code', 'seo_noindex'],
                static fn (string $c): bool => Schema::hasColumn('communities', $c),
            );
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $cols = array_filter(
                ['trust_level', 'acquisition_source', 'acquisition_landing_url', 'acquisition_locale'],
                static fn (string $c): bool => Schema::hasColumn('users', $c),
            );
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
