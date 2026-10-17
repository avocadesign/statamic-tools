<?php

namespace Avocadesign\StatamicTools\Site\Audit;

/**
 * What the checks found, and what they couldn't check and why.
 */
final class Findings
{
    /** @var array<int, array{rule: string, section: string, path: string, line: int|null, message: string}> */
    private array $findings = [];

    /** @var array<int, string> */
    private array $notes = [];

    public function add(string $rule, string $path, ?int $line, string $message): void
    {
        if (! isset(Rules::RULES[$rule])) {
            throw new \InvalidArgumentException("Unknown audit rule {$rule}");
        }
        $this->findings[] = ['rule' => $rule, 'section' => Rules::section($rule), 'path' => $path, 'line' => $line, 'message' => $message];
    }

    public function note(string $note): void
    {
        $this->notes[] = $note;
    }

    /** @return array<int, array{rule: string, section: string, path: string, line: int|null, message: string}> in the order of the rules, then by file and line */
    public function all(): array
    {
        $order = array_flip(Rules::ids());
        $findings = $this->findings;
        usort($findings, fn (array $a, array $b) => [$order[$a['rule']], $a['path'], $a['line'] ?? 0] <=> [$order[$b['rule']], $b['path'], $b['line'] ?? 0]);

        return $findings;
    }

    /** @return array<int, string> */
    public function notes(): array
    {
        return $this->notes;
    }

    /** A few names, then how many more there are. */
    public static function list(array $names, int $max = 4): string
    {
        $names = array_values(array_unique($names));

        return implode(', ', array_slice($names, 0, $max)).(count($names) > $max ? ' and '.(count($names) - $max).' more' : '');
    }

    /** "1 token", "3 tokens", "2 classes": a list of names is counted once each. */
    public static function count(array|int $items, string $noun): string
    {
        $n = is_array($items) ? count(array_unique(array_map('strval', $items))) : $items;

        return $n.' '.$noun.($n === 1 ? '' : (str_ends_with($noun, 's') ? 'es' : 's'));
    }
}
