<?php

namespace Avocadesign\StatamicTools\Site;

use Statamic\Facades\Site;

/**
 * Where the site still shows the agency's logo instead of the client's. The starter kit points the form emails and the
 * site header at public/agencies/<AGENCY> until the client's logo arrives, and a site must not reach client sign-off
 * like that. The control panel keeps the agency's logo on purpose, so it is never mentioned. Nothing here changes a
 * file: it says what a developer has to swap.
 */
final class AgencyLogo
{
    /** The kit's partial for the logo in the site header, under resources/views. */
    public const HEADER_PARTIAL = 'components/_logo.antlers.html';

    /**
     * @param  array<string, string>  $mailLogos  the form email logo's URL, keyed by each language the site uses
     * @param  string|null  $header  the header logo partial's contents, or null when the site has no such partial
     * @return array<int, string> one line per thing to swap, or none
     */
    public static function problems(array $mailLogos, ?string $header): array
    {
        $problems = [];

        $languages = array_keys(array_filter($mailLogos, fn (string $url) => str_contains($url, '/agencies/')));
        if ($languages !== []) {
            $problems[] = 'form emails still carry the agency\'s logo ('.implode(', ', $languages).'): put the client\'s logo '
                .'as a PNG in public/visuals/ and point form_mail_logo at it in lang/<language>/strings.php';
        }

        if ($header !== null && preg_match('~/agencies/|config:agency~', $header)) {
            $problems[] = 'the site header still shows the agency\'s logo: put the client\'s SVG in resources/views/'.self::HEADER_PARTIAL;
        }

        return $problems;
    }

    /** @return array<int, string> the problems for this site, read from its languages and its header partial */
    public static function check(): array
    {
        $mailLogos = [];
        foreach (Site::all() as $site) {
            $lang = $site->lang();
            $mailLogos[$lang] = (string) trans('strings.form_mail_logo', [], $lang);
        }

        $partial = resource_path('views/'.self::HEADER_PARTIAL);

        return self::problems($mailLogos, is_file($partial) ? (string) file_get_contents($partial) : null);
    }
}
