<?php

namespace App\Services\Moderation;

use App\Models\BlockedKeyword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ContentFilterService
{
    public const CACHE_KEY = 'syncviet.blocked_keywords.v1';

    /**
     * Inspect text against active blocked keywords and domains.
     *
     * @return array{blocked: bool, flagged: bool, matched: list<string>, masked_text: string}
     */
    public function evaluate(string $text): array
    {
        if (! Schema::hasTable('blocked_keywords')) {
            return ['blocked' => false, 'flagged' => false, 'matched' => [], 'masked_text' => $text];
        }

        /** @var list<array{keyword: string, match_type: string, severity: string}> $rules */
        $rules = Cache::remember(self::CACHE_KEY, now()->addMinutes(10), static function (): array {
            return BlockedKeyword::query()
                ->where('is_active', true)
                ->get(['keyword', 'match_type', 'severity'])
                ->map(static fn (BlockedKeyword $row): array => [
                    'keyword' => $row->keyword,
                    'match_type' => $row->match_type,
                    'severity' => $row->severity,
                ])
                ->all();
        });

        $lowerText = mb_strtolower($text);
        $blocked = false;
        $flagged = false;
        $matched = [];
        $masked = $text;

        foreach ($rules as $rule) {
            $needle = mb_strtolower($rule['keyword']);

            if ($needle === '') {
                continue;
            }

            $isHit = match ($rule['match_type']) {
                'exact' => preg_match('/(?<!\pL)'.preg_quote($needle, '/').'(?!\pL)/u', $lowerText) === 1,
                'domain' => str_contains($lowerText, $needle),
                default => str_contains($lowerText, $needle),
            };

            if (! $isHit) {
                continue;
            }

            $matched[] = $rule['keyword'];

            if ($rule['severity'] === 'block') {
                $blocked = true;
            } elseif ($rule['severity'] === 'flag') {
                $flagged = true;
            } elseif ($rule['severity'] === 'mask') {
                $masked = (string) preg_replace(
                    '/'.preg_quote($rule['keyword'], '/').'/iu',
                    str_repeat('*', max(3, mb_strlen($rule['keyword']))),
                    $masked,
                );
            }
        }

        return [
            'blocked' => $blocked,
            'flagged' => $flagged,
            'matched' => array_values(array_unique($matched)),
            'masked_text' => $masked,
        ];
    }

    public function assertAllowedOrMask(string $text, string $field = 'content'): string
    {
        $result = $this->evaluate($text);

        if ($result['blocked']) {
            throw ValidationException::withMessages([
                $field => [__('Your submission contains restricted content or blocked domains.')],
            ]);
        }

        return $result['masked_text'];
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
