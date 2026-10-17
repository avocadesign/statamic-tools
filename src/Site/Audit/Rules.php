<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * Every rule avoca:site:audit checks: the recipe section it comes from and the rule itself in a line, in the
 * recipe's words. The order here is the order the report reads in.
 */
final class Rules
{
    public const RULES = [
        'token-alias' => ['Colours and design tokens', "Never alias one of Tailwind's tokens: use its own name, in CSS too"],
        'token-duplicate' => ['Colours and design tokens', 'One value, one token: point every use at the token that already holds it'],
        'token-scale' => ['Colours and design tokens', "No spacing scale of the site's own beside Tailwind's: --spacing(4) in CSS, p-4 in a template"],
        'token-elsewhere' => ['Colours and design tokens', "Tokens are declared in theme.css and colours.css, Peak's in typography.css, and nowhere else"],

        'bem-class' => ['Styling', "A source's BEM classes are rewritten as utilities, or as data- hooks where CSS has to stay"],
        'single-use-class' => ['Styling', "A class only one block uses is utilities in that block's template"],
        'import-only' => ['Styling', 'No file whose job is to wrap another: import that file itself'],
        'peak-override' => ['Styling', "Change Peak in peak.css, don't override it from another stylesheet"],
        'source-none' => ['Styling', 'A Tailwind entry starts @import "tailwindcss" source(none) and names what to scan with @source'],
        'arbitrary-colour' => ['Styling', 'A colour is always a token, never a value written in a template'],
        'arbitrary-text-size' => ['Styling', 'A type size is always a step on the scale, never an arbitrary value'],

        'critical-shared-source' => ['Critical CSS', 'No shared import names a source: @source lines belong in site.css and the critical entries'],
        'critical-sheets' => ['Critical CSS', 'Keep page-specific critical sheets few: blocks that share pages share one'],
        'critical-small' => ['Critical CSS', 'A page-specific critical sheet only for a large saving: fold one under a couple of KB back into critical.css'],

        'script-class-selector' => ['Scripts', 'Scripts find elements by data- attributes, never by class'],
        'script-reads-css' => ['Scripts', 'A script takes its values from the template, never from a stylesheet'],
        'unused-alpine-plugin' => ['Scripts', 'Load only what a page uses: remove a plugin nothing uses'],
        'layout-third-party' => ['Scripts', 'A third-party script is pushed by the block that needs it, never printed by the layout'],

        'bare-img' => ['Images', 'Render images with the figure or picture partial, never a bare <img>'],

        'comment-long' => ['Comments', "A comment says what the code can't, in a line"],
        'desc-long' => ['Comments', "A template's @desc is one line"],
        'comment-block' => ['Comments', 'A comment that needs a paragraph is a sign: simplify the code, or move the reasoning to the guidance'],
        'comment-heavy' => ['Comments', 'File headers say what the file is for and what reads it, not the story of how it got there'],

        'ignore-reason' => ['Review steps', 'A finding the site keeps is recorded with its reason, or it is still a finding'],
    ];

    public static function section(string $rule): string
    {
        return self::RULES[$rule][0];
    }

    public static function text(string $rule): string
    {
        return self::RULES[$rule][1];
    }

    /** The section's anchor in recipe.md, as GitHub writes it. */
    public static function anchor(string $rule): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(self::section($rule))), '-');
    }

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_keys(self::RULES);
    }
}
