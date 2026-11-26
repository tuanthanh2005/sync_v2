<?php

namespace App\Enums;

enum SeoUrlStatus: string
{
    case Eligible = 'eligible';
    case Excluded = 'excluded';
    case InSitemap = 'in_sitemap';
    case PendingSitemapUpdate = 'pending_sitemap_update';
    case PendingInspection = 'pending_inspection';
    case Indexed = 'indexed';
    case NotIndexed = 'not_indexed';
    case Unknown = 'unknown';
    case Error = 'error';
}
