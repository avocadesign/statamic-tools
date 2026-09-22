<?php

namespace Avocadesign\StatamicTools\Library;

use Statamic\Facades\AssetContainer;
use Symfony\Component\Yaml\Yaml;

/**
 * Sample entries in the library mark their images instead of shipping photos that the install would add to a live site.
 * When a file under content/ is installed, every marker becomes one image the site already has in its images container:
 * the largest with "placeholder" in its file name, otherwise the largest at least MIN_LONG_SIDE pixels on its long side.
 * When there is neither, each marked field is left out. It reads the container and never writes to it.
 */
final class SampleImages
{
    public const MARKER = 'avoca:sample-image';

    /** The long side, in pixels, an image not named placeholder needs to be big enough. */
    public const MIN_LONG_SIDE = 1200;

    /** The file types Statamic treats as images. */
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /** @var array{path: string, width: int, height: int, named: bool}|false|null null until looked for, false when there is none */
    private array|false|null $chosen = null;

    /** @param  ?string  $root  the images container's folder, or null when the site has no such container */
    public function __construct(
        private ?string $root,
        private string $container = 'images',
    ) {
    }

    public static function make(): self
    {
        $handle = (string) config('statamic-tools.library.sample_images_container', 'images');

        return new self(AssetContainer::find($handle)?->disk()->path(''), $handle);
    }

    /**
     * The contents with each marker replaced by the chosen image, or its field left out when there is none, and what the
     * plan says about it. Null when no value is the marker. In a file with front matter only the front matter is read,
     * so the body stays as it is.
     *
     * @return array{contents: string, detail: string}|null
     *
     * @throws \InvalidArgumentException when a marker isn't a whole value on its own line, or the YAML doesn't parse
     */
    public function apply(string $contents): ?array
    {
        if (! str_contains($contents, self::MARKER)) {
            return null;
        }
        [$head, $yaml, $tail] = preg_match('/\A(---[ \t]*\r?\n)(.*?)(^---[ \t]*\r?$.*)\z/ms', $contents, $parts)
            ? [$parts[1], $parts[2], $parts[3]]
            : ['', $contents, ''];

        $image = $this->choose();
        $fields = [];
        // A line whose whole value is the marker, quoted or not: "key: marker", "- marker" or "- key: marker".
        $line = '/^(?<lead>[ \t]*(?<dash>-[ \t]+)?(?:(?<key>[\w.-]+|\'[^\'\r\n]*\'|"[^"\r\n]*")[ \t]*:[ \t]+)?)(?<quote>[\'"]?)'
            .preg_quote(self::MARKER, '/').'\k<quote>[ \t]*(?:#[^\r\n]*)?(?<eol>\r?\n|\z)/m';
        $replaced = (string) preg_replace_callback($line, function (array $m) use ($image, $yaml, &$fields): string {
            $lead = $m['lead'][0];
            $key = trim($m['key'][0] ?? '', '\'"');
            $indent = substr($lead, 0, strspn($lead, " \t"));
            $fields[] = $key !== '' ? $key : $this->listKey(substr($yaml, 0, $m[0][1]), strlen($indent));
            if ($image !== null) {
                return $lead."'".str_replace("'", "''", $image['path'])."'".$m['eol'][0];
            }

            // The line goes, but "- key: marker" keeps its dash, so the rest of that list item stays in the list.
            return $key !== '' && ($m['dash'][0] ?? '') !== '' ? $indent.'-'.$m['eol'][0] : '';
        }, $yaml, -1, $count, PREG_OFFSET_CAPTURE);

        try {
            $parsed = Yaml::parse($replaced);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('its YAML does not parse with the sample images in place: '.$e->getMessage());
        }
        $left = false;
        if (is_array($parsed)) {
            array_walk_recursive($parsed, function ($value) use (&$left) {
                $left = $left || $value === self::MARKER;
            });
        }
        if ($left) {
            throw new \InvalidArgumentException('a sample image marker must be the whole value of a field or list item, on its own line');
        }
        if ($count === 0) {
            return null;
        }

        return ['contents' => $head.$replaced.$tail, 'detail' => $this->detail(array_values(array_unique($fields)), $image)];
    }

    /** @return array{path: string, width: int, height: int, named: bool}|null the image every marker uses, with its path in the container */
    public function choose(): ?array
    {
        if ($this->chosen === null) {
            $named = $others = [];
            foreach ($this->images() as $path) {
                if (stripos(basename($path), 'placeholder') !== false) {
                    $named[] = $path;
                } else {
                    $others[] = $path;
                }
            }
            $this->chosen = match (true) {
                ($best = $this->largest($named, 0)) !== null => [...$best, 'named' => true],
                ($best = $this->largest($others, self::MIN_LONG_SIDE)) !== null => [...$best, 'named' => false],
                default => false,
            };
        }

        return $this->chosen ?: null;
    }

