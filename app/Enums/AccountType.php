<?php

namespace App\Enums;

enum AccountType: string
{
    case Human = 'human';
    case AiAgent = 'ai_agent';
    case SystemOfficial = 'system_official';
}
