<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * The Tailwind utility that says what a CSS declaration says, for the common ones whose value is a keyword, a step
 * of the spacing scale or one of the theme's tokens: display: flex is flex, gap: 1rem is gap-4, color:
 * var(--color-ink) is text-ink. Anything else, such as a calc() or a value off the scales, has none.
 */
final class Utilities
{
    private const KEYWORDS = [
        'display' => ['flex' => 'flex', 'grid' => 'grid', 'block' => 'block', 'inline-block' => 'inline-block', 'inline-flex' => 'inline-flex', 'inline-grid' => 'inline-grid', 'inline' => 'inline', 'none' => 'hidden', 'contents' => 'contents', 'flow-root' => 'flow-root'],
        'position' => ['absolute' => 'absolute', 'relative' => 'relative', 'fixed' => 'fixed', 'sticky' => 'sticky', 'static' => 'static'],
        'flex-direction' => ['row' => 'flex-row', 'column' => 'flex-col', 'row-reverse' => 'flex-row-reverse', 'column-reverse' => 'flex-col-reverse'],
        'flex-wrap' => ['wrap' => 'flex-wrap', 'nowrap' => 'flex-nowrap'],
        'flex' => ['1' => 'flex-1', '1 1 0%' => 'flex-1', 'none' => 'flex-none', 'auto' => 'flex-auto'],
        'flex-shrink' => ['0' => 'shrink-0', '1' => 'shrink'],
        'flex-grow' => ['0' => 'grow-0', '1' => 'grow'],
        'align-items' => ['center' => 'items-center', 'start' => 'items-start', 'flex-start' => 'items-start', 'end' => 'items-end', 'flex-end' => 'items-end', 'stretch' => 'items-stretch', 'baseline' => 'items-baseline'],
        'align-self' => ['center' => 'self-center', 'start' => 'self-start', 'flex-start' => 'self-start', 'end' => 'self-end', 'flex-end' => 'self-end', 'stretch' => 'self-stretch', 'auto' => 'self-auto'],
        'justify-content' => ['center' => 'justify-center', 'space-between' => 'justify-between', 'space-around' => 'justify-around', 'space-evenly' => 'justify-evenly', 'start' => 'justify-start', 'flex-start' => 'justify-start', 'end' => 'justify-end', 'flex-end' => 'justify-end'],
        'justify-items' => ['center' => 'justify-items-center', 'start' => 'justify-items-start', 'end' => 'justify-items-end', 'stretch' => 'justify-items-stretch'],
        'justify-self' => ['center' => 'justify-self-center', 'start' => 'justify-self-start', 'end' => 'justify-self-end', 'stretch' => 'justify-self-stretch'],
        'place-items' => ['center' => 'place-items-center', 'start' => 'place-items-start', 'end' => 'place-items-end', 'stretch' => 'place-items-stretch'],
        'place-content' => ['center' => 'place-content-center'],
        'overflow' => ['hidden' => 'overflow-hidden', 'auto' => 'overflow-auto', 'clip' => 'overflow-clip', 'visible' => 'overflow-visible', 'scroll' => 'overflow-scroll'],
        'overflow-x' => ['hidden' => 'overflow-x-hidden', 'auto' => 'overflow-x-auto', 'clip' => 'overflow-x-clip', 'visible' => 'overflow-x-visible', 'scroll' => 'overflow-x-scroll'],
        'overflow-y' => ['hidden' => 'overflow-y-hidden', 'auto' => 'overflow-y-auto', 'clip' => 'overflow-y-clip', 'visible' => 'overflow-y-visible', 'scroll' => 'overflow-y-scroll'],
        'text-align' => ['left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right', 'start' => 'text-start', 'end' => 'text-end'],
        'text-transform' => ['uppercase' => 'uppercase', 'lowercase' => 'lowercase', 'capitalize' => 'capitalize', 'none' => 'normal-case'],
        'white-space' => ['nowrap' => 'whitespace-nowrap', 'normal' => 'whitespace-normal', 'pre' => 'whitespace-pre', 'pre-line' => 'whitespace-pre-line'],
        'text-wrap' => ['balance' => 'text-balance', 'pretty' => 'text-pretty', 'nowrap' => 'text-nowrap'],
        'pointer-events' => ['none' => 'pointer-events-none', 'auto' => 'pointer-events-auto'],
        'cursor' => ['pointer' => 'cursor-pointer', 'default' => 'cursor-default'],
        'user-select' => ['none' => 'select-none'],
        'object-fit' => ['cover' => 'object-cover', 'contain' => 'object-contain'],
        'visibility' => ['hidden' => 'invisible', 'visible' => 'visible'],
        'isolation' => ['isolate' => 'isolate'],
        'font-style' => ['italic' => 'italic', 'normal' => 'not-italic'],
        'list-style' => ['none' => 'list-none'],
        'text-decoration' => ['none' => 'no-underline', 'underline' => 'underline'],
        'border-style' => ['solid' => 'border-solid', 'dashed' => 'border-dashed', 'none' => 'border-none'],
        'aspect-ratio' => ['1' => 'aspect-square', '1 / 1' => 'aspect-square', '16 / 9' => 'aspect-video', 'auto' => 'aspect-auto'],
        'box-shadow' => ['none' => 'shadow-none'],
        'font-weight' => ['400' => 'font-normal', 'normal' => 'font-normal', '500' => 'font-medium', '600' => 'font-semibold', '700' => 'font-bold', 'bold' => 'font-bold'],
        'line-height' => ['1' => 'leading-none'],
        'border-width' => ['0' => 'border-0', '1px' => 'border', '2px' => 'border-2', '4px' => 'border-4'],
    ];

