<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Site\Audit\Audit;
use Avocadesign\StatamicTools\Site\Audit\Ignores;
use Avocadesign\StatamicTools\Site\Audit\Rules;
use Illuminate\Console\Command;

/**
 * Reads the site's templates, CSS and scripts against the recipe's rules for tokens, styling, critical CSS, scripts,
 * images and comments, and lists what breaks them with the file, the line, the rule and its recipe section. It is
 * advisory: it exits 0 whatever it finds, unless --strict. A finding the site keeps on purpose goes in
 * resources/site/audit-ignore.yaml with its reason.
 */
class SiteAudit extends Command
{
    /** How many findings of one rule are listed before the rest are counted. */
    private const SHOWN = 8;

    protected $signature = 'avoca:site:audit
        {paths?* : Only report findings in these files or folders}
        {--strict : Fail when anything is found}
        {--all : List every finding, not the first few of each rule}
        {--json : Report as JSON}
        {--root= : Audit the site in this folder instead of this one}';

    protected $description = "Check the site's templates, CSS and scripts against the recipe's styling, scripts, images and comments rules.";

    public function handle(): int
    {
        $root = $this->option('root') === null
            ? base_path()
            : (realpath((string) preg_replace('~^\~(?=/|$)~', (string) getenv('HOME'), (string) $this->option('root'))) ?: (string) $this->option('root'));
        if (! is_dir($root.'/resources')) {
            $this->error("{$root} has no resources folder to audit.");

            return self::FAILURE;
        }

        $result = Audit::make($root)->run((array) $this->argument('paths'));
        $findings = $result['findings'];
        $failed = $this->option('strict') && $findings !== [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'findings' => array_map(fn (array $f) => $f + ['rule_text' => Rules::text($f['rule']), 'recipe' => 'recipe.md#'.Rules::anchor($f['rule'])], $findings),
                'ignored' => $result['ignored'],
                'unused_ignores' => $result['unused'],
                'notes' => $result['notes'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $byRule = [];
        foreach ($findings as $finding) {
            $byRule[$finding['rule']][] = $finding;
        }
        $section = null;
        foreach ($byRule as $rule => $list) {
            if (Rules::section($rule) !== $section) {
                $section = Rules::section($rule);
                $this->newLine();
                $this->line("<options=bold>{$section}</> <fg=gray>recipe.md#".Rules::anchor($rule).'</>');
            }
            $this->line('  '.Rules::text($rule)." <fg=gray>[{$rule}]</>");
            $shown = $this->option('all') ? $list : array_slice($list, 0, self::SHOWN);
            foreach ($shown as $finding) {
                $where = $finding['path'].($finding['line'] ? ":{$finding['line']}" : '');
                $this->line("    <fg=yellow>!</> {$where} {$finding['message']}");
            }
            if (count($list) > count($shown)) {
                $this->line('    <fg=gray>… and '.(count($list) - count($shown)).' more: --all lists them</>');
            }
        }

        foreach ([...$result['unused'], ...$result['notes']] as $note) {
            $this->line("  <fg=gray>-</> {$note}");
        }

        $this->newLine();
        $ignored = count($result['ignored']) ? ', '.count($result['ignored']).' kept on purpose in '.Ignores::PATH : '';
        if ($findings === []) {
            $this->info("Nothing to report: the templates, CSS and scripts follow the recipe's rules{$ignored}.");
        } else {
            $summary = count($findings).' '.(count($findings) === 1 ? 'finding' : 'findings').' against '.count($byRule).' '.(count($byRule) === 1 ? 'rule' : 'rules').$ignored.'.';
            $failed ? $this->error($summary) : $this->warn($summary.' Advisory: keep one on purpose in '.Ignores::PATH.' with its reason.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
