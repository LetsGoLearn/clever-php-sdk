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
    protected $signature = 'clever:id:fixer {--rosters : Process only rosters} {--users : Process only users}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Attempts to clean accounts with multiple Clever IDs.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('users') || (!$this->option('users') && !$this->option('rosters'))) {
            $this->processUsers();
        }

        if ($this->option('rosters') || (!$this->option('users') && !$this->option('rosters'))) {
            $this->processRosters();
        }
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
            $this->info("Processing...");
            $bar = $this->output->createProgressBar(count($duplicateCleverIds));
            $bar->start();
            foreach ($duplicateCleverIds as $cleverId) {
                $metadataRecords = Metadata::where('data->clever_id', $cleverId)->get();
                if ($metadataRecords->count() > 1) {
                    $this->info("[MultiCleverIds] Found {$metadataRecords->count()} records with Clever ID: {$cleverId} for rosters. Roster Ids: {$metadataRecords->pluck('metable_id')->implode(', ')} | metable_types: {$metadataRecords->pluck('metable_type')->implode(', ')}");
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
        if(count($duplicateCleverIds) === 0) {
            $this->info("No duplicate Clever IDs found.");
            return;
        }
        $this->info("Found " . count($duplicateCleverIds) . " duplicate Clever IDs.");
        $this->info("Processing...");
        foreach ($duplicateCleverIds as $cleverId) {
            $metadataRecords = Metadata::where('data->clever_id', $cleverId)->get();
            if ($metadataRecords->count() > 1) {
                $users = EloquentUser::withTrashed()->with('metadata', 'roles', 'queue')->whereIn('id', $metadataRecords->pluck('metable_id')->toArray())->orderBy('created_at')->get();
                Log::error("[MultiCleverIds] Found {$metadataRecords->count()} records with Clever ID: {$cleverId} for users. metabale_ids: {$metadataRecords->pluck('metable_id')->implode(', ')} | metable_types: {$metadataRecords->pluck('metable_type')->implode(', ')}");
                $this->info("[MultiCleverIds] Found {$metadataRecords->count()} records with Clever ID: {$cleverId} for users. metabale_ids: {$metadataRecords->pluck('metable_id')->implode(', ')} | metable_types: {$metadataRecords->pluck('metable_type')->implode(', ')}");
                // Check for a queue record for any users
                $hasQueueRecords = $users->contains(function ($user) {
                    return $user->queue->isNotEmpty();
                });

                if ($hasQueueRecords) {
                    foreach ($users as $user) {
                        $this->info('User Id: '.$user->id.' | Last Login: '.$user->last_login.' | Created at: '.$user->created_at.' | Deleted at: '.$user->deleted_at);
                    }
                    $this->info("Diane should look at these student ID's to merge: ".implode(',',$users->pluck('id')->all()));
                }

                else {
                    $sortedUsers = $users->sortBy(['last_login', 'created_at']);
                    foreach ($sortedUsers as $user) {
                        $this->info('User Id: '.$user->id.' | Last Login: '.$user->last_login.' | Created at: '.$user->created_at.' | Deleted at: '.$user->deleted_at);
                    }
                    $this->info("Diane should look at these ID's to merge they do not have any queue records: ".implode(',',$users->pluck('id')->all()));
                }
                $this->newLine(2);
            }
        }
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
