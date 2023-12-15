<?php

namespace LGL\Clever\Commands;

use Illuminate\Console\Command;
use LGL\Core\Accounts\Models\Client;

class CleverMatcherImporter extends Command
{

    protected $clientId;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:match:import {clientId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports Clever IDs from a CSV file.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //
        $this->client = Client::find($this->argument('clientId'));

    }

}