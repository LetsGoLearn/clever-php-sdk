<?php

namespace LGL\Clever\Commands;

use Illuminate\Console\Command;
use LGL\Clever\Api;
use LGL\Core\Accounts\Models\Client;
use LGL\Core\Models\District;
use LGL\Core\Rosters\Models\Roster;
use LGL\Core\Models\Metadata;
use Carbon\Carbon;


class CleverRosterCleaner extends Command
{
    protected Api $clever;
    protected int $lostAccess = 0;
    protected int $otherError = 0;

    protected Client $client;

    protected $cleverDistrict;

    protected District $lglDistrict;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:rosters:fixer {clientId : The ID of the client to process.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleans up Clever Rosters. Removes rosters that we no longer have access to. This command is still in use as of 2023-09-09.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Initialize timer
        $startTime = microtime(true);

        $this->warn('Starting Clever roster cleanup...');
        $this->client = Client::find($this->argument('clientId'));
        $this->clever = new Api($this->client->metadata->data['api_secret']);

        $lglDistrict = $this->getLglDistrict();
        $district = $this->clever->district($lglDistrict->metadata->data['clever_id']);
        $rosters = $this->getRosters($this->client);

        $logFile = fopen(storage_path("logs/{$this->client->id}_roster_processing.".Carbon::now()->toDateString()."_".Carbon::now()->toTimeString().".log"), 'a');


        $bar = $this->output->createProgressBar($rosters->count());

        $bar->start();

        foreach ($rosters as $roster) {

            $logData = [
                'roster_id' => $roster->id,
                'roster_title' => $roster->title,
                'roster_type' => $roster->type_id,
                'clever_id' => $roster->metadata->data['clever_id'],
                'timestamp' => Carbon::now()->toDateTimeString()
            ];

            $action = $this->processRoster($roster);

            $logData['action'] = $action;


            // Write to the log file
            fwrite($logFile, json_encode($logData) . PHP_EOL);
            $bar->advance();

        }

        $bar->finish();
        fclose($logFile);
        $this->newLine(1);

        $this->warn("Lost Access: {$this->lostAccess}");
        $this->warn("Other Errors: {$this->otherError}");
        $this->newLine(2);


        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        $this->info("Total execution time: {$elapsedTime} seconds.");
    }

    public function getRosters() {
        return Roster::where('client_id', $this->client->id)->with('metadata')
            ->whereHas('metadata', function ($query) {
                $query->whereNotNull('data->clever_id');
            })->get();
    }

    public function getLglDistrict() {
        return District::where('client_id', $this->client->id)->with('metadata')->first();
    }

    public function processRoster($entity)
    {
        $return = $this->clever->section($entity->metadata->data['clever_id']);
        if (isset($return->data['error'])) {
            // We no longer have access, remove Clever ID from metadata
            if ($return->data['error'] == 'Resource not found') {
                $entity->delete();
                $this->lostAccess++;
                return 'Lost access...';
            }
            else {
                $this->otherError++;
                return 'Not sure what happened...';
            }

        }
        return 'Nothing to do...';
    }
}


//php artisan clever:roster:fixer 3015 -vvv;
//php artisan clever:roster:fixer 1036 -vvv;
//php artisan clever:roster:fixer 1555 -vvv;
//php artisan clever:roster:fixer 2881 -vvv;
//php artisan clever:roster:fixer 2087 -vvv;
//php artisan clever:roster:fixer 1422 -vvv;
//php artisan clever:roster:fixer 2214 -vvv;
//php artisan clever:roster:fixer 2309 -vvv;
//php artisan clever:roster:fixer 709 -vvv;
//php artisan clever:roster:fixer 701 -vvv;
//php artisan clever:roster:fixer 2279 -vvv;
//php artisan clever:roster:fixer 703 -vvv;
//
//php artisan clever:users:fixer 3015 -vvv;
//php artisan clever:users:fixer 1036 -vvv;
//php artisan clever:users:fixer 1555 -vvv;
//php artisan clever:users:fixer 2881 -vvv;
//php artisan clever:users:fixer 2087 -vvv;
//php artisan clever:users:fixer 1422 -vvv;
//php artisan clever:users:fixer 2214 -vvv;
//php artisan clever:users:fixer 2309 -vvv;
//php artisan clever:users:fixer 709 -vvv;
//php artisan clever:users:fixer 701 -vvv;
//php artisan clever:users:fixer 2279 -vvv;
//php artisan clever:users:fixer 703 -vvv;
