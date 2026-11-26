<?php

namespace App\Enums;

enum AiModelGroup: string
{
    case Fast = 'fast';
    case LowCost = 'low_cost';
    case Advanced = 'advanced';
    case Translation = 'translation';
    case Moderation = 'moderation';
    case Research = 'research';

    public function label(): string
    {
        return match ($this) {
            self::Fast => 'Fast / Editorial',
            self::LowCost => 'Low Cost / High Volume',
            self::Advanced => 'Advanced Reasoning',
            self::Translation => 'Translation',
            self::Moderation => 'Moderation Assist',
            self::Research => 'Research & Summarization',
        };
    }
}
