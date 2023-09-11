<?php

namespace LGL\Clever\Commands;

use Illuminate\Console\Command;
use LGL\Auth\Users\EloquentUser;
use LGL\Core\Models\Metadata;

class CleverReport extends Command
{

    protected $clientId;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:report {clientId} {--health}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provides a report of potential data issues in the Clever database. Still in development as of 2023-09-09.';

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
        $this->clientId = $this->argument('clientId');

        $this->info('Scrubbing Clever data...');
        $this->getEmailStats();
    }

    private function getEmailStats() {
        // Get the number of users with no email address
        $noEmail = EloquentUser::where('email', '=', '')->where('client_id', $this->clientId)->count();

        // Get the number of users with a duplicate email address
        $duplicateEmail = EloquentUser::select('email')
            ->where('client_id', $this->clientId)
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        ddng($noEmail, $duplicateEmail);
    }

    private function getCleverIdStats() {
        // SELECT COUNT(*), data->>'clever_id' as clever_id FROM metadata WHERE data->>'clever_id' IS NOT NULL GROUP BY metadata.data->>'clever_id' HAVING COUNT(*) > 1;
        $metadata = Metadata::where('client_id', $this->clientId)->groupBy()->get();
    }

}