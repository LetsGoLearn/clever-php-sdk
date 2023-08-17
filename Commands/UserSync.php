<?php

namespace LGL\Clever\Commands;

/*
 * Created by PhpStorm.
 * User: pmoon
 * Date: 11/18/16
 * Time: 2:29 PM
 */

use LGL\Clever\Api;
use LGL\Clever\Exceptions\Exception;
use Illuminate\Console\Command;
use LGL\Core\Accounts\Models\Client as Clients;
use LGL\Core\Clever\Traits\CleverBase;
use LGL\Core\Imports\Traits\ProcessRoster;
use LGL\Core\Imports\Traits\ProcessUser;
use LGL\Core\Rosters\Models\Roster;
use LGL\Auth\Users\EloquentUser;
/**
 * Class CleverSync.
 */
class UserSync extends Command
{
    use CleverBase;
    use ProcessUser;
    use ProcessRoster;

    protected $client, $cleverId, $type;
    protected $metadata, $clever;
    protected $adminTypes;
    protected $notifier, $notifyUser;

    /**
     * @var EloquentUser
     */
    protected $user;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:userSync 
    {clientId : Client\'s LGL ID to sync} 
    {type : The clever type} 
    {cleverId : User\'s clever id}
    {--debug : Set to output more messages.}
    {--notify= : The LGL id of a user to notify in the system when the command has completed.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Used to re-sync some information about a user object from Clever';

    /**
     * CleverSync constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @throws \Exception
     */
    public function handle()
    {
        $cleverSections   = null;
        $this->adminTypes = config('clever')['adminTypes'];
        try {
            $this->client   = $this->verify(Clients::with('metadata')->find($this->argument('clientId')));
            $this->cleverId = $this->argument('cleverId');
            $this->type     = $this->argument('type');
            $this->clever   = new Api($this->client->metadata->data['api_secret']);

            if ($this->type === 'teacher') {
                $siteLinkNumber   = 2;
                $rosterLinkNumber = 4;
            } elseif ($this->type === 'student') {
                $siteLinkNumber   = 3;
                $rosterLinkNumber = 2;
            } elseif (in_array($this->type, $this->adminTypes)) {
                $siteLinkNumber   = 1;
                $rosterLinkNumber = null;
            } else {
                throw new Exception($this->type.' is not a valid type. We support teacher, student,'.implode(', ', $this->adminTypes).' syncs.');
            }
            $this->metadata = $this->getCleverMetadata($this->cleverId);
            $cleverPull     = $this->pullInCleverData();
            if ($cleverPull) {
                /** @noinspection PhpUndefinedFieldInspection */
                $cleverData                     = $cleverPull->data;
                $cleverData['data']['clientId'] = $this->client->id;
                $cleverLink                     = $cleverData['links'][$siteLinkNumber]['uri'];
                $cleverRosterLink               = $cleverData['links'][$rosterLinkNumber]['uri'];
                $this->getPreferences();

                // ToDo: Site information is actually updated in this part of the system. We should move it to the site sync command.
                $this->user = $this->upsertUser($this->type, $cleverData['data'], 'Clever User Sync Command', true);
                $this->user->deleted_at = null;
                $this->user->save();
                $this->updateSiteLinks($cleverLink);
                if (! in_array($this->type, $this->adminTypes)) {
                    $this->updateRosterData($cleverRosterLink);
                }
            } else {

//                 $user = EloquentUser::withTrashed()->find($this->metadata->data['metable_id']);
//                 $user->delete();
//                 $this->error('Closed Account User not available from clever '.$this->cleverId);
//                 $this->sendNotification('Closed Account User not available from clever '.$this->cleverId);
            }
        } catch (Exception $e) {
            $this->error('Caught exception: '.$e->getMessage().' File: '.$e->getFile().' Line: '.$e->getLine());
            $subject = 'Clever Sync Error';
            $message = 'Caught exception: '.$e->getMessage().' File: '.$e->getFile().' Line: '.$e->getLine();
            $reciepients = [];
            die();
        }
        if ($this->option('debug')) {
            $this->info(ucfirst($this->argument('type')).' information has been updated.');
        }

        // $this->sendNotification();
    }

    /**
     * @param $cleverRosterLink
     *
     * @throws \Exception
     */
    protected function updateRosterData($cleverRosterLink)
    {
        if ($this->option('debug')) {
            $this->output->note('Syncing '.$this->type.'s roster information...');
        }
        /* @noinspection PhpUndefinedMethodInspection */
        $cleverSections = $this->clever->getUrl(substr($cleverRosterLink, 6));

        ($this->type === 'student') ? $this->workWithStudentRosterData($cleverSections) : $this->workWithStaffRosterData($cleverSections);
    }

    /**
     * @param $cleverLink
     */
    public function updateSiteLinks($cleverLink)
    {
        if ($this->option('debug')) {
            $this->output->note('Syncing '.$this->type.'s site information...');
            $this->output->note('Updating Site Information...');
        }
        /* @noinspection PhpUndefinedMethodInspection */
        $schools = $this->getSchoolData($cleverLink);
        /* @noinspection PhpUndefinedMethodInspection */

		/**
		 * This is where we should trim the clever sites and only replace as needed.
		 *  Good luck this is going to be a shitshow
		 */

		$this->processCleverSites($schools);

    }

	private function processCleverSites($schools) {
		$cleverSchoolIds = [];
		foreach($schools['data'] as $school) {
			$cleverSchoolIds[] = $school['data']['id'];
		}
		foreach ($this->user->sites as $site) {
			if (isset($site->metadata->data['clever_id'])) {
				if (!in_array($site->metadata->data['clever_id'], $cleverSchoolIds)) {
					// Nothing to do, everything lines up.
					$this->user->sites()->detach($site->id);
				}
			}
		}
	}

    protected function getSchoolData($cleverLink)
    {
        /* @noinspection PhpUndefinedMethodInspection */
        $schoolResult = $this->clever->getUrl(substr($cleverLink, 6));
        if (empty($schoolResult['data'][0])) {
            $schools['data'][0]['data'] = $schoolResult['data'];
            $schools['links']           = $schoolResult['links'];
        } else {
            $schools = $schoolResult;
        }

        return $schools;
    }

    /**
     * @return Api
     */
    protected function pullInCleverData()
    {
        $type = $this->type;
        if ($this->option('debug')) {
            $this->output->note('Looking for clever data for '.$type.'...');
        }
        $cleverUser = $this->clever->$type($this->cleverId);
        if (isset($cleverUser->data['error'])) {
            $cleverUser = false;
        }

        return $cleverUser;
    }


    /**
     * @param $cleverSections
     *
     * @throws \Exception
     */
    protected function workWithStaffRosterData($cleverSections)
    {
        foreach ($cleverSections['data'] as $section) {
            if (isset($section['data']['id'])) {
                $this->upsertSection($section['data']);
                if ($this->option('debug')) {
                    $this->info('Roster created/updated...');
                }
            } else {
                $this->error('No id for Roster.');
            }
        }
    }

    /**
     * @param $cleverSections
     * @throws Exception
     */
    protected function workWithStudentRosterData($cleverSections)
    {
        /* @noinspection PhpUndefinedMethodInspection */
        $passedCleverSectionId = [];
		$alreadyEnrolled = [];

        foreach ($cleverSections['data'] as $section) {
            $passedCleverSectionId[] = $section['data']['id'];
        }
		foreach ($this->user->rosters as $roster) {
			if (isset($roster->metadata->data['clever_id'])) {
				if (!in_array($roster->metadata->data['clever_id'], $passedCleverSectionId)) {
					$this->user->rosters()->detach($roster->id);
				}
				else {
					$alreadyEnrolled[] = $roster->metadata->data['clever_id'];
				}

			}
		}

		foreach($cleverSections['data'] as $section) {
			if(!in_array($section['data']['id'], $alreadyEnrolled)) {
				$roster = Roster::ofClever($section['data']['id'])->first();
				if($roster) {
					$this->user->rosters()->attach($roster->id);
				}
			}

		}

    }

    /**
     * @param $client
     *
     * @return mixed
     * @throws \Clever\Exceptions\Exception
     */
    private function verify($client)
    {
        if (isset($client->metadata) && $client->metadata->data['partner_id'] === 1 && $client->deleted_at === null) {
            return $client;
        }
        throw new Exception($client->code.' is not using Clever Smart Sync.');
    }


}
