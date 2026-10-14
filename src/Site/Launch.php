<?php

namespace Avocadesign\StatamicTools\Site;

use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Statamic\Facades\YAML;

/**
 * Whether a launched site still lets the add-on move to a new release line. At launch a site narrows the add-on's
 * constraint to the line it launched on, so later releases in that line still arrive and a new line is a decision. A
 * site marks its launch with `launched:` in resources/site/updates.yaml. Nothing here changes a file: it says what a
 * developer has to change.
 */
final class Launch
{
    public const PACKAGE = 'avocadesign/statamic-tools';

    /**
     * @param  mixed  $launched  the `launched:` value from resources/site/updates.yaml, or null when there is none
     * @param  string|null  $constraint  what the site's composer.json asks for, or null when it doesn't require the add-on
     * @param  string|null  $installed  the release the site has, such as v0.1.48, or null when it can't be told
     * @return array<int, string> one line when the constraint reaches past the installed release's line, or none
     */
    public static function problems(mixed $launched, ?string $constraint, ?string $installed): array
    {
        if (empty($launched) || $constraint === null || $installed === null) {
            return [];
        }

        // A release of its own, such as v0.1.48. A branch or a local folder, as Avoca's sandbox uses, has no line.
        if (! preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', $installed, $m)) {
            return [];
        }
        [$major, $minor] = [(int) $m[1], (int) $m[2]];

        // Under 1.0 the middle number marks a new line, from 1.0 the first one does.
        $next = $major === 0 ? '0.'.($minor + 1).'.0' : ($major + 1).'.0.0';
        $line = $major === 0 ? "0.{$minor}" : "{$major}.x";

        try {
            if (! Semver::satisfies($next, $constraint)) {
                return [];
            }
        } catch (\UnexpectedValueException) {
            return [];
        }

        $version = ltrim($installed, 'v');

        return ["the site is launched but composer.json lets the add-on move past {$line} ({$constraint}): ask for ^{$version}, "
            .'so later releases in this line still arrive and a new line is a decision'];
    }

    /** @return array<int, string> the problems for this site, read from its updates.yaml, composer.json and installed release */
    public static function check(): array
    {
        $updates = base_path('resources/site/updates.yaml');
        $launched = is_file($updates) ? ((array) YAML::file($updates)->parse())['launched'] ?? null : null;

        $composer = base_path('composer.json');
        $require = is_file($composer) ? (json_decode((string) file_get_contents($composer), true)['require'] ?? []) : [];
        $constraint = is_string($require[self::PACKAGE] ?? null) ? $require[self::PACKAGE] : null;

        $installed = InstalledVersions::isInstalled(self::PACKAGE) ? InstalledVersions::getPrettyVersion(self::PACKAGE) : null;

        return self::problems($launched, $constraint, $installed);
    }
}
