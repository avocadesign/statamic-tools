<?php

namespace Avocadesign\StatamicTools\Site\Audit;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The findings a site has decided to keep, in resources/site/audit-ignore.yaml, so a deliberate exception is
 * recorded once with its reason rather than raised on every run. An entry names the rule, the file (or a glob, or a
 * folder ending in /) and, optionally, words the finding's message contains, and says why. One without a reason
 * silences nothing.
 */
final class Ignores
{
    public const PATH = 'resources/site/audit-ignore.yaml';

    /** @param array<int, array{rule: string, path: string, match: string|null, reason: string|null, index: int}> $entries */
    private function __construct(private array $entries, private ?string $problem)
    {
    }

    public static function load(Files $files): self
    {
        $yaml = $files->get(self::PATH);
        if ($yaml === null) {
            return new self([], null);
        }

        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $e) {
            return new self([], self::PATH.' could not be read: '.$e->getMessage());
        }

        $entries = [];
        foreach (is_array($parsed) ? array_values($parsed) : [] as $index => $entry) {
            if (is_array($entry) && is_string($entry['rule'] ?? null) && is_string($entry['path'] ?? null)) {
                $entries[] = [
                    'rule' => $entry['rule'],
                    'path' => $entry['path'],
                    'match' => is_scalar($entry['match'] ?? null) ? (string) $entry['match'] : null,
                    'reason' => is_string($entry['reason'] ?? null) && trim($entry['reason']) !== '' ? trim($entry['reason']) : null,
                    'index' => $index + 1,
                ];
            }
        }

        return new self($entries, null);
    }

    /**
     * The findings split into those still to report and those the site keeps, with the entries that matched nothing.
     *
     * @return array{findings: array<int, array>, ignored: array<int, array>, unused: array<int, string>}
     */
    public function apply(array $findings): array
    {
        $kept = [];
        $ignored = [];
        $used = [];
        foreach ($findings as $finding) {
            foreach ($this->entries as $entry) {
                if (! self::matches($entry, $finding)) {
                    continue;
                }
                $used[$entry['index']] = true;
                if ($entry['reason'] !== null) {
                    $ignored[] = $finding + ['reason' => $entry['reason']];

                    continue 2;
                }
            }
            $kept[] = $finding;
        }

        foreach ($this->entries as $entry) {
            if ($entry['reason'] === null) {
                $kept[] = ['rule' => 'ignore-reason', 'section' => Rules::section('ignore-reason'), 'path' => self::PATH, 'line' => null,
                    'message' => "entry {$entry['index']} ({$entry['rule']} in {$entry['path']}) has no reason, so it silences nothing"];
            }
        }

        $unused = [];
        foreach ($this->entries as $entry) {
            if (! isset($used[$entry['index']])) {
                $unused[] = "entry {$entry['index']} in ".self::PATH." ({$entry['rule']} in {$entry['path']}) matches nothing now: remove it";
            }
        }

        return ['findings' => $kept, 'ignored' => $ignored, 'unused' => $unused];
    }

    public function problem(): ?string
    {
        return $this->problem;
    }

    private static function matches(array $entry, array $finding): bool
    {
        if ($entry['rule'] !== '*' && $entry['rule'] !== $finding['rule']) {
            return false;
        }
        $path = $entry['path'];
        $inPath = $path === $finding['path']
            || (str_ends_with($path, '/') && str_starts_with($finding['path'], $path))
            || fnmatch($path, $finding['path'], FNM_PATHNAME);

        return $inPath && ($entry['match'] === null || str_contains($finding['message'], $entry['match']));
    }
}
