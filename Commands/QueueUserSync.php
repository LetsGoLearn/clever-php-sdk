<?php

namespace LGL\Clever\Commands;

use Illuminate\Console\Command;
use Artisan;

class QueueUserSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:queue:user:sync 
    {clientId : Client\'s LGL ID to sync} 
    {type : The clever type} 
    {cleverId : User\'s clever id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        Artisan::queue('clever:userSync', ['clientId' => $this->argument('clientId'), 'type' => $this->argument('type'), 'cleverId' => $this->argument('cleverId')]);
        $this->info('Queued: '.$this->argument('cleverId').' ');
    }
}
