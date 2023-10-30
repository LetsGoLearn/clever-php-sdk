<?php

namespace LGL\Clever\Commands;

use Illuminate\Console\Command;
use LGL\Core\Models\Metadata;
use LGL\Auth\Users\EloquentUser;
use LGL\Core\Rosters\Models\Roster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    protected $description = 'This works with duplicate Clever IDs for Rosters Only as of 2023-09-09.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->processRosters();
        $this->processUsers();

    }

    private function processRosters() {
        $this->warn("This doesn't limit by Client ID. It will process all rosters.");
        $this->info("Processing Rosters...");
        $this->info("Fetching duplicate Clever IDs...");
        $duplicateCleverIds = Metadata::select(DB::raw("data->>'clever_id' as clever_id"), DB::raw('COUNT(*) as count'), DB::raw('metable_type'))
            ->whereNotNull(DB::raw("data->>'clever_id'"))
            ->where('metable_type', 'LGL\Core\Rosters\Models\Roster')
            ->groupBy(DB::raw("data->>'clever_id', metable_type"))
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('count', 'desc')
            ->get();

        if ($duplicateCleverIds->count() !== 0) {

            $this->info("Found " . count($duplicateCleverIds) . " duplicate Clever IDs.");
            if(count($duplicateCleverIds) === 0) {
                $this->info("No duplicate Clever IDs found.");
                return;
            }
            $this->info("Processing...");
            $bar = $this->output->createProgressBar(count($duplicateCleverIds));
            $bar->start();
            foreach ($duplicateCleverIds as $cleverId) {
                $metadataRecords = Metadata::where('data->clever_id', $cleverId)->get();
                if ($metadataRecords->count() > 1) {
                    Log::error("[MultiCleverIds] Found {$metadataRecords->count()} records with Clever ID: {$cleverId} for rosters. metabale_ids: {$metadataRecords->pluck('metable_id')->implode(', ')} | metable_types: {$metadataRecords->pluck('metable_type')->implode(', ')}");
                    $slicedRecords = $metadataRecords->slice(1);
                    foreach ($slicedRecords as $record) {
                        $roster = Roster::find($record->metable_id);
                        $roster->delete();
                    }
                }
                $bar->advance();
            }
            $bar->finish();
        }
        else {
            $this->info("No duplicate Clever IDs found for rosters.");
        }

    }
    private function processUsers() {
        $this->warn("This doesn't limit by Client ID. It will process all users.");
        $this->info("Processing users...");
        $duplicateCleverIds = Metadata::select(DB::raw("data->>'clever_id' as clever_id"), DB::raw('COUNT(*) as count'))
            ->whereNotNull(DB::raw("data->>'clever_id'"))
            ->where('metable_type', 'LGL\Core\Auth\Users\EloquentUser')
            ->groupBy(DB::raw("data->>'clever_id'"))
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('count', 'desc')
            ->get()->pluck('clever_id')->toArray();

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
            $metadataRecords = Metadata::where('data->clever_id', $cleverId)->get();
            if ($metadataRecords->count() > 1) {
                $users = EloquentUser::with('metadata', 'roles')->whereIn('id', $metadataRecords->pluck('metable_id')->toArray())->orderBy('created_at')->get();
                Log::error("[MultiCleverIds] Found {$metadataRecords->count()} records with Clever ID: {$cleverId} for users. metabale_ids: {$metadataRecords->pluck('metable_id')->implode(', ')} | metable_types: {$metadataRecords->pluck('metable_type')->implode(', ')}");

                // Get the user with the most recent non-null last_login
                $latestUser = $users->filter(function ($user) {
                    return !is_null($user->last_login);
                })->sortByDesc('last_login')->first();

                if (!$latestUser) {
                    $latestUser = $users->sortByDesc('created_at')->first();
                }

                $users = $users->reject(function ($user) use ($latestUser) {
                    return $user->id == $latestUser->id;
                })->values();  // The values() method re-indexes the collection

                foreach ($users as $user) {
                    if (is_null($user->last_login)) {
                        $user->delete();
                        Log::error("[MultiCleverIds] User has never logged in. Moving Clever ID to error_clever_id. Need to hard Delete User. User ID: {$user->id} | Clever ID: {$cleverId}");
                    }
                    else {
                        $data = $user->metadata->data;
                        $data['error_clever_id'] = $data['clever_id'];
                        unset($data['clever_id']);
                        $user->setMetadata($data, true);
                        $user->delete();
                        Log::error("[MultiCleverIds] User has logged in. Not Sure what to do. Soft Delete User. Change where clever_id is stored. // Possible Merge Needed. User ID: {$user->id} | Clever ID: {$cleverId}");
                    }
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
    }
    private function getRoles($users) {
        $roles = [];
        foreach ($users as $user) {
            $roles[] = $user->roles->pluck('slug')->toArray();
        }
        return $roles;
    }
}
