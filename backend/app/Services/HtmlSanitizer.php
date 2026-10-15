<?php

namespace App\Services;

use HTMLPurifier;
use HTMLPurifier_Config;

/** Allow-list sanitiser for lecturer-authored rich text. Scripts, styles, forms, iframes and event handlers are stripped. */
class HtmlSanitizer
{
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $c = HTMLPurifier_Config::createDefault();
        $c->set('Cache.SerializerPath', storage_path('framework/cache'));
        $c->set('HTML.Allowed', 'p,br,strong,b,em,i,u,s,blockquote,ul,ol,li,h2,h3,h4,a[href|title],hr,table,thead,tbody,tr,th,td,code,pre,sup,sub');
        $c->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $c->set('HTML.TargetBlank', true);
        $c->set('HTML.Nofollow', true);
        $c->set('AutoFormat.RemoveEmpty', true);
        $this->purifier = new HTMLPurifier($c);
    }

    public function clean(?string $html): ?string
    {
        return $html === null ? null : $this->purifier->purify($html);
    }
}
