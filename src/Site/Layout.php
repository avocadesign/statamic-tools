<?php

namespace Avocadesign\StatamicTools\Site;

/**
 * The site's own page frame, read from its layout template, so the reference pages sit on the ground its pages sit on.
 */
class Layout
{
    /**
     * The classes on the layout's <body>, such as `flex flex-col min-h-screen bg-white`. Antlers in the attribute is
     * left out, along with whatever an if or unless around it would add, because a class that depends on the page
     * can't be known here. Empty when there is no layout or its body has no class.
     */
    public static function bodyClass(string $layoutPath): string
    {
        if (! is_file($layoutPath) || ! preg_match('/<body\b[^>]*?\sclass\s*=\s*(["\'])(.*?)\1/is', (string) file_get_contents($layoutPath), $m)) {
            return '';
        }

        $classes = preg_replace('/\{\{\s*(if|unless)\b.*?\{\{\s*\/(if|unless)\s*\}\}/s', ' ', $m[2]);
        $classes = preg_replace('/\{\{.*?\}\}/s', ' ', $classes);

        return trim(preg_replace('/\s+/', ' ', $classes));
    }
}
