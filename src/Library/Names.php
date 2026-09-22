<?php

namespace Avocadesign\StatamicTools\Library;

use Avocadesign\StatamicTools\Site\Blocks;
use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;

/**
 * The handles a new block, set or collection would share with Avoca's library or with the site. A library item uses its
 * own handle, the handles of the blocks and sets it adds, and the handle of each collection, taxonomy, global set,
 * navigation and fieldset among its files. The same handle is a clash: the recipe installs a library item rather than
 * building its own, and installing it after new work took its handle would collide. A handle that differs only in case,
 * separators or a plural, or the same name editors see, is similar: worth a look, never a stop.
 */
final class Names
{
    public const CLASH = 'clash';

    public const SIMILAR = 'similar';

    /** kind => the site-relative files in an item that give it a handle of that kind */
    private const FILE_KINDS = [
        'collection' => '#^content/collections/([^/]+)\.yaml$#',
        'taxonomy' => '#^content/taxonomies/([^/]+)\.yaml$#',
        'global set' => '#^content/globals/([^/]+)\.yaml$#',
        'navigation' => '#^content/navigation/([^/]+)\.yaml$#',
        'fieldset' => '#^resources/fieldsets/([^/]+)\.yaml$#',
    ];

    private const CATEGORY = ['blocks' => 'block', 'sets' => 'set', 'presets' => 'preset'];

    /** @var array<int, array{item: Item, status: string, names: array<int, array{kind: string, handle: string, display: ?string}>}>|null */
    private ?array $entries = null;

    /** @param  \Closure|null  $siteNames  returns kind => [handle => the name editors see, or null]; null reads the site */
    public function __construct(private Library $library, private ?\Closure $siteNames = null)
    {
    }

    public static function make(): self
    {
        return new self(Library::make());
    }

    /**
     * The library items with a name that clashes with, or is similar to, this handle or the name editors would see,
     * clashes first. kinds and handles list what matched at that level; the kind item is the library item's own handle.
     *
     * @return array<int, array{level: string, item: string, name: string, category: string, status: string, kinds: array<int, string>, handles: array<int, string>}>
     */
    public function check(string $handle, ?string $display = null): array
    {
        $matches = [];
        foreach ($this->entries() as $entry) {
            $found = [self::CLASH => [], self::SIMILAR => []];
            foreach ($entry['names'] as $name) {
                if (($level = self::compare($handle, $display, $name['handle'], $name['display'])) !== null) {
                    $found[$level][] = $name;
                }
            }
            $level = $found[self::CLASH] !== [] ? self::CLASH : ($found[self::SIMILAR] !== [] ? self::SIMILAR : null);
            if ($level === null) {
                continue;
            }
            $matches[] = [
                'level' => $level,
                'item' => $entry['item']->handle,
                'name' => $entry['item']->name(),
                'category' => $entry['item']->category,
                'status' => $entry['status'],
                'kinds' => array_values(array_unique(array_column($found[$level], 'kind'))),
                'handles' => array_values(array_unique(array_column($found[$level], 'handle'))),
            ];
        }
        usort($matches, fn (array $a, array $b) => [$a['level'] !== self::CLASH, $a['item']] <=> [$b['level'] !== self::CLASH, $b['item']]);

        return $matches;
    }

    /**
     * The clashes with items this site hasn't installed. An installed item's files are already in the site, where the
     * site's own checks stop anything that collides with them.
     */
    public static function blocking(array $matches): array
    {
        return array_values(array_filter($matches, fn (array $match) => $match['level'] === self::CLASH && $match['status'] === 'not installed'));
    }

    public static function describe(array $match): string
    {
        $item = "the {$match['name']} ".(self::CATEGORY[$match['category']] ?? 'item');
        $kinds = array_values(array_diff($match['kinds'], ['item']));
        $handles = self::list($match['handles']);
        $has = $kinds === [] ? "whose handle is {$handles}" : 'which has a '.self::list($kinds)." called {$handles}";
        $installed = $match['status'] !== 'not installed';

        return match (true) {
            $match['level'] === self::CLASH && ! $installed => "Avoca's library already has {$item}, {$has}. Install it with php please avoca:library:install {$match['item']}, or choose another handle.",
            $match['level'] === self::CLASH => "This site installed {$item} from Avoca's library, {$has}.",
            ! $installed => "Avoca's library has {$item}, {$has}, a similar name. Look at it before building your own: php please avoca:library:install {$match['item']} --dry-run",
            default => "This site installed {$item} from Avoca's library, {$has}, a similar name.",
        };
    }

