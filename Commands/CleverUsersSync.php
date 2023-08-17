<?php

namespace LGL\Clever\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use LGL\Auth\Users\EloquentUser;
use LGL\Core\Rosters\Models\Roster;

class CleverUsersSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:clientUserSync {clientId? : The id we should run the clever re-sync on} {type=all : Either student, teacher, section, or all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Runs through all users of a student/teacher role and re-syncs Clever Information.\n 
                              This doesn\'t pull anything from Clever for new user creation.\n
                              This could be adjusted to accomidate a pull to adjust all users accourdingly.\n';

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


        switch($this->argument('type')) {
            case 'student':
                $this->info('Queuing Clever Sync for Client: ' . $this->argument('clientId') . ' and type: ' . $this->argument('type') . '...');
                $this->usersSync('student');
                break;
            case 'teacher':
                $this->info('Queuing Clever Sync for Client: ' . $this->argument('clientId') . ' and type: ' . $this->argument('type') . '...');
                $this->usersSync('teacher');
                break;
            case 'section':
                $this->sectionsSync();
                break;
            case 'all':
                $this->comment('Begining Clever Sync for Client: ' . $this->argument('clientId') . ' and type: EVERYTHING!!!...');
                $this->info('Queueing Students...');
                $this->usersSync('student');
                print "\n";
                print "\n";
                $this->info('Queueing Teachers...');
                $this->usersSync('teacher');
                print "\n";
                print "\n";
                $this->info('Queueing Sections...');
                $this->sectionsSync();
                break;
            default:
                $this->error('Invalid type. Please use student, teacher, section, or all.');
                break;
        }
        print "\n";
        $this->comment('Done!');
        


        
    }

    private function usersSync($type) {

		$users = EloquentUser::ofClientId($this->argument('clientId'))->OfRole($type)->where('last_login', '>', Carbon::now()->subDays(120));
		$progressBar = $this->output->createProgressBar();
        $progressBar->start($users->count());
		$users->orderBy('id')->with('metadata')->chunk(25, function($usersChunk) use($progressBar, $type) {
			foreach ($usersChunk as $user) {
                if(isset($user->metadata->data['clever_id'])) {
                    Artisan::queue('clever:userSync', ['clientId' => $user->client_id, 'type' => $type, 'cleverId' => $user->metadata->data['clever_id']]);
                }
			}
            $progressBar->advance(count($usersChunk));
		});
		$progressBar->finish();
    }

    private function sectionsSync() {

        // Get all sections and queue for a roster sync
        $sections = Roster::ofClient($this->argument('clientId'))->where('created_at', '>', Carbon::now()->subDays(120))->get();
        $progressBar = $this->output->createProgressBar();
        $progressBar->start($sections->count());
    
        $sections->chunk(25, function($sectionsChunk) use($progressBar) {
            foreach ($sectionsChunk as $section) {
                if(isset($section->metadata->data['clever_id'])) {
                    Artisan::queue('clever:sectionSync', ['clientId' => $section->client_id, 'sectionId' => $section->metadata->data['clever_id']]);
                }
            }
            $progressBar->advance(count($sectionsChunk));
        });
        $progressBar->finish();
    }
}
