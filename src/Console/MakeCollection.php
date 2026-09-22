<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Ai\ClaudeCode;
use Avocadesign\StatamicTools\Collections\CollectionMaker;
use Avocadesign\StatamicTools\Collections\CollectionRecord;
use Avocadesign\StatamicTools\Collections\CollectionSpec;
use Avocadesign\StatamicTools\Library\Names;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;

/**
 * Makes a collection: its settings, a page builder or custom blueprint, an optional listing block in the Dynamic group
 * (the blocks that show content from elsewhere), editor access unless it is opted out and a record in resources/site/collections. Every question has an option, so an AI agent
 * can run it without prompts. A custom blueprint starts minimal: the record holds the brief an AI agent builds it from.
 */
class MakeCollection extends Command
{
    protected $signature = 'avoca:make:collection
        {--title= : What the collection is called, such as People}
        {--handle= : Its handle, suggested from the title}
        {--no-route : Entries have no pages of their own and appear only in blocks}
        {--dated : Entries are dated}
        {--ordered : Editors put entries in order by hand}
        {--blueprint= : How an entry is built: page_builder, or custom for fields you describe}
        {--description= : For a custom blueprint, the fields each entry has, in plain words}
        {--no-block : Add no block that lists the entries}
        {--ignore-library : Make it even when the Avoca library already has something with this handle}
        {--no-catalogue : Leave the catalogue to regenerate later}
        {--dry-run : Show every file and fieldset change without writing anything}
        {--json : Report as JSON, asking nothing}
        {--ai : After you confirm, run Claude Code headless to finish the collection}';

    protected $description = 'Make a collection, built with the page builder or from fields you describe for an AI agent to build.';

    /**
     * Added here because the signature cannot hold them: a description with braces, and an option with both a plain and
     * a --no- form.
     */
    protected function configure(): void
    {
        $this->addOption('route', null, InputOption::VALUE_REQUIRED, 'The URL of an entry, such as /people/{slug}');
        $this->addOption('editor-access', null, InputOption::VALUE_NEGATABLE, 'Editors get access by default. --no-editor-access keeps the collection away from them and records it');
    }

    /** What Avoca's library has with this collection's handle, or a similar name, for the report. */
    private array $library = [];

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $ask = $this->canAsk();
        [$spec, $problems] = $this->answers($ask);
        if ($problems !== []) {
            return $this->stop($json, $spec, [], [], $problems, 'Nothing was made:');
        }

        // The recipe installs from Avoca's library before building its own. A library item with this handle would collide
        // with the new collection when installed later, so the make stops unless --ignore-library says the clash is meant.
        $this->library = (app()->bound(Names::class) ? app(Names::class) : Names::make())->check($spec->handle, $spec->title);
        $clashes = Names::blocking($this->library);
        if ($clashes !== [] && ! $this->option('ignore-library')) {
            return $this->stop($json, $spec, [], [], [...array_map(Names::describe(...), $clashes), 'To make this collection anyway, choose another handle with --handle, or pass --ignore-library.'], 'Nothing was made:');
        }

        $maker = app()->bound(CollectionMaker::class) ? app(CollectionMaker::class) : CollectionMaker::make();
        $date = now()->format('Y-m-d');
        $plan = $maker->plan($spec);
        $blocking = CollectionMaker::blocking($plan);
        $paths = $maker->paths($spec);
        $catalogue = $spec->block && ! $this->option('no-catalogue') ? $maker->site()['catalogue_path'] : null;
        $dry = (bool) $this->option('dry-run');
        $ai = ClaudeCode::command($this->prompt($spec, $paths['record']), $maker->editable($spec), CollectionRecord::CHECK_COMMANDS);

        if (! $json) {
            $this->info("{$spec->title} ({$spec->handle})");
            $this->line('  '.$this->summary($spec));
            foreach ($this->library as $match) {
                $this->line('  <fg=yellow>!</> '.Names::describe($match));
            }
            foreach ($plan as $step) {
                $this->line('  '.$this->stepLine($step));
            }
            if ($catalogue !== null) {
                $this->line("  <fg=green>+</> regenerate {$catalogue}");
            }
        }

