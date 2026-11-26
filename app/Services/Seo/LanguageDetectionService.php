<?php

namespace App\Services\Seo;

class LanguageDetectionService
{
    /**
     * @var array<string, list<string>>
     */
    private const STOPWORDS = [
        'vi' => ['của', 'và', 'là', 'trong', 'cho', 'với', 'người', 'không', 'được', 'này', 'những', 'một', 'các', 'tại', 'khi'],
        'es' => ['que', 'para', 'con', 'los', 'las', 'una', 'por', 'como', 'pero', 'más', 'este', 'está', 'sobre'],
        'pt-BR' => ['que', 'para', 'com', 'uma', 'não', 'mais', 'como', 'você', 'isso', 'também', 'muito', 'pelo'],
        'fr' => ['les', 'des', 'est', 'pour', 'dans', 'une', 'que', 'avec', 'plus', 'sur', 'pas', 'nous', 'vous'],
        'de' => ['und', 'der', 'die', 'das', 'ist', 'nicht', 'ein', 'eine', 'mit', 'für', 'auf', 'sind', 'auch'],
        'id' => ['yang', 'dan', 'untuk', 'dengan', 'tidak', 'ini', 'dari', 'pada', 'adalah', 'dalam', 'bisa', 'kami'],
        'fil' => ['ang', 'mga', 'hindi', 'para', 'ito', 'naman', 'lang', 'kami', 'kung', 'pero', 'yung', 'dahil'],
        'tr' => ['bir', 'için', 'olan', 'olarak', 'daha', 'gibi', 'ama', 'çok', 'değil', 'ile', 'kadar', 'ancak'],
        'en' => ['the', 'and', 'for', 'that', 'with', 'this', 'from', 'have', 'are', 'your', 'about', 'what', 'when'],
    ];

    public function __construct(
        private readonly SeoConfigService $config,
    ) {}

    /**
     * Detect the natural language of user-authored text using script ranges and diacritic/token heuristics.
     * Distinguishes original content language from viewer UI locale.
     */
    public function detect(string $text, ?string $fallbackLocale = null): string
    {
        $clean = trim(strip_tags($text));
        $supported = $this->config->enabledLocales();
        $fallback = ($fallbackLocale !== null && in_array($fallbackLocale, $supported, true))
            ? $fallbackLocale
            : $this->config->defaultLocale();

        if (mb_strlen($clean) < 8) {
            return $fallback;
        }

        // Non-Latin scripts (high confidence Unicode range detection)
        if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $clean) === 1 && in_array('ja', $supported, true)) {
            return 'ja';
        }
        if (preg_match('/[\x{AC00}-\x{D7AF}\x{1100}-\x{11FF}]/u', $clean) === 1 && in_array('ko', $supported, true)) {
            return 'ko';
        }
        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $clean) === 1 && in_array('zh-CN', $supported, true)) {
            return 'zh-CN';
        }
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $clean) === 1 && in_array('ar', $supported, true)) {
            return 'ar';
        }
        if (preg_match('/[\x{0900}-\x{097F}]/u', $clean) === 1 && in_array('hi', $supported, true)) {
            return 'hi';
        }
        if (preg_match('/[\x{0E00}-\x{0E7F}]/u', $clean) === 1 && in_array('th', $supported, true)) {
            return 'th';
        }

        // Vietnamese unique diacritics
        if (preg_match('/[ăâđêôơưĂÂĐÊÔƠƯảãạắằẳẵặấầẩẫậẻẽẹếềểễệỉĩịỏõọốồổỗộớờởỡợủũụứừửữựỷỹỵ]/u', $clean) === 1 && in_array('vi', $supported, true)) {
            return 'vi';
        }

        // Turkish unique characters
        if (preg_match('/[ğĞşŞıİ]/u', $clean) === 1 && in_array('tr', $supported, true)) {
            return 'tr';
        }

        // Latin-script token scoring
        $lower = mb_strtolower($clean);
        $words = preg_split('/[^\p{L}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return $fallback;
        }

        $wordSet = array_count_values($words);
        $bestLocale = $fallback;
        $bestScore = 0;

        foreach (self::STOPWORDS as $locale => $tokens) {
            if (! in_array($locale, $supported, true)) {
                continue;
            }

            $score = 0;
            foreach ($tokens as $token) {
                if (isset($wordSet[$token])) {
                    $score += $wordSet[$token];
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLocale = $locale;
            }
        }

        return $bestScore >= 2 ? $bestLocale : $fallback;
    }

    public function isValidBcp47(string $code): bool
    {
        return preg_match('/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-(?:[A-Z]{2}|\d{3}))?$/', $code) === 1;
    }
}
