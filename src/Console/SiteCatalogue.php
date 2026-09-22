<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Site\Blocks;
use Avocadesign\StatamicTools\Site\Catalogue;
use Illuminate\Console\Command;

/**
 * Writes the site's AI block catalogue from its fieldsets and guidance files. The file belongs to the site:
 * commit it with the change that produced it.
 */
class SiteCatalogue extends Command
{
    protected $signature = 'avoca:site:catalogue';

    protected $description = "Write this site's AI block catalogue from its fieldsets and guidance files.";

    public function handle(): int
    {
        $path = Catalogue::path();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, Catalogue::build());
        $this->info('Wrote '.Catalogue::relativePath().': '.count(Blocks::pageBuilder()).' blocks, '.count(Blocks::article()).' sets. Commit it with your change.');

        return self::SUCCESS;
    }
}