    /**
     * Every image worth showing, best first: those named placeholder, biggest first, then the rest that are
     * big enough. choose() takes the first of these; the reference pages walk the list when they need several.
     *
     * @return array<int, string>
     */
    public function choices(): array
    {
        $named = $others = [];
        foreach ($this->images() as $path) {
            if (stripos(basename($path), 'placeholder') !== false) {
                $named[] = $path;
            } else {
                $others[] = $path;
            }
        }

        return [...$this->biggestFirst($named, 0), ...$this->biggestFirst($others, self::MIN_LONG_SIDE)];
    }

    /** @return array<int, string> the given paths that are big enough, most pixels first */
    private function biggestFirst(array $paths, int $minimum): array
    {
        $sized = [];
        foreach ($paths as $path) {
            [$width, $height] = $this->dimensions($path) ?? [0, 0];
            if (max($width, $height) >= max($minimum, 1)) {
                $sized[] = ['path' => $path, 'pixels' => $width * $height];
            }
        }
        usort($sized, fn (array $a, array $b) => $b['pixels'] <=> $a['pixels']);

        return array_column($sized, 'path');
    }

    /** What the plan says: the fields and the image they use, or why they are left out. */
    private function detail(array $fields, ?array $image): string
    {
        $names = count($fields) > 1 ? implode(', ', array_slice($fields, 0, -1)).' and '.end($fields) : $fields[0];

        return match (true) {
            $image !== null => $names.(count($fields) > 1 ? ' use ' : ' uses ')."{$image['path']} ({$image['width']} × {$image['height']}), the largest image"
                .($image['named'] ? ' named placeholder' : ', as none is named placeholder'),
            $this->root === null => "{$names} left out, as the site has no {$this->container} asset container",
            default => "{$names} left out, as no image in the {$this->container} container is named placeholder or is at least ".self::MIN_LONG_SIDE.' pixels on its long side',
        };
    }

    /** The key a list item belongs to: the nearest line above that opens a key, at or left of the item's dash. */
    private function listKey(string $above, int $indent): string
    {
        foreach (array_reverse(preg_split('/\r?\n/', $above) ?: []) as $line) {
            if (preg_match('/^([ \t]*)(?:-[ \t]+)?([\w.-]+)[ \t]*:[ \t]*(?:#.*)?$/', $line, $k) && strlen($k[1]) <= $indent) {
                return $k[2];
            }
        }

        return 'a list item';
    }

    /** @return array<int, string> the container's images, as paths in the container in path order, skipping hidden files and folders such as .meta */
    private function images(): array
    {
        if ($this->root === null || ! is_dir($this->root)) {
            return [];
        }
        $root = rtrim($this->root, '/');
        $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            fn (\SplFileInfo $file) => ! str_starts_with($file->getFilename(), '.'),
        ));
        $paths = [];
        foreach ($files as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                $paths[] = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @return array{path: string, width: int, height: int}|null the image with the most pixels, and at least $minimum on its long side */
    private function largest(array $paths, int $minimum): ?array
    {
        $best = null;
        foreach ($paths as $path) {
            [$width, $height] = $this->dimensions($path) ?? [0, 0];
            if (max($width, $height) >= max($minimum, 1) && ($best === null || $width * $height > $best['width'] * $best['height'])) {
                $best = ['path' => $path, 'width' => $width, 'height' => $height];
            }
        }

        return $best;
    }

    /** @return array{0: int, 1: int}|null width and height from Statamic's metadata, or from the file when there is none */
    private function dimensions(string $path): ?array
    {
        $root = rtrim((string) $this->root, '/');
        $folder = dirname($path);
        $meta = $root.'/'.($folder === '.' ? '' : "{$folder}/").'.meta/'.basename($path).'.yaml';
        if (is_file($meta)) {
            try {
                $data = (array) Yaml::parse((string) file_get_contents($meta));
            } catch (\Throwable) {
                $data = [];
            }
            if (is_numeric($data['width'] ?? null) && is_numeric($data['height'] ?? null) && $data['width'] > 0 && $data['height'] > 0) {
                return [(int) $data['width'], (int) $data['height']];
            }
        }
        $size = @getimagesize("{$root}/{$path}");

        return $size && $size[0] > 0 && $size[1] > 0 ? [(int) $size[0], (int) $size[1]] : null;
    }
}
