<?php

namespace App\Enums;

enum ContentOrigin: string
{
    case Human = 'human';
    case AiGenerated = 'ai_generated';
    case Editorial = 'editorial';
    case Sponsored = 'sponsored';
}
