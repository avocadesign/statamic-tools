<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Site\PageSample;
use Illuminate\Console\Command;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\YAML;

class SiteUrls extends Command
{
    /** How many entries a collection is read for, so a site with ten thousand of them still answers quickly. */
    private const SCAN = 50;

    protected $signature = 'avoca:site:urls {--sample= : how many more pages to add for coverage}';

    protected $description = 'The pages a check should render: the ones listed, every collection mount, one entry per collection, then whatever covers the most.';

    public function handle(): int
    {
        $policy = $this->policy();
        $urls = [];
        $add = function (?string $url) use (&$urls): void {
            $path = is_string($url) ? (parse_url($url, PHP_URL_PATH) ?: '/') : null;
            if ($path !== null && ! in_array($path, $urls, true)) {
                $urls[] = $path;
            }
        };

        // Listed by hand: the home page, and whatever the site says must always work.
        foreach ($policy['urls'] ?? ['/'] as $url) {
            $add($url);
        }

        $scanned = [];
        $covered = [];
        foreach (Collection::all() as $collection) {
            // A page with a collection mounted on it renders that collection: it breaks first and loudest.
            $add($collection->mount()?->url());

            if ($collection->routes()->filter()->isEmpty()) {
                continue;
            }

            $pages = [];
            foreach (Entry::query()->where('collection', $collection->handle())->whereStatus('published')->limit(self::SCAN)->get() as $entry) {
                if ($url = $entry->url()) {
                    $pages[] = ['url' => $url, 'features' => $this->features($collection->handle(), $entry)];
                }
            }

            // One entry from every collection, so each collection's own template is rendered once.
            foreach (PageSample::choose($pages, 1) as $url) {
                $add($url);
                $covered = array_unique([...$covered, ...$this->featuresOf($pages, $url)]);
            }
            $scanned = [...$scanned, ...$pages];
        }

        // Then whatever adds the most blocks and blueprints nothing chosen already has.
        $sample = (int) ($this->option('sample') ?? $policy['sample'] ?? 5);
        $rest = array_values(array_filter($scanned, fn (array $page) => ! in_array(parse_url($page['url'], PHP_URL_PATH), $urls, true)));
        foreach (PageSample::choose($rest, max(0, $sample), $covered) as $url) {
            $add($url);
        }

        foreach ($urls as $url) {
            $this->line($url);
        }

        return self::SUCCESS;
    }

    /** What a page exercises: its collection, its blueprint, its template and the blocks on it. */
    private function features(string $collection, $entry): array
    {
        $features = ["collection:{$collection}", 'blueprint:'.$entry->blueprint()?->handle(), 'template:'.$entry->template()];
        $field = (string) config('statamic-tools.site.page_builder_fieldset', 'page_builder');
        foreach ((array) $entry->value($field) as $block) {
            if (is_array($block) && isset($block['type'])) {
                $features[] = 'block:'.$block['type'];
            }
        }

        return array_values(array_unique($features));
    }

    private function featuresOf(array $pages, string $url): array
    {
        foreach ($pages as $page) {
            if ($page['url'] === $url) {
                return $page['features'];
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        $file = base_path('resources/site/updates.yaml');

        return is_file($file) ? (array) YAML::file($file)->parse() : [];
    }
}
