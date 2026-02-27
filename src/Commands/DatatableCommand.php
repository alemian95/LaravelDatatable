<?php

namespace AleMian95\Datatable\Commands;

use Illuminate\Console\Command;

class DatatableCommand extends Command
{
    public $signature = 'laraveldatatable';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
