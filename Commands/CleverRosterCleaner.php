<?php

namespace LGL\Clever\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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
        $this->info('Client ID: ' . $this->argument('clientId'));
        $this->client = Client::find($this->argument('clientId'));
        $this->clever = new Api($this->client->metadata->data['api_secret']);

        $lglDistrict = $this->getLglDistrict();
        $district = $this->clever->district($lglDistrict->metadata->data['clever_id']);
        $rosters = $this->getRosters();

        $bar = $this->output->createProgressBar($rosters->count());

        $bar->start();

        foreach ($rosters as $roster) {

            $this->processRoster($roster);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(1);



        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        $this->info("Total execution time: {$elapsedTime} seconds.");
    }

    public function getRosters() {
        return Roster::withTrashed()->where('client_id', $this->client->id)->with('metadata')
            ->whereHas('metadata', function ($query) {
                $query->whereNotNull('data->clever_id');
            })->get();
    }

    public function getLglDistrict() {
        return District::where('client_id', $this->client->id)->with('metadata')->first();
    }

    public function processRoster($roster)
    {

        $return = $this->clever->section($roster->metadata->data['clever_id']);
        if (isset($return->data['error'])) {

            $logData = [
                'roster_id' => $roster->id,
                'roster_title' => $roster->title,
                'roster_type' => $roster->type_id,
                'clever_id' => $roster->metadata->data['clever_id'],
                'timestamp' => Carbon::now()->toDateTimeString()
            ];

            if ($return->data['error'] == 'Resource not found') {
                $data = $roster->metadata->data;
                $data['old_clever_id'] = $data['clever_id'];
                unset($data['clever_id']);
                $roster->setMetadata($data);
                $roster->delete();
            }
            else {
                $logData['softDelete'] = false;
                $logData['description'] = 'No clue, go figure it out';
            }
            Log::info('['.Carbon::now()->toDateTimeString().'][CleverIdUserCleaner] '. json_encode($logData));
        }
    }
}