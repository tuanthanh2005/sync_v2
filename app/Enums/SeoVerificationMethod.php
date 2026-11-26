<?php

namespace App\Enums;

enum SeoVerificationMethod: string
{
    case DnsTxt = 'dns_txt';
    case DnsCname = 'dns_cname';
    case HtmlFile = 'html_file';
    case HtmlMeta = 'html_meta';
}
