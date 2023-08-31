<?php

namespace LGL\Clever\Commands;

use Illuminate\Console\Command;
use LGL\Core\Models\Metadata;
use LGL\Core\Rosters\Models\Roster;

class CleverIdCleaner extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:id:fixer';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This works with duplicate Clever IDs for Rosters Only';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Gather Metadata records that have duplicate Clever IDs data->clever_id
        // Step 1: Fetch clever_ids that appear more than once
        $this->info("Fetching duplicate Clever IDs...");
        $duplicateCleverIds = Metadata::selectRaw("data->>'clever_id' as clever_id")->where('metable_type', 'LGL\Core\Rosters\Models\Roster')
            ->groupBy(\DB::raw("data->>'clever_id'"))
            ->havingRaw("COUNT(*) > 1")
            ->pluck('clever_id')
            ->toArray();

        $duplicateCleverIds = array_filter($duplicateCleverIds);

        $this->info("Found " . count($duplicateCleverIds) . " duplicate Clever IDs.");
        if(count($duplicateCleverIds) === 0) {
            $this->info("No duplicate Clever IDs found.");
            return;
        }
        $this->info("Processing...");
        $bar = $this->output->createProgressBar(count($duplicateCleverIds));
        $bar->start();
        foreach ($duplicateCleverIds as $cleverId) {
            $this->info("Processing Clever ID: {$cleverId}");
            $metadataRecords = Metadata::where('data->clever_id', $cleverId)->get();

            if ($metadataRecords->count() > 1) {
                $this->info("Found {$metadataRecords->count()} records with Clever ID: {$cleverId}");
                $this->info("Processing...");

                $slicedRecords = $metadataRecords->slice(1);

                // Process the remaining records
                $this->info("Processing Roster records...");
                foreach ($slicedRecords as $record) {
                    $this->warn("Processing Roster record: {$record->metable_id}");
                    $roster = Roster::find($record->metable_id);
                    $roster->delete();
                }
            }
            $bar->advance();
        }
        $bar->finish();
    }
}
