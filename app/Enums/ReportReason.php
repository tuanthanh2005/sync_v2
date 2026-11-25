<?php

namespace App\Enums;

enum ReportReason: string
{
    case Spam = 'spam';
    case Scam = 'scam';
    case Fraud = 'fraud';
    case Harassment = 'harassment';
    case HateSpeech = 'hate_speech';
    case Impersonation = 'impersonation';
    case Misinformation = 'misinformation';
    case Copyright = 'copyright';
    case IllegalContent = 'illegal_content';
    case InappropriateContent = 'inappropriate_content';
    case MisleadingAd = 'misleading_ad';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spam => __('Spam'),
            self::Scam => __('Scam'),
            self::Fraud => __('Fraud'),
            self::Harassment => __('Harassment or Abuse'),
            self::HateSpeech => __('Hate Speech'),
            self::Impersonation => __('Impersonation'),
            self::Misinformation => __('Misleading Information'),
            self::Copyright => __('Copyright Violation'),
            self::IllegalContent => __('Illegal Content'),
            self::InappropriateContent => __('Inappropriate Content'),
            self::MisleadingAd => __('Misleading Advertisement'),
            self::Other => __('Other Policy Violation'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $reason): array => [
                'value' => $reason->value,
                'label' => $reason->label(),
            ],
            self::cases(),
        );
    }
}