    /** Properties on the spacing scale, with their utility. */
    private const SPACING = [
        'gap' => 'gap', 'row-gap' => 'gap-y', 'column-gap' => 'gap-x',
        'padding' => 'p', 'padding-inline' => 'px', 'padding-block' => 'py', 'padding-top' => 'pt', 'padding-right' => 'pr', 'padding-bottom' => 'pb', 'padding-left' => 'pl', 'padding-inline-start' => 'ps', 'padding-inline-end' => 'pe',
        'margin' => 'm', 'margin-inline' => 'mx', 'margin-block' => 'my', 'margin-top' => 'mt', 'margin-right' => 'mr', 'margin-bottom' => 'mb', 'margin-left' => 'ml', 'margin-inline-start' => 'ms', 'margin-inline-end' => 'me',
        'width' => 'w', 'height' => 'h', 'min-width' => 'min-w', 'min-height' => 'min-h', 'max-width' => 'max-w', 'max-height' => 'max-h',
        'inset' => 'inset', 'inset-inline' => 'inset-x', 'inset-block' => 'inset-y', 'top' => 'top', 'right' => 'right', 'bottom' => 'bottom', 'left' => 'left',
    ];

    /** Properties a theme token sets, with the utility and the token's namespace. */
    private const TOKENS = [
        'color' => ['text', 'color'], 'background-color' => ['bg', 'color'], 'background' => ['bg', 'color'], 'border-color' => ['border', 'color'],
        'fill' => ['fill', 'color'], 'stroke' => ['stroke', 'color'], 'outline-color' => ['outline', 'color'], 'text-decoration-color' => ['decoration', 'color'],
        'font-size' => ['text', 'text'], 'font-family' => ['font', 'font'], 'font-weight' => ['font', 'font-weight'], 'line-height' => ['leading', 'leading'],
        'letter-spacing' => ['tracking', 'tracking'], 'border-radius' => ['rounded', 'radius'], 'box-shadow' => ['shadow', 'shadow'],
        'transition-timing-function' => ['ease', 'ease'],
    ];

    /** The breakpoints a min-width media query names, in rem, as Tailwind sets them. */
    private const BREAKPOINTS = ['40rem' => 'sm', '48rem' => 'md', '64rem' => 'lg', '80rem' => 'xl', '96rem' => '2xl'];