        if ($blocking !== []) {
            $problems = array_map(CollectionMaker::problem(...), $blocking);

            return $this->stop($json, $spec, $paths, $plan, $problems, $dry ? 'Dry run: nothing was written. The real run would stop before writing anything, because:' : 'Nothing was written, because:', $dry);
        }

        if ($dry) {
            if ($json) {
                $this->report($spec, $paths, $plan, dry: true, written: false, catalogue: $catalogue);

                return self::SUCCESS;
            }
            if ($this->output->isVerbose()) {
                foreach ($maker->files($spec, $date) as $path => $contents) {
                    $this->newLine();
                    $this->line("<comment>{$path}</comment>");
                    $this->line(rtrim($contents));
                }
            }
            $this->newLine();
            $this->line('Dry run: nothing was written.'.($this->output->isVerbose() ? '' : ' Add -v to see the contents of each file.'));
            if ($this->option('ai') && $spec->needsFinishing()) {
                $this->line('With --ai, once you confirm, it would run:');
                $this->line('  '.ClaudeCode::display($ai));
            }

            return self::SUCCESS;
        }

        try {
            $maker->write($spec, $date);
        } catch (\RuntimeException $e) {
            return $this->stop($json, $spec, $paths, $plan, explode("\n", $e->getMessage()), 'Nothing was written, because:');
        }
        // This process loaded Statamic's collections before the new files existed, and anything that reads them here
        // can cache a broken index. So the Stache refresh, and then the catalogue, both run in fresh PHP processes.
        if (($failure = \Avocadesign\StatamicTools\Site\StacheRefresh::run()) !== null && ! $json) {
            $this->warn("Could not refresh the Stache. Run php please stache:refresh.\n{$failure}");
        }
        if ($catalogue !== null) {
            [$ok, $output] = \Avocadesign\StatamicTools\Site\StacheRefresh::artisan(['avoca:site:catalogue']);
            if (! $json) {
                $ok ? $this->line($output) : $this->warn("Could not regenerate the catalogue. Run php please avoca:site:catalogue.\n{$output}");
            }
        }


        if ($json) {
            $this->report($spec, $paths, $plan, dry: false, written: true, catalogue: $catalogue);

            return self::SUCCESS;
        }
        $this->newLine();
        $this->info("Made the {$spec->title} collection. Its record is {$paths['record']}.");
        $this->line($this->nextStep($spec, $paths['record']));
        $this->line('See it in the control panel at /'.trim((string) config('statamic.cp.route', 'cp'), '/')."/collections/{$spec->handle}.");
        if ($this->option('ai')) {
            $this->handOff($spec, $ask, $ai, $maker->root());
        }