    /** @return array<string, array<string, ?string>> kind => handle => the name editors see, or null */
    public function siteNames(): array
    {
        if ($this->siteNames !== null) {
            return ($this->siteNames)();
        }
        $titles = fn ($structures) => $structures->mapWithKeys(fn ($structure) => [$structure->handle() => $structure->title()])->all();

        return [
            'block' => array_map(fn (array $set) => $set['display'], Blocks::pageBuilder()),
            'set' => array_map(fn (array $set) => $set['display'], Blocks::article()),
            'collection' => $titles(Collection::all()),
            'taxonomy' => $titles(Taxonomy::all()),
            'global set' => $titles(GlobalSet::all()),
            'navigation' => $titles(Nav::all()),
            'fieldset' => Fieldset::all()->reject(fn ($fieldset) => str_contains($fieldset->handle(), '::'))->mapWithKeys(fn ($fieldset) => [$fieldset->handle() => null])->all(),
        ];
    }

    /** @return array<int, array{level: string, kind: string, handle: string}> what this site already has with this handle, or a similar one, clashes first */
    public function inSite(string $handle, ?string $display = null): array
    {
        $matches = [];
        foreach ($this->siteNames() as $kind => $names) {
            foreach ($names as $other => $otherDisplay) {
                if (($level = self::compare($handle, $display, (string) $other, $otherDisplay)) !== null) {
                    $matches[] = ['level' => $level, 'kind' => $kind, 'handle' => (string) $other];
                }
            }
        }
        usort($matches, fn (array $a, array $b) => ($a['level'] !== self::CLASH) <=> ($b['level'] !== self::CLASH));

        return $matches;
    }

    /**
     * Library items this site hasn't installed that use a handle the site already has: installing one would collide.
     *
     * @return array<int, array{item: string, name: string, category: string, site: array<int, array{kind: string, handle: string}>}>
     */
    public function siteClashes(): array
    {
        $clashes = [];
        foreach ($this->siteNames() as $kind => $names) {
            foreach (array_keys($names) as $handle) {
                foreach (self::blocking($this->check((string) $handle)) as $match) {
                    $clashes[$match['item']] ??= ['item' => $match['item'], 'name' => $match['name'], 'category' => $match['category'], 'site' => []];
                    $clashes[$match['item']]['site'][] = ['kind' => $kind, 'handle' => (string) $handle];
                }
            }
        }
        ksort($clashes);

        return array_values($clashes);
    }

    public static function describeSiteClash(array $clash): string
    {
        $pairs = array_map(fn (array $site) => "{$site['kind']} {$site['handle']}", $clash['site']);
        $item = self::CATEGORY[$clash['category']] ?? 'item';
        $shares = count($pairs) === 1 ? 'shares its handle' : 'share their handles';

        return "this site's ".self::list($pairs)." {$shares} with the {$clash['name']} {$item} in Avoca's library, which the site hasn't installed, so installing the {$item} later would collide";
    }

    /** clash, similar or null */
    public static function compare(string $handle, ?string $display, string $other, ?string $otherDisplay): ?string
    {
        return match (true) {
            Str::lower($handle) === Str::lower($other) => self::CLASH,
            self::stem($handle) === self::stem($other) => self::SIMILAR,
            $display !== null && $otherDisplay !== null && self::stem($display) === self::stem($otherDisplay) => self::SIMILAR,
            default => null,
        };
    }

    /** Case, spaces, hyphens, underscores and a plural last word set aside: Case Studies, case-study and case_studies all become casestudy. */
    private static function stem(string $name): string
    {
        $words = preg_split('/[\s_\-]+/', Str::lower(trim($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words !== []) {
            $last = array_key_last($words);
            $words[$last] = Str::singular($words[$last]);
        }

        return implode('', $words);
    }

    private static function list(array $words): string
    {
        $words = array_values($words);

        return count($words) > 1 ? implode(', ', array_slice($words, 0, -1)).' and '.$words[count($words) - 1] : (string) ($words[0] ?? '');
    }

    /** @return array<int, array{item: Item, status: string, names: array<int, array{kind: string, handle: string, display: ?string}>}> */
    private function entries(): array
    {
        return $this->entries ??= array_values(array_map(fn (Item $item) => [
            'item' => $item,
            'status' => $this->library->status($item),
            'names' => $this->itemNames($item),
        ], $this->library->items()));
    }

    /** @return array<int, array{kind: string, handle: string, display: ?string}> */
    private function itemNames(Item $item): array
    {
        $names = [['kind' => 'item', 'handle' => $item->handle, 'display' => $item->name()]];
        foreach ([['block', $item->blocks()], ['set', $item->sets()]] as [$kind, $entries]) {
            foreach ($entries as $entry) {
                $names[] = ['kind' => $kind, 'handle' => $entry['handle'], 'display' => $entry['display']];
            }
        }
        foreach (array_keys($item->files()) as $path) {
            foreach (self::FILE_KINDS as $kind => $pattern) {
                if (preg_match($pattern, $path, $match)) {
                    $names[] = ['kind' => $kind, 'handle' => $match[1], 'display' => null];
                }
            }
        }

        return $names;
    }
}
