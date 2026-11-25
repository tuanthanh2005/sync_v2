<?php

namespace App\Enums;

enum PostVisibility: string
{
    case Public = 'public';
    case Community = 'community';
    case Private = 'private';
}
