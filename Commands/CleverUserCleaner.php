<?php

namespace LGL\Clever\Commands;

use Illuminate\Console\Command;
use LGL\Clever\Api;
use LGL\Core\Accounts\Models\Client;
use LGL\Auth\Users\EloquentUser;
use Carbon\Carbon;


class CleverUserCleaner extends Command
{
    protected Api $clever;
    protected int $lostAccess = 0;
    protected int $otherError = 0;

    protected Client $client;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:fixer {clientId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Initialize timer
        $startTime = microtime(true);

        $this->warn('Starting Clever account cleanup...');
        $this->client = Client::find($this->argument('clientId'));
        $clever = new Api($this->client->metadata->data['api_secret']);



        // Fetch users by roles.
        $roles = ['student', 'teacher', 'principal', 'client'];
        $roleNames = ['Students', 'Teachers', 'School Admins', 'District Admins'];
        $counts = [];

        foreach ($roles as $index => $role) {
            $users = $this->getUsersByRole($this->client, $role);
            $counts[] = [$roleNames[$index], $users->count()];
            $this->processUsers($users, $clever, $role);
        }

        $this->table(['Type', 'Count'], $counts);
        $this->warn("Lost Access: {$this->lostAccess}");
        $this->warn("Other Errors: {$this->otherError}");
        $this->newLine(2);


        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        $this->info("Total execution time: {$elapsedTime} seconds.");
    }

    // Helper function to fetch EloquentUser records based on a role.
    public function getUsersByRole($client, $role) {
        return EloquentUser::ofClientId($client->id)->ofRole($role)->with('metadata')
            ->whereHas('metadata', function ($query) {
                $query->whereNotNull('data->clever_id');
            })->limit(100)->get();
    }

    // Helper function to process users.
    public function processUsers($users, $clever, $role) {
        $this->info("Processing $role... {Count: " . $users->count() . "}");
        $bar = $this->output->createProgressBar($users->count());

        // Open log file for writing

        $logFile = fopen(storage_path("logs/{$this->client->id}_{$role}_processing.".Carbon::now()->toDateString()."_".Carbon::now()->toTimeString().".log"), 'a');

        foreach ($users as $user) {
            // Your existing processing logic here

            $action = $this->processCleverId($user, $clever, $role);

            // Log the processed user info
            $logData = [
                'cleverId' => $user->metadata->data['clever_id'] ?? 'N/A',
                'lglId' => $user->id ?? 'N/A',
                'role' => $role,
                'email' => $user->email ?? 'N/A',
                'action' => $action,
            ];

            // Write to the log file
            fwrite($logFile, json_encode($logData) . PHP_EOL);

            $bar->advance();
        }

        // Close log file
        fclose($logFile);

        $bar->finish();
        $this->newLine(2);
    }

    public function processCleverId($entity, $clever, $type) {
        // Check if Clever ID is valid & Available
        $return = $clever->{$type}($entity->metadata->data['clever_id']);

        // We have an Error
        if (isset($return->data['error'])) {
            // We no longer have access, remove Clever ID from metadata
            if ($return->data['error'] == 'Resource not found') {
                $data = $entity->metadata->data;
                $data['error_clever_id'] = $data['clever_id'];
                unset($data['clever_id']);
                $entity->setMetadata($data);
                $entity->save();
                $this->lostAccess++;
                return 'Lost Access';
            }
            else {
                $this->otherError++;
                return 'Not sure what happened...';
            }

        }
        return 'Nothing to do...';
    }
}
