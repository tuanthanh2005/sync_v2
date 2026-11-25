<?php

namespace App\Enums;

enum ReportReason: string
{
    case Spam = 'spam';
    case Harassment = 'harassment';
    case Misinformation = 'misinformation';
    case Copyright = 'copyright';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spam => __('Spam or Scam'),
            self::Harassment => __('Harassment or Abuse'),
            self::Misinformation => __('Misleading Information'),
            self::Copyright => __('Copyright Infringement'),
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
