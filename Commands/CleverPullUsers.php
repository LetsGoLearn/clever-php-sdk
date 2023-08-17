<?php

namespace LGL\Clever\Commands;

use Illuminate\Console\Command;

class CleverPullUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:pull:users';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[Production: Jan. 21 2023] Pulls all users from Clever and creates them in LGL.';

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
    }
}
