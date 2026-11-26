<?php

namespace App\Enums;

enum SeoEntityType: string
{
    case Page = 'page';
    case Post = 'post';
    case Translation = 'translation';
    case Community = 'community';
    case Profile = 'profile';
    case Topic = 'topic';
    case Policy = 'policy';
}
