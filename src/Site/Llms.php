<?php

namespace Avocadesign\StatamicTools\Site;

/**
 * What llms.txt is missing. The starter kit ships a template that lists the site's pages by itself but leaves a
 * placeholder for every other collection, so a site that gains a routed collection quietly stops describing itself.
 * Nothing here writes the file: it says what a developer has to finish.
 */
final class Llms
{
    /** Text the starter kit ships as a prompt to the developer, which means the file was never finished. */
    private const PLACEHOLDERS = [
        'Other collections (1 section per collection)',
        '[Town/City, Region, Country]',
        'Optional second paragraph',
    ];

    /**
     * @param  array<int, string>  $routedCollections  handles of collections that have a route, so they have pages
     * @return array<int, string> one line per thing to fix, or none
     */
    public static function problems(?string $content, array $routedCollections): array
    {
        if ($content === null || trim($content) === '') {
            return ['llms.txt has no content: write it in the Bots global, LLMs tab'];
        }

        $problems = [];
        foreach (self::PLACEHOLDERS as $placeholder) {
            if (str_contains($content, $placeholder)) {
                $problems[] = "llms.txt still carries the starter kit's placeholder \"{$placeholder}\": finish it in the Bots global, LLMs tab";
            }
        }
        foreach ($routedCollections as $handle) {
            if (! str_contains($content, "collection:{$handle}")) {
                $problems[] = "llms.txt doesn't list the {$handle} collection, which has a route: add a section for it in the Bots global, LLMs tab";
            }
        }

        return $problems;
    }
}
