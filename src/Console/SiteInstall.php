<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Site\Samples;
use Illuminate\Console\Command;

class SiteInstall extends Command
{
    protected $signature = 'avoca:site:install';

    protected $description = 'Publish the placeholder images the /site pages render with.';

    public function handle(): int
    {
        foreach (['landscape', 'portrait', 'square'] as $orientation) {
            $this->line('  images/'.Samples::placeholder($orientation));
        }
        $this->info('Placeholder images are in place. Visit /'.config('statamic-tools.site.prefix').'/style');

        return self::SUCCESS;
    }
}
