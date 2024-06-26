<?php

namespace LGL\Clever\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use LGL\Clever\Api;
use LGL\Core\Accounts\Models\Client;
use LGL\Auth\Users\EloquentUser;
use LGL\Clever\Jobs\ProcessCleverIdJob;


class CleverUserCleaner extends Command
{
    protected Api $clever;
    protected array $lostAccess = [
        'student' => 0,
        'teacher' => 0,
        'principal' => 0
    ];

    protected int $otherError = 0;

    protected $logFile;

    protected $forceDelete;

    protected Client $client;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:users:fixer {clientId : Client ID to process for Clever} {--forceDelete : Force delete users from the system. Default: false}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This clever command will remove Clever IDs from users who no longer have access to Clever.';

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
        $roles = ['student', 'teacher', 'principal'];

        foreach ($roles as $index => $role) {
            $users = $this->getUsersByRole($this->client, $role);
            $this->processUsers($users, $role);
        }

        $this->info('Clever ID cleanup Jobs Queued.');
        $this->newLine(2);


        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        $this->info("Total execution time: {$elapsedTime} seconds.");
    }

    // Helper function to fetch EloquentUser records based on a role.
    public function getUsersByRole($client, $role) {
        return EloquentUser::withTrashed()->ofClientId($client->id)->ofRole($role)->with('metadata')
            ->whereHas('metadata', function ($query) {
                $query->whereNotNull('data->clever_id');
            });
    }


    public function processUsers($users, $role) {
        $shouldForceDelete = false;
        $this->info("Processing ".$role."... {Count: " . $users->count() . "}");
        if ($this->option('forceDelete')) {
            $this->warn('forceDelete is enabled!!!!');
            $shouldForceDelete = true;
        }
        $this->info("Client: " . $this->client->title);
        $bar = $this->output->createProgressBar($users->count());
        $bar->start();
        $users->chunk(100, function ($chunk) use ($role, $bar, $shouldForceDelete) {
            foreach ($chunk as $user) {
                if ($user->exists) {
                    ProcessCleverIdJob::dispatchSync($user->id, $user->client_id, $role, $shouldForceDelete);
                }
                else {
                    Log::warning('['.Carbon::now()->toDateTimeString().'][CleverIdUserCleaner] No Idea why this thing thinks it should do this... User ID: ' . $user->id . ' | Client Id: ' . $this->client->id . ' | Role: ' . $role . ' | Should Force Delete: ' . $this->option('forceDelete'));
                }
            }
            $bar->advance($chunk->count());
        });

        $bar->finish();
        $this->newLine(2);
    }
}
