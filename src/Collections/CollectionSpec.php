<?php

namespace Avocadesign\StatamicTools\Collections;

use Illuminate\Support\Str;

/**
 * The answers that describe a new collection: its name and handle, whether entries have URLs, whether they are dated
 * or ordered by hand, how an entry is built, whether a block lists them and whether editors get access to it. Everything the maker writes follows from these.
 */
final class CollectionSpec
{
    public const PAGE_BUILDER = 'page_builder';

    public const CUSTOM = 'custom';

    public function __construct(
        public readonly string $title,
        public readonly string $handle,
        public readonly ?string $route,
        public readonly bool $dated,
        public readonly bool $ordered,
        public readonly string $blueprint,
        public readonly string $description = '',
        public readonly bool $block = true,
        public readonly bool $editorAccess = true,
    ) {
    }

    /** "Team members" => team_members */
    public static function suggestHandle(string $title): string
    {
        return Str::slug($title, '_');
    }

    /** team_members => /team-members/{slug} */
    public static function suggestRoute(string $handle): string
    {
        return '/'.str_replace('_', '-', $handle).'/{slug}';
    }

    /** "page-builder" and "Page builder" both mean page_builder. */
    public static function blueprintType(string $answer): string
    {
        return Str::slug($answer, '_');
    }

    public static function handleProblem(string $handle): ?string
    {
        return preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $handle)
            ? null
            : "The handle \"{$handle}\" must start with a letter and use only lowercase letters, numbers and single underscores.";
    }

    public static function routeProblem(string $route): ?string
    {
        return match (true) {
            ! str_starts_with($route, '/') => "The route \"{$route}\" must start with a slash.",
            ! preg_match('/\{[a-z_]+\}/', $route) => "The route \"{$route}\" needs a placeholder such as {slug}, or every entry would share one URL.",
            default => null,
        };
    }

    /** @return array<int, string> what is wrong with the answers, in plain words */
    public function problems(): array
    {
        $problems = [];
        if (trim($this->title) === '') {
            $problems[] = 'The collection needs a title.';
        }
        if (($problem = self::handleProblem($this->handle)) !== null) {
            $problems[] = $problem;
        }
        if ($this->route !== null && ($problem = self::routeProblem($this->route)) !== null) {
            $problems[] = $problem;
        }
        if (! in_array($this->blueprint, [self::PAGE_BUILDER, self::CUSTOM], true)) {
            $problems[] = "The blueprint must be page_builder or custom, not \"{$this->blueprint}\".";
        } elseif ($this->custom() && trim($this->description) === '') {
            $problems[] = 'A custom blueprint needs a description of the fields each entry has.';
        } elseif (! $this->custom() && trim($this->description) !== '') {
            $problems[] = 'A description is only used for a custom blueprint.';
        }

        return $problems;
    }

    public function routed(): bool
    {
        return $this->route !== null;
    }

    public function custom(): bool
    {
        return $this->blueprint === self::CUSTOM;
    }

    /** The order Statamic lists entries in: manual when ordered by hand, then date for dated entries, then title. */
    public function ordering(): string
    {
        return $this->ordered ? 'manual' : ($this->dated ? 'date' : 'title');
    }

    /** Whether anything is left for a person or an AI agent to finish once the files are written. */
    public function needsFinishing(): bool
    {
        return $this->custom() || $this->block;
    }
}
