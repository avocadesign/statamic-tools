<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Library\SampleImages;
use Illuminate\Console\Command;

class SiteInstall extends Command
{
    protected $signature = 'avoca:site:install';

    protected $description = 'Say which images the /site pages will render with.';

    public function handle(): int
    {
        $prefix = config('statamic-tools.site.prefix');
        $images = app(SampleImages::class)->choices();

        if ($images === []) {
            $this->warn('The images container holds no image the reference pages can use.');
            $this->line('  They need one image at least '.SampleImages::MIN_LONG_SIDE.' pixels on its long side, or any');
            $this->line('  image with "placeholder" in its file name. Blocks with an image will preview without one.');

            return self::SUCCESS;
        }

        $this->info('The reference pages will use these, in this order:');
        foreach (array_slice($images, 0, 6) as $path) {
            $this->line("  images/{$path}");
        }
        if (count($images) > 6) {
            $this->line('  and '.(count($images) - 6).' more.');
        }
        $this->line("Visit /{$prefix}/style and /{$prefix}/content.");

        return self::SUCCESS;
    }
}