        return self::SUCCESS;
    }

    /** Prompts need a person at a terminal. Tests answer through Laravel's fallback questions. */
    private function canAsk(): bool
    {
        return ! $this->option('json') && $this->input->isInteractive()
            && ($this->laravel->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN)));
    }

    /** @return array{0: ?CollectionSpec, 1: array<int, string>} */
    private function answers(bool $ask): array
    {
        $title = trim((string) $this->option('title'));
        if ($title === '' && $ask) {
            $title = trim(text(label: 'What is the collection called?', placeholder: 'E.g. People', required: true, hint: 'Editors see this name in the control panel.'));
        }
        if ($title === '') {
            return [null, ['Give the collection a title with --title, or run the command in a terminal to be asked.']];
        }

        $handle = (string) $this->option('handle');
        if ($handle === '') {
            $handle = $ask
                ? text(label: 'What is its handle?', default: CollectionSpec::suggestHandle($title), required: true, validate: fn (string $value) => CollectionSpec::handleProblem($value), hint: 'Used in file names, templates and permissions.')
                : CollectionSpec::suggestHandle($title);
        }

        $problems = [];
        $route = $this->option('route');
        if ($route !== null && $this->option('no-route')) {
            $problems[] = 'Use --route or --no-route, not both.';
        }
        if ($this->option('no-route')) {
            $route = null;
        } elseif ($route === null) {
            $routed = ! $ask || confirm(label: 'Does each entry get its own page, with its own URL?', default: true, yes: 'Yes, its own page', no: 'No, it only appears in blocks', hint: 'Choose no for things like testimonials that only show inside other pages.');
            $suggested = CollectionSpec::suggestRoute($handle);
            $route = ! $routed ? null : ($ask
                ? text(label: 'What is the URL of an entry?', default: $suggested, required: true, validate: fn (string $value) => CollectionSpec::routeProblem($value), hint: "{slug} is replaced by each entry's slug.")
                : $suggested);
        }

        $dated = $this->option('dated') || ($ask && confirm(label: 'Are entries dated?', default: false, hint: 'Like news posts or events, where each entry has a date.'));
        $ordered = $this->option('ordered') || ($ask && confirm(label: 'Do editors put entries in order by hand?', default: false, hint: 'They drag entries into order in the control panel.'));

        $blueprint = $this->option('blueprint');
        $blueprint = $blueprint !== null
            ? CollectionSpec::blueprintType((string) $blueprint)
            : ($ask ? (string) select(label: 'How is an entry built?', options: [
                CollectionSpec::PAGE_BUILDER => 'With the page builder, from the same blocks as pages',
                CollectionSpec::CUSTOM => 'With its own fields, which you describe for an AI agent to build',
            ], default: CollectionSpec::PAGE_BUILDER) : CollectionSpec::PAGE_BUILDER);

        $description = trim((string) $this->option('description'));
        if ($blueprint === CollectionSpec::CUSTOM && $description === '' && $ask) {
            $description = trim(textarea(label: 'Describe the fields each entry has.', placeholder: 'E.g. Each person has a photo, name, role, a short bio, an email and a LinkedIn link.', required: true, hint: 'Plain words are fine. They become the brief an AI agent builds the blueprint from.'));
        }

        $block = ! $this->option('no-block') && (! $ask || confirm(label: 'Add a block that lists these entries?', default: true, hint: "It goes in the page builder's Dynamic group, with the other blocks that show content from elsewhere."));

        // Editors get access without being asked. --no-editor-access opts the collection out, and its record remembers it.
        $editorAccess = $this->option('editor-access') !== false;

        $spec = new CollectionSpec($title, $handle, $route === null ? null : (string) $route, $dated, $ordered, $blueprint, $description, $block, $editorAccess);

        return [$spec, [...$problems, ...$spec->problems()]];
    }

    private function summary(CollectionSpec $spec): string
    {
        return implode(' ', [
            $spec->routed() ? "Each entry has its own page at {$spec->route}." : 'Entries appear only in blocks.',
            $spec->dated ? 'Dated.' : 'Not dated.',
            ['manual' => 'Ordered by hand.', 'date' => 'Newest first.', 'title' => 'Listed by title.'][$spec->ordering()],
            $spec->custom() ? 'Built from its own fields.' : 'Built with the page builder.',
            $spec->block ? 'A block lists the entries.' : 'No listing block.',
            $spec->editorAccess ? 'Editors have access.' : 'Editors have no access.',
        ]);
    }

    private function stepLine(array $step): string
    {
        $blocking = CollectionMaker::blocking([$step]) !== [];
        $mark = match (true) {
            $step['status'] === 'error' => '<fg=red>✗</>',
            $blocking => '<fg=yellow>!</>',
            $step['status'] === 'same' => '<fg=gray>=</>',
            $step['status'] === 'skipped' => '<fg=gray>-</>',
            default => '<fg=green>+</>',
        };
        $text = match (true) {
            $blocking => CollectionMaker::problem($step),
            $step['action'] === 'create' => "create {$step['target']}",
            $step['action'] === 'block' => "add the {$step['target']} block to the page builder: {$step['detail']}",
            $step['action'] === 'permissions' && $step['status'] === 'same' => "the {$step['target']} role already has every permission",
            $step['action'] === 'permissions' && $step['status'] === 'skipped' => "no permissions for the {$step['target']} role, because {$step['detail']}",
            $step['action'] === 'permissions' => "give the {$step['target']} role: {$step['detail']}",
            default => "{$step['action']} {$step['target']}",
        };

        return "{$mark} {$text}";
    }

    private function nextStep(CollectionSpec $spec, string $record): string
    {
        return match (true) {
            $spec->custom() => "Next, ask your AI agent: build the blueprint described in {$record}",
            $spec->block => "Next, ask your AI agent: finish the listing block described in {$record}",
            default => 'Nothing is left to build. Add entries in the control panel.',
        };
    }

    private function prompt(CollectionSpec $spec, string $record): string
    {
        [$catalogue, $check] = CollectionRecord::CHECK_COMMANDS;

        return "Read {$record} and finish the {$spec->title} collection it describes, following its Notes for AI. Change only the files it lists under Files. When you are done, run `{$catalogue}` and then `{$check}`, exactly as written, and fix anything they report. Do not commit. End with a short summary of what you changed and anything you could not finish.";
    }

    private function handOff(CollectionSpec $spec, bool $ask, array $command, string $root): void
    {
        $this->newLine();
        if (! $spec->needsFinishing()) {
            $this->line('Claude Code was not run: there is nothing for it to finish.');

            return;
        }
        if (! ClaudeCode::available()) {
            $this->warn('Claude Code was not run: `command -v claude` found no claude on the PATH.');

            return;
        }
        if (! $ask) {
            $this->warn('Claude Code was not run: --ai needs you to confirm, and this run cannot ask.');

            return;
        }
        $this->line('This runs:');
        $this->line('  '.ClaudeCode::display($command));
        if (! confirm(label: 'Run Claude Code now to finish the collection?', default: false, hint: 'It can read the site, change only the files the record lists and run the two site checks. It does not commit.')) {
            $this->line('Claude Code was not run.');

            return;
        }
        $this->line('Claude Code is working. This can take a few minutes.');
        $code = ClaudeCode::run($command, $root, fn (string $buffer) => $this->output->write($buffer));
        $this->newLine();
        $code === 0
            ? $this->info('Claude Code finished. Review its changes before you commit them.')
            : $this->error("Claude Code stopped with exit code {$code}. If it did not recognise an option, update it with: claude update");
    }

    /** @param  array<int, string>  $problems */
    private function stop(bool $json, ?CollectionSpec $spec, array $paths, array $plan, array $problems, string $heading, bool $dry = false): int
    {
        if ($json) {
            $this->report($spec, $paths, $plan, dry: $dry, written: false, catalogue: null, errors: $problems);
        } else {
            $this->newLine();
            $this->error($heading);
            foreach ($problems as $problem) {
                $this->line("  {$problem}");
            }
            $this->line('Run php please avoca:make:collection --help to see every option.');
        }

        return self::FAILURE;
    }

    /** @param  array<int, string>  $errors */
    private function report(?CollectionSpec $spec, array $paths, array $plan, bool $dry, bool $written, ?string $catalogue, array $errors = []): void
    {
        $this->line((string) json_encode([
            'ok' => $errors === [],
            'dry_run' => $dry,
            'written' => $written,
            'collection' => $spec ? CollectionRecord::frontMatter($spec) : null,
            'record' => $paths['record'] ?? null,
            'files' => array_values($paths),
            'steps' => $plan,
            'library' => $this->library,
            'catalogue' => $catalogue,
            'next' => $spec && $errors === [] ? $this->nextStep($spec, $paths['record']) : null,
            'errors' => $errors,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