    public static function for(string $property, string $value): ?string
    {
        $property = strtolower($property);
        $value = strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
        if (str_starts_with($property, '--') || str_contains($value, '!important')) {
            return null;
        }

        if (isset(self::KEYWORDS[$property][$value])) {
            return self::KEYWORDS[$property][$value];
        }

        if (isset(self::TOKENS[$property])) {
            [$utility, $namespace] = self::TOKENS[$property];
            if (preg_match('/^var\(--'.preg_quote($namespace, '/').'-([\w-]+)\)$/', $value, $m)) {
                return "{$utility}-{$m[1]}";
            }
            if ($namespace === 'color' && in_array($value, ['currentcolor', 'transparent', 'inherit'], true)) {
                return $utility.'-'.($value === 'currentcolor' ? 'current' : $value);
            }
        }

        if ($property === 'opacity' && is_numeric($value) && fmod((float) $value * 100, 1) === 0.0) {
            return 'opacity-'.(int) round((float) $value * 100);
        }
        if ($property === 'z-index' && preg_match('/^-?\d+$/', $value)) {
            return str_starts_with($value, '-') ? '-z-'.substr($value, 1) : "z-{$value}";
        }
        if ($property === 'grid-template-columns' && preg_match('/^repeat\((\d+), ?minmax\(0, ?1fr\)\)$/', $value, $m)) {
            return "grid-cols-{$m[1]}";
        }
        if ($property === 'grid-column' && $value === '1 / -1') {
            return 'col-span-full';
        }

        if (isset(self::SPACING[$property])) {
            $utility = self::SPACING[$property];
            $parts = explode(' ', $value);
            if (count($parts) === 2 && in_array($utility, ['p', 'm', 'inset'], true)) {
                // padding: 1rem 2rem is py-4 px-8
                $block = self::sized($utility === 'inset' ? 'inset-y' : $utility.'y', $parts[0]);
                $inline = self::sized($utility === 'inset' ? 'inset-x' : $utility.'x', $parts[1]);

                return $block !== null && $inline !== null ? "{$block} {$inline}" : null;
            }

            return count($parts) === 1 ? self::sized($utility, $value) : null;
        }

        return null;
    }

    /** The variant a media query is, such as lg: for (min-width: 64rem), or null when it is none of Tailwind's. */
    public static function variant(string $prelude): ?string
    {
        $prelude = strtolower(trim((string) preg_replace('/\s+/', ' ', $prelude)));
        if (preg_match('/^@media \((?:min-width: ?|width ?>= ?)([\d.]+rem)\)$/', $prelude, $m)) {
            return isset(self::BREAKPOINTS[$m[1]]) ? self::BREAKPOINTS[$m[1]].':' : null;
        }

        return match ($prelude) {
            '@media (prefers-reduced-motion: reduce)' => 'motion-reduce:',
            '@media (prefers-reduced-motion: no-preference)' => 'motion-safe:',
            default => null,
        };
    }

    /** A size on the spacing scale, a share or a keyword, as the utility's suffix. */
    private static function sized(string $utility, string $value): ?string
    {
        $words = ['auto' => 'auto', '100%' => 'full', '50%' => '1/2', 'min-content' => 'min', 'max-content' => 'max', 'fit-content' => 'fit',
            '100vw' => 'screen', '100vh' => 'screen', '100svh' => 'svh', '100dvh' => 'dvh'];
        if (isset($words[$value])) {
            return "{$utility}-{$words[$value]}";
        }
        if (preg_match('/^var\(--(?:spacing|container)-([\w-]+)\)$/', $value, $m)) {
            return "{$utility}-{$m[1]}";
        }

        $step = null;
        if (in_array($value, ['0', '0px', '0rem'], true)) {
            $step = 0.0;
        } elseif (preg_match('/^(?:calc\(var\(--spacing\) ?\* ?|--spacing\()(-?[\d.]+)\)$/', $value, $m)) {
            $step = (float) $m[1];
        } elseif (preg_match('/^(-?[\d.]+)(px|rem)$/', $value, $m)) {
            $step = $m[2] === 'px' ? (float) $m[1] / 4 : (float) $m[1] * 4;
        }
        if ($step === null || fmod(abs($step) * 4, 1) !== 0.0) {
            return null;
        }
        $number = rtrim(rtrim(sprintf('%.2f', abs($step)), '0'), '.');

        return ($step < 0 ? '-' : '')."{$utility}-{$number}";
    }
}
