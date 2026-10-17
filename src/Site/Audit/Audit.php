<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * A static read of a site's templates, stylesheets and scripts against the recipe's rules for styling, scripts,
 * images and comments, so a port or a new block is caught before review. Nothing is rendered and nothing changes:
 * it says what it found, and the developer decides.
 */
final class Audit
{
    private const CHECKS = [TokenChecks::class, StyleChecks::class, CriticalChecks::class, ScriptChecks::class, ImageChecks::class, CommentChecks::class];

    public function __construct(private string $root)
    {
    }

    public static function make(?string $root = null): self
    {
        return new self($root ?? base_path());
    }

    /**
     * @param  array<int, string>  $paths  only report findings in these files or folders, relative to the site
     * @return array{findings: array<int, array{rule: string, section: string, path: string, line: int|null, message: string}>, ignored: array<int, array>, unused: array<int, string>, notes: array<int, string>}
     */
    public function run(array $paths = []): array
    {
        $files = new Files($this->root);
        $out = new Findings;
        foreach (self::CHECKS as $check) {
            (new $check($files, $out))->run();
        }

        $ignores = Ignores::load($files);
        $result = $ignores->apply($out->all());
        $notes = $out->notes();
        if ($ignores->problem() !== null) {
            $notes[] = $ignores->problem();
        }

        if ($paths !== []) {
            $paths = array_map(fn (string $path) => trim($path, '/'), $paths);
            $within = fn (array $finding) => (bool) array_filter($paths, fn (string $path) => $finding['path'] === $path || str_starts_with($finding['path'], $path.'/'));
            $result['findings'] = array_values(array_filter($result['findings'], $within));
            $result['ignored'] = array_values(array_filter($result['ignored'], $within));
            $result['unused'] = [];
        }

        return $result + ['notes' => $notes];
    }
}
