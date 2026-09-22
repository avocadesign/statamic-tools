<?php

namespace Avocadesign\StatamicTools\Site;

/**
 * Which pages a check should render when rendering all of them is out of the question.
 *
 * A dependency update breaks a template or a block, not a page, so the pages worth rendering are the
 * ones that cover the most templates and blocks between them. Sampling at random would mostly render
 * the same page over and over on a site where two hundred pages are built from the same three blocks.
 *
 * Nothing here knows about Statamic: give it what each page uses and it says which to render.
 */
final class PageSample
{
    /**
     * @param  array<int, array{url: string, features: array<int, string>}>  $pages  a page's blueprint, template
     *                                                                               and the blocks on it
     * @param  array<int, string>  $covered  what pages chosen elsewhere already cover
     * @return array<int, string> the urls to render, most new coverage first, within the limit
     */
    public static function choose(array $pages, int $limit, array $covered = []): array
    {
        $chosen = [];

        while (count($chosen) < $limit) {
            $best = null;
            $bestNew = 0;
            foreach ($pages as $i => $page) {
                if (isset($chosen[$i])) {
                    continue;
                }
                $new = count(array_diff($page['features'], $covered));
                // A tie goes to the page listed first, so the same site gives the same answer twice.
                if ($new > $bestNew) {
                    $best = $i;
                    $bestNew = $new;
                }
            }

            // Nothing left adds a template or a block that another chosen page doesn't already have.
            if ($best === null) {
                break;
            }

            $chosen[$best] = $pages[$best]['url'];
            $covered = array_unique([...$covered, ...$pages[$best]['features']]);
        }

        return array_values($chosen);
    }
}
