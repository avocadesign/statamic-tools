<?php

namespace Avocadesign\StatamicTools\Ai;

use Illuminate\Support\Facades\Process;

/**
 * Claude Code run headless (claude -p) on a brief, allowed only to read the site, edit named files and run named
 * commands. Commands start it only after a person has confirmed.
 */
final class ClaudeCode
{
    public static function available(): bool
    {
        $result = Process::run('command -v claude');

        return $result->successful() && trim($result->output()) !== '';
    }

    /**
     * --restricted ignores the user, project and local settings files, so no allow rule in them can widen the run, and
     * keeps the file tools inside the site. --tools leaves only the tools the brief needs. --permission-mode dontAsk
     * denies anything --allowedTools does not name, instead of waiting for an answer nobody is there to give.
     * --strict-mcp-config with no MCP config loads no MCP servers.
     *
     * @param  array<int, string>  $editable  paths from the site root that it may change
     * @param  array<int, string>  $commands  exact commands it may run
     * @return array<int, string>
     */
    public static function command(string $prompt, array $editable, array $commands): array
    {
        return [
            'claude', '-p', $prompt,
            '--restricted',
            '--tools', 'Read,Glob,Grep,Edit,Write,Bash',
            '--permission-mode', 'dontAsk',
            '--allowedTools', 'Read', 'Glob', 'Grep',
            ...array_map(fn (string $path) => "Edit({$path})", $editable),
            ...array_map(fn (string $command) => "Bash({$command})", $commands),
            '--strict-mcp-config',
        ];
    }

    /** The command as a person would type it, to show before it runs. */
    public static function display(array $command): string
    {
        return implode(' ', array_map(
            fn (string $part) => preg_match('#^[A-Za-z0-9_/.:,=-]+$#', $part) ? $part : escapeshellarg($part),
            $command,
        ));
    }

    /** Runs it from the site root, passing its output on as it arrives. Returns the exit code. */
    public static function run(array $command, string $root, callable $output, int $timeout = 1800): int
    {
        return Process::path($root)
            ->timeout($timeout)
            ->run($command, fn (string $type, string $buffer) => $output($buffer))
            ->exitCode() ?? 1;
    }
}
