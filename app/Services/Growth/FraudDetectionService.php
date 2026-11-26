<?php

namespace App\Services\Growth;

use App\Enums\UserStatus;
use App\Models\Referral;
use App\Models\User;

class FraudDetectionService
{
    public function __construct(
        private readonly GrowthConfigService $config,
    ) {}

    /**
     * Normalize email for self-referral alias comparison (e.g. Gmail dot/plus-alias).
     */
    public function canonicalEmailKey(string $email): string
    {
        $lower = strtolower(trim($email));
        $parts = explode('@', $lower, 2);
        if (count($parts) !== 2) {
            return $lower;
        }

        [$local, $domain] = $parts;
        $plusPos = strpos($local, '+');
        if ($plusPos !== false) {
            $local = substr($local, 0, $plusPos);
        }

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }

    /**
     * Privacy-safe keyed hash for IP velocity checks (never stores raw IP PII).
     */
    public function hashIp(?string $ip): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return null;
        }

        $appKey = (string) config('app.key', 'syncviet-growth-salt');

        return hash_hmac('sha256', trim($ip), $appKey);
    }

    /**
     * Evaluate multi-signal risk score for a referral WITHOUT auto-banning or assuming shared Wi-Fi equals fraud.
     *
     * @return array{score: int, signals: list<string>, hard_reject: bool, hard_reason: string|null, needs_manual_review: bool}
     */
    public function evaluateReferral(User $referrer, User $referee, ?string $ipHash = null): array
    {
        $signals = [];
        $score = 0;

        // 1. Hard block: self-referral by ID or canonical email alias
        if ($referrer->id === $referee->id || $this->canonicalEmailKey($referrer->email) === $this->canonicalEmailKey($referee->email)) {
            return [
                'score' => 100,
                'signals' => ['self_referral'],
                'hard_reject' => true,
                'hard_reason' => 'Self-referral is not permitted.',
                'needs_manual_review' => false,
            ];
        }

        // 2. Hard block: AI Agent, System Official, or Test Accounts
        if (! $referrer->isHumanMember() || ! $referee->isHumanMember()) {
            return [
                'score' => 100,
                'signals' => ['non_human_account'],
                'hard_reject' => true,
                'hard_reason' => 'AI Agents, system accounts, and test accounts are excluded from referrals.',
                'needs_manual_review' => false,
            ];
        }

        // 3. Hard block: Direct referral loop (A referred B and B attempts to refer A)
        $reverseExists = Referral::query()
            ->where('referrer_id', $referee->id)
            ->where('referee_id', $referrer->id)
            ->exists();

        if ($reverseExists) {
            return [
                'score' => 100,
                'signals' => ['referral_loop'],
                'hard_reject' => true,
                'hard_reason' => 'Mutual referral loops are not permitted.',
                'needs_manual_review' => false,
            ];
        }

        // 4. Risk signal: Same hashed IP burst (NEVER concludes fraud alone; routes to manual review only when combined/high velocity)
        if ($ipHash !== null) {
            $recentSameIpForReferrer = Referral::query()
                ->where('referrer_id', $referrer->id)
                ->where('ip_hash', $ipHash)
                ->where('created_at', '>=', now()->subHours(24))
                ->count();

            if ($recentSameIpForReferrer >= 2) {
                $signals[] = 'repeated_ip_same_referrer_24h';
                $score += 35;
            }
        }

        // 5. Risk signal: High registration velocity on a single referral code within 1 hour
        $hourlyThreshold = max(2, $this->config->int('fraud_velocity_hourly_threshold', 5));
        $hourlyCount = Referral::query()
            ->where('referrer_id', $referrer->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($hourlyCount >= $hourlyThreshold) {
            $signals[] = 'high_hourly_registration_velocity';
            $score += 40;
        }

        // 6. Risk signal: Account moderation / warning / violation history
        if ($referee->status !== UserStatus::Active || $referee->violations_count > 0 || $referrer->violations_count > 1) {
            $signals[] = 'moderation_risk_signal';
            $score += 35;
        }

        // 7. Risk signal: Rapid unverified burst from same referrer
        $unverifiedPending = Referral::query()
            ->where('referrer_id', $referrer->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->whereHas('referee', static fn ($q) => $q->whereNull('email_verified_at'))
            ->count();

        if ($unverifiedPending >= 5) {
            $signals[] = 'unverified_signup_burst';
            $score += 30;
        }

        $score = min(100, $score);
        $reviewThreshold = max(20, $this->config->int('fraud_manual_review_score', 50));

        return [
            'score' => $score,
            'signals' => $signals,
            'hard_reject' => false,
            'hard_reason' => null,
            'needs_manual_review' => $score >= $reviewThreshold,
        ];
    }
}
