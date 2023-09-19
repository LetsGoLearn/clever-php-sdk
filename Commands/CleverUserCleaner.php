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
    protected array $lostAccess = [
        'student' => 0,
        'teacher' => 0,
        'principal' => 0,
        'client' => 0
    ];

    protected int $otherError = 0;

    protected $logFile;

    protected Client $client;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:users:fixer {clientId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This clever command will remove Clever IDs from users who no longer have access to Clever.'
                           . ' It will also log any other errors that occur. This command is still in use as of 2023-09-09.';

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

        $this->info('+-- Finished Processing users --+');
        $this->table(['Type', 'Count'], [
            ['Lost Access', $this->lostAccess['student'] + $this->lostAccess['teacher'] + $this->lostAccess['principal']],
            ['Other Errors', $this->otherError],
        ]);
        $this->newLine(2);

        $this->info('+-- Totals Counts --+');
        $this->table(['Type', 'Count'], $counts);
        $this->newLine(2);

        $this->info('+-- Access Removed Counts --+');
        $this->table(['Type', 'Count'], [
            ['Students', $this->lostAccess['student']],
            ['Teachers', $this->lostAccess['teacher']],
            ['School Admins', $this->lostAccess['principal']],
            ['District Admins', $this->lostAccess['client']],
        ]);
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
            })->get();
    }

    // Helper function to process users.
    public function processUsers($users, $clever, $role) {
        $this->info("Processing $role... {Count: " . $users->count() . "}");
        $bar = $this->output->createProgressBar($users->count());

        // Open log file for writing

        $this->logFile = fopen(storage_path("logs/{$this->client->id}_{$role}_processing.".Carbon::now()->toDateString()."_".Carbon::now()->toTimeString().".log"), 'a');

        foreach ($users as $user) {

            $this->processCleverId($user, $clever, $role);

            $bar->advance();
        }

        // Close log file
        fclose($this->logFile);

        $bar->finish();
        $this->newLine(2);
    }

    // $entity is an object of a user type (EloquentUser)
    // $clever is an instance of the Clever API class
    // $type is the role of the user (student, teacher, principal, client)
    public function processCleverId($entity, $clever, $type) {
        // Check if Clever ID is valid & Available
        $return = $clever->{$type}($entity->metadata->data['clever_id']);

        // We have an Error
        if (isset($return->data['error'])) {
            // We no longer have access, remove Clever ID from metadata
            $cleverId = $entity->metadata->data['clever_id'];
            if ($return->data['error'] == 'Resource not found') {
                $data = $entity->metadata->data;
                $data['error_clever_id'] = $data['clever_id'];
                unset($data['clever_id']);
                $metadata = $entity->metadata;
                $metadata->data = $data;
                $metadata->save();
                $this->lostAccess[$type]++;
                $logData = [
                    'cleverIdError' => $cleverId,
                    'lglId' => $entity->id,
                    'role' => $type,
                    'email' => $entity->email
                ];
                fwrite($this->logFile, json_encode($logData) . PHP_EOL);
            }
            else {
                $logData = [
                    'cleverIdError' => $cleverId,
                    'lglId' => $entity->id,
                    'role' => $type,
                    'email' => $entity->email
                ];

                $this->otherError++;
                fwrite($this->logFile, json_encode($logData) . PHP_EOL);
            }
        }
        return $this;
    }
}
