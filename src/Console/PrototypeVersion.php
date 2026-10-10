<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Prototype\Prototype;
use Illuminate\Console\Command;

/**
 * Starts a new version of the prototype as a copy of the newest one, leaving the earlier versions as they were: a
 * version shared with a client never changes. The new folder gets the newest version's data.js and pages.js and a
 * version.json dated today, with an empty list of notes to fill in with what changed.
 */
class PrototypeVersion extends Command
{
    protected $signature = 'avoca:prototype:version
        {version : The new version\'s name, such as 2, or B for an alternative}
        {--label= : What the version menu calls it, such as "Option B"; "Version <name>" when left out}';

    protected $description = 'Start a new version of the prototype as a copy of the newest one.';

    public function handle(): int
    {
        $new = (string) $this->argument('version');
        if (! preg_match('/^[A-Za-z0-9-]+$/', $new) || in_array($new, ['sign-out', 'assets'], true)) {
            $this->error("{$new} can't name a version: use letters, numbers and hyphens, as in 2 or B.");

            return self::FAILURE;
        }

        $folder = Prototype::folder();
        if (is_dir("{$folder}/{$new}")) {
            $this->error("Version {$new} already exists.");

            return self::FAILURE;
        }

        $current = array_filter(Prototype::versions(), fn ($v) => ! $v['legacy']);
        if ($current === []) {
            $this->error("There is no version to copy in {$folder}: a version needs data.js and pages.js.");

            return self::FAILURE;
        }
        $latest = array_key_last($current);

        mkdir("{$folder}/{$new}", 0755, true);
        copy("{$folder}/{$latest}/data.js", "{$folder}/{$new}/data.js");
        copy("{$folder}/{$latest}/pages.js", "{$folder}/{$new}/pages.js");
        $about = [
            'format' => Prototype::FORMAT,
            'label' => $this->option('label') ?: "Version {$new}",
            'date' => now()->toDateString(),
            'notes' => [],
        ];
        file_put_contents("{$folder}/{$new}/version.json", json_encode($about, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->info("Version {$new} is a copy of version {$latest}. Edit its data.js and pages.js, and list what changed under notes in its version.json.");

        return self::SUCCESS;
    }
}
