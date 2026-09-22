<?php

namespace Avocadesign\StatamicTools\Console;

use Avocadesign\StatamicTools\Library\Item;
use Avocadesign\StatamicTools\Library\Library;
use Illuminate\Console\Command;

/** Lists what Avoca's library offers and whether this site has installed each item. */
class LibraryList extends Command
{
    protected $signature = 'avoca:library {--json : List the items as JSON}';

    protected $description = "List the blocks, sets and presets in Avoca's library, and which this site has installed.";

    public function handle(): int
    {
        $library = Library::make();
        $items = array_values($library->items());

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (Item $item) => [
                'handle' => $item->handle,
                'name' => $item->name(),
                'category' => $item->category,
                'version' => $item->version(),
                'status' => $library->status($item),
                'description' => $item->description(),
                'blocks' => array_column($item->blocks(), 'handle'),
                'sets' => array_column($item->sets(), 'handle'),
            ], $items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (! $items) {
            $this->info('The library is empty.');
            $this->line('Items go in '.implode(' or ', $library->paths()).', as {category}/{handle}/item.yaml.');

            return self::SUCCESS;
        }

        $this->table(['Handle', 'Name', 'Category', 'Version', 'Status', 'Description'], array_map(
            fn (Item $item) => [$item->handle, $item->name(), $item->category, $item->version(), $library->status($item), $item->description()],
            $items,
        ));
        $this->line('Preview an install with: php please avoca:library:install HANDLE --dry-run');

        return self::SUCCESS;
    }
}
