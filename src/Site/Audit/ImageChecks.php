<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * A bare <img> in a template. Not an SVG, an inline data image, an image on another site, one a script chooses
 * (:src), one inside <noscript>, an email's, or the img inside the figure and picture partials themselves.
 */
final class ImageChecks
{
    public function __construct(private Files $files, private Findings $out)
    {
    }

    public function run(): void
    {
        foreach ($this->files->templates() as $path => $html) {
            if (Markup::isEmail($path) || preg_match('~/_(figure|picture)[^/]*\.antlers\.html$~', $path)) {
                continue;
            }
            $code = Markup::withoutComments($html);
            preg_match_all('~<noscript\b.*?</noscript>~is', $code, $noscripts, PREG_OFFSET_CAPTURE);
            preg_match_all('~<img\b[^>]*>~is', $code, $images, PREG_OFFSET_CAPTURE);

            foreach ($images[0] as [$tag, $offset]) {
                if (preg_match('/(?<![\w-])(?::|x-bind:)src\s*=/', $tag)) {
                    continue;
                }
                preg_match('/(?<![\w:-])src\s*=\s*"((?:\{\{.*?\}\}|[^"])*)"|(?<![\w:-])src\s*=\s*\'([^\']*)\'/s', $tag, $src);
                $url = trim(($src[1] ?? '') !== '' ? $src[1] : ($src[2] ?? ''));
                if (preg_match('~^(data:|https?://|//)|\.svg(\?|$)~i', $url)) {
                    continue;
                }
                foreach ($noscripts[0] as [$noscript, $at]) {
                    if ($offset > $at && $offset < $at + strlen($noscript)) {
                        continue 2;
                    }
                }
                $this->out->add('bare-img', $path, Files::line($html, $offset), '<img src="'.($url === '' ? '…' : $url).'"> has no srcset or sizes: use the figure or picture partial');
            }
        }
    }
}
