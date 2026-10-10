<?php

namespace Avocadesign\StatamicTools\Prototype;

use Avocadesign\StatamicTools\Site\CssTokens;
use Avocadesign\StatamicTools\Site\SiteCss;

/**
 * The site's discovery prototype. The interface, the route and the sign-in are the add-on's, so every site gets their
 * improvements; what the prototype says is the site's own, in prototype/<version>/: data.js (the project, decisions,
 * notes, journeys, content model and sitemap), pages.js (its header, footer, pages and routes) and version.json.
 * The frames draw those pages with the site's real CSS, compiled by Tailwind's browser build, and the add-on's
 * wireframe layer on top.
 *
 * A version folder holding a built index.html and no data.js is from before the move: it is served as it was built,
 * because a version shared with a client never changes.
 */
final class Prototype
{
    public const FORMAT = 2;

    /** On for local and staging unless PROTOTYPE_ENABLED says otherwise, off everywhere else. */
    public static function enabled(): bool
    {
        $setting = config('statamic-tools.prototype.enabled');
        if ($setting === null || $setting === '') {
            return app()->environment(['local', 'staging']);
        }

        return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
    }

    public static function folder(): string
    {
        $path = (string) config('statamic-tools.prototype.path', 'prototype');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /**
     * Every version, keyed by its folder name and in natural order: 1, 2, 10, then A, B.
     *
     * @return array<string, array{id: string, label: string, date: ?string, notes: array<int, string>, default: bool, url: string, legacy: bool}>
     */
    public static function versions(): array
    {
        $versions = [];
        foreach (glob(self::folder().'/*', GLOB_ONLYDIR) ?: [] as $folder) {
            $id = basename($folder);
            if (! preg_match('/^[A-Za-z0-9-]+$/', $id)) {
                continue;
            }
            $current = is_file("{$folder}/data.js") && is_file("{$folder}/pages.js");
            $legacy = ! $current && is_file("{$folder}/index.html");
            if (! $current && ! $legacy) {
                continue;
            }
            $about = is_file("{$folder}/version.json") ? (json_decode((string) file_get_contents("{$folder}/version.json"), true) ?: []) : [];
            $versions[$id] = [
                'id' => (string) $id,
                'label' => (string) ($about['label'] ?? "Version {$id}"),
                'date' => $about['date'] ?? null,
                'notes' => array_values((array) ($about['notes'] ?? [])),
                'default' => (bool) ($about['default'] ?? false),
                'url' => "/prototype/{$id}",
                'legacy' => $legacy,
            ];
        }
        uksort($versions, 'strnatcasecmp');

        return $versions;
    }

    /** The version marked "default" in its version.json, or else the last. */
    public static function defaultVersion(): ?array
    {
        $versions = self::versions();
        foreach ($versions as $version) {
            if ($version['default']) {
                return $version;
            }
        }

        return $versions === [] ? null : end($versions);
    }

    /**
     * The page for a version, with who is viewing and which versions there are for its top bar.
     *
     * @param  array{id: string, label: string, date: ?string, notes: array<int, string>, legacy: bool}  $version
     */
    public static function page(array $version, ?array $viewer): string
    {
        $folder = self::folder().'/'.$version['id'];
        $json = fn ($value) => json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $list = array_values(array_map(fn ($v) => array_diff_key($v, ['legacy' => true]), self::versions()));
        $globals = 'window.PROTOTYPE_VIEWER = '.$json($viewer).'; window.PROTOTYPE_VERSIONS = '.$json($list).';';

        if ($version['legacy']) {
            return (string) preg_replace('/<head>/', '<head><script>'.$globals.'</script>', (string) file_get_contents("{$folder}/index.html"), 1);
        }

        $agency = self::agency();
        $interface = strtr(self::resource('interface.html'), [
            '<!--AGENCY_LOGO-->' => $agency['logo'],
            '<!--AGENCY_LOGO_DARK-->' => $agency['logo_dark'],
            '__AGENCY_NAME__' => e($agency['name']),
            '__AGENCY_URL__' => e($agency['url']),
        ]);
        $about = ['id' => $version['id'], 'label' => $version['label'], 'date' => $version['date'], 'notes' => $version['notes']];
        $title = e($version['label'].' · '.config('app.name').' prototype');

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex, nofollow"><link rel="icon" href="data:,">'
            ."<title>{$title}</title>"
            .'<script>try { const t = localStorage.getItem(\'prototype-theme\'); if (t === \'light\' || t === \'dark\') document.documentElement.dataset.theme = t; } catch (e) {}</script>'
            .'<style>'.self::resource('interface.css').'</style></head><body>'."\n"
            .$interface."\n"
            .'<script type="text/plain" id="frame-css">'."\n".self::frameCss()."\n".'</script>'."\n"
            .'<script type="text/plain" id="frame-js">'."\n".self::resource('frame.js')."\n".'</script>'."\n"
            .'<script>'.$globals.' window.PROTOTYPE_TAILWIND = '.$json(self::tailwindVersion()).";\n"
            .'const AGENCY = '.$json(['handle' => $agency['handle'], 'name' => $agency['name'], 'url' => $agency['url']]).";\n"
            .'const VERSION = '.$json($about).";\n"
            .(string) file_get_contents("{$folder}/data.js")."\n"
            .self::resource('helpers.js')."\n"
            .(string) file_get_contents("{$folder}/pages.js")."\n"
            .self::resource('shell.js')."\n"
            .'</script></body></html>'."\n";
    }

    /**
     * The frames' stylesheet: the site's own CSS, then the wireframe's greys for the site's brand colours, then the
     * add-on's wireframe layer.
     */
    public static function frameCss(): string
    {
        $entry = (string) config('statamic-tools.site.css_entry', 'resources/css/site.css');
        $entry = str_starts_with($entry, '/') ? $entry : base_path($entry);
        $greys = [];
        foreach (CssTokens::fromEntry($entry)->all() as $name => $token) {
            $value = trim($token['value']);
            $brand = str_starts_with($name, 'color-')
                && ! preg_match('/^color-(gray|grey|slate|zinc|neutral|stone|black|white|current|transparent|light|dark|primary)(-|$)/', $name)
                && ! preg_match('/^(var|color-mix)\(/i', $value);
            if ($brand) {
                $greys[] = "    --{$name}: oklch(from {$value} l 0 h);";
            }
        }

        return SiteCss::flatten($entry)
            ."\n\n/* Wireframe: the site's other brand colours, each at its own lightness in grey. */\n@theme static {\n".implode("\n", $greys)."\n}\n\n"
            .self::resource('wireframe.css');
    }

    /** The Tailwind the site builds with, so the frames compile its CSS the same way. */
    public static function tailwindVersion(): string
    {
        $package = base_path('node_modules/tailwindcss/package.json');
        $version = is_file($package) ? (json_decode((string) file_get_contents($package), true)['version'] ?? null) : null;

        return is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : (string) config('statamic-tools.prototype.tailwind', '4.3.3');
    }

    /** @return array{handle: string, name: string, url: string, logo: string, logo_dark: string} */
    public static function agency(): array
    {
        $handle = (string) config('agency.handle', '');
        $folder = public_path("agencies/{$handle}");
        $about = $handle !== '' && is_file("{$folder}/agency.json") ? (json_decode((string) file_get_contents("{$folder}/agency.json"), true) ?: []) : [];
        $svg = fn (string $file) => $handle !== '' && is_file("{$folder}/{$file}") ? (string) file_get_contents("{$folder}/{$file}") : '';

        return [
            'handle' => $handle,
            'name' => (string) ($about['name'] ?? ''),
            'url' => (string) ($about['url'] ?? ''),
            'logo' => $svg('logo.svg'),
            'logo_dark' => $svg('logo-dark.svg') ?: $svg('logo.svg'),
        ];
    }

    private static function resource(string $file): string
    {
        return (string) file_get_contents(__DIR__."/../../resources/prototype/{$file}");
    }
}
