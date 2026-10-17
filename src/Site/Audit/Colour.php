<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * Reads a CSS colour written as hex, rgb() or oklch() into OKLab, where the distance between two colours is how
 * different they look. Under about 0.02 they can't be told apart.
 */
final class Colour
{
    /** @return array{0: float, 1: float, 2: float}|null L, a and b, or null for anything else, such as a color-mix() */
    public static function oklab(string $value): ?array
    {
        $value = strtolower(trim($value));

        if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value, $m)) {
            $hex = strlen($m[1]) <= 4 ? implode('', array_map(fn ($c) => $c.$c, str_split($m[1]))) : $m[1];

            return self::fromSrgb(hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
        }
        if (preg_match('/^rgba?\(\s*([\d.]+%?)[\s,]+([\d.]+%?)[\s,]+([\d.]+%?)/', $value, $m)) {
            $channel = fn (string $c) => str_ends_with($c, '%') ? (float) $c / 100 : (float) $c / 255;

            return self::fromSrgb($channel($m[1]), $channel($m[2]), $channel($m[3]));
        }
        if (preg_match('/^oklch\(\s*([\d.]+)(%?)\s+([\d.]+)\s+([\d.]+)/', $value, $m)) {
            $l = (float) $m[1] / ($m[2] === '%' ? 100 : 1);
            $h = deg2rad((float) $m[4]);

            return [$l, (float) $m[3] * cos($h), (float) $m[3] * sin($h)];
        }

        return null;
    }

    /** The colour's opacity, from 0 to 1: the fourth hex pair, rgba()'s fourth value or what follows a slash. */
    public static function alpha(string $value): float
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#(?:[0-9a-f]{3}([0-9a-f])|[0-9a-f]{6}([0-9a-f]{2}))$/', $value, $m)) {
            return hexdec(($m[1] ?? '') !== '' ? $m[1].$m[1] : $m[2]) / 255;
        }
        if (preg_match('/^\w+\((?:[^,()]*,){3}\s*([\d.]+)(%?)\s*\)$|\/\s*([\d.]+)(%?)\s*\)$/', $value, $m)) {
            [$number, $percent] = ($m[1] ?? '') !== '' ? [$m[1], $m[2]] : [$m[3], $m[4]];

            return (float) $number / ($percent === '%' ? 100 : 1);
        }

        return 1.0;
    }

    public static function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function fromSrgb(float $r, float $g, float $b): array
    {
        $linear = fn (float $c) => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        [$r, $g, $b] = [$linear($r), $linear($g), $linear($b)];

        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }
}
