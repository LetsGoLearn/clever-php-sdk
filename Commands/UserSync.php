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
use LGL\Core\Models\Metadata;

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


        $cleverSections = null;
        $this->adminTypes = config('clever')['adminTypes'];
        try {
            $this->client = $this->verify(Clients::with('metadata')->find($this->argument('clientId')));
            $this->cleverId = $this->argument('cleverId');
            $this->type = $this->argument('type');
            $this->clever = new Api($this->client->metadata->data['api_secret']);

            // Do we have access to the user?
            if ($this->type == 'teacher') {
                $this->warn('Checking for teacher access...');
                $cleverTeacher = $this->clever->teacher($this->cleverId);
                if (isset($cleverTeacher->data['error'])) {
                    $this->error('Teacher not available from clever '.$this->cleverId);
                    die();
                }
            }

            // If the main record is missing we'll move the clever_id
            $idInUse = Metadata::ofCleverId($this->cleverId)->get();

            $this->warn('Checking for to many of a resource');
            foreach ($idInUse as $metadata) {
                // ToDo: Clever: Check if user exists before moving on.
                $this->user = EloquentUser::withTrashed()->find($metadata->metable_id);
                if (is_null($this->user)) {
                    $data = $metadata->data;
                    $data['clever_id_removed'] = $data['clever_id'];
                    unset($data['clever_id']);
                    $metadata->data = $data;
                    $metadata->save();
                } else {
                    $this->warn('User is not deleted. Moving on...');
                }
                $type = $this->type;
                $cleverInfo = $this->clever->$type($this->cleverId);
                if (isset($cleverInfo->data['error'])) {
                    $this->warn('Clever Id: '.$this->cleverId.' is no longer in clever. Removing...');
                    $metadata->data['clever_id'] = false;
                    $metadata->data['clever_id_removed'] = $this->cleverId;
                    $metadata->save();
                }
            }

            if ($this->type === 'teacher') {
                $siteLinkNumber = 2;
                $rosterLinkNumber = 5;
            } elseif ($this->type === 'student') {
                $siteLinkNumber = 3;
                $rosterLinkNumber = 2;
            } elseif (in_array($this->type, $this->adminTypes)) {
                $siteLinkNumber = 1;
                $rosterLinkNumber = null;
            } else {
                throw new Exception($this->type.' is not a valid type. We support teacher, student,'.implode(', ',
                        $this->adminTypes).' syncs.');
            }
            $this->metadata = $this->getCleverMetadata($this->cleverId);
            $cleverPull = $this->pullInCleverData();

            if ($cleverPull) {
                /** @noinspection PhpUndefinedFieldInspection */
                $cleverData = $cleverPull->data;
                $cleverData['data']['clientId'] = $this->client->id;
                $cleverLink = $cleverData['links'][$siteLinkNumber]['uri'];
                $cleverRosterLink = $cleverData['links'][$rosterLinkNumber]['uri'];
                $this->getPreferences();
                $this->user = $this->upsertUser($this->type, $cleverData['data'], 'Clever User Sync Command', true);
                $this->user->deleted_at = null;
                $this->user->save();

                $this->warn('Updating Sites...');
                $this->updateSiteLinks($cleverLink);

                $this->warn('Updating Rosters...');
                if (! in_array($this->type, $this->adminTypes)) {
                    $this->updateRosterData($cleverRosterLink);
                }
            } else {
                dd($cleverPull, 'here', $this->cleverId);
                $this->warn('Discconect Clever Id: '.$this->cleverId.' from user '.$this->user->id.'.');

            }
            $this->warn('Done updating user '.$this->user->id.'.');
            $this->warn("Remove rosters the user shouldn't be in anymore");

            if ($this->type == 'student') {
                $this->warn('Removing rosters from student...');
                foreach ($this->user->rosters as $roster) {
                    if (isset($roster->metadata->data['clever_id'])) {
                        $section = $this->clever->section($roster->metadata->data['clever_id']);
                        if (isset($section->data['error'])) {
                            $this->warn('Roster '.$roster->id.' is no longer in clever. Removing...');
                            $this->user->rosters()->detach($roster->id);
                        }
                    }
                }
            } elseif ($this->type == 'teacher') {
                // ToDo: Clever: Remove rosters from teacher that they lost access to
                $this->warn('Removing rosters from teacher... ToDo');
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
        // This is just odd. Why are we doing this?
        if ($this->option('debug')) {
            $this->output->note('Syncing '.$this->type.'s roster information...'.$cleverRosterLink);
        }

        $cleverSections = $this->clever->getUrl(substr($cleverRosterLink, 6));
        ($this->type === 'student') ? $this->workWithStudentRosterData($cleverSections) : $this->workWithStaffRosterData($cleverSections);
    }


    public function updateSiteLinks($cleverLink)
    {

        $this->output->note('Syncing '.$this->type.'s site information...');

        $cleverSiteData = $this->clever->getUrl(substr($cleverLink, 6));
        $metadataSite = Metadata::ofCleverId($cleverSiteData['data']['id'])->first();
        $this->user->sites()->detach();
        $this->user->sites()->attach($metadataSite->metable_id);
        $this->user->setMetadata(['site_id' => $metadataSite->metable_id]);
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
        $this->warn('Processing teachers rosters... roster access or a different issue');

        $rosterAccess = [];

        foreach ($cleverSections['data'] as $section) {

            $rosterAccess = array_merge($rosterAccess, $section['data']['teachers']);

            if (Metadata::ofType('LGL\Core\Rosters\Models\Roster')->ofCleverId($section['data']['id'])->count() > 0) {
                // We found a roster to attach to the user.
                $this->warn('Found roster to attach to user...');
                $site = $this->getSite($section['data']['school']);
                $teacherGet = $this->getUser(null, $section['data']['teacher'], $this->client->id)->first();
                $rosterId = Metadata::ofType('LGL\Core\Rosters\Models\Roster')->ofCleverId($section['data']['id'])->first()->metable_id ?? null;

                if (! is_null($teacherGet) && ! is_null($rosterId) && ! is_null($site)) {

                    $this->info('Updated teacher for roster: '.$rosterId.' ...');

                    Roster::where('id', $rosterId)->update([
                        'site_id' => $site->id,
                        'user_id' => $teacherGet->id,
                        'title' => $section['data']['name'].' (Clever)'
                    ]);

                }

            } else {
                $cleverTeacher = $this->clever->teacher($section['data']['teacher']);
                $this->warn('No roster found to attach to the teacher. Lets Create One...');
                $this->createRoster($section['data'], $cleverTeacher->data['data']);
            }
        }
        $rosterAccess = array_filter(array_unique($rosterAccess), fn($val)=> $val != $this->cleverId);

        if (!empty($rosterAccess)) {
            $this->user->rosterAccess()->sync($rosterAccess);
        } else {
            $this->user->rosterAccess()->detach();
            $data = $this->user->metadata->data;
            unset($data['roster_id']);
            $this->user->setMetadata($data, true);
        }
    }

    /**
     * @param $cleverSections
     * @throws Exception
     */
    protected function workWithStudentRosterData($cleverSections)
    {
        $rosterMetadataByCleverIds = [];
        // ToDo: Clever: There has to be a better way to do this (@Ryan)
        foreach ($cleverSections['data'] as $section) {

            if (Metadata::ofType('LGL\Core\Rosters\Models\Roster')->ofCleverId($section['data']['id'])->count() > 0) {
                // We found a roster to attach to the user.
                $this->warn('Found roster to attach to user...');
                $rosterMetadataByCleverIds[] = Metadata::ofType('LGL\Core\Rosters\Models\Roster')->ofCleverId($section['data']['id'])->first()->metable_id;
            } else {
                $this->warn('No roster found to attach to user. Lets Create One...');
                // ToDo: Clever Get Teacher Data From Clever in-case we need it.
                $teacher = $this->clever->teacher($section['data']['teacher']);
                // ToDo: Clever: Create the roster
                $this->createRoster($section['data'], $teacher->data['data']);
                $roster = Roster::ofClever($section['data']['id'])->first();
                $this->warn('Created Roster...');
            }
        }
        $this->user->rosters()->syncWithoutDetaching($rosterMetadataByCleverIds);
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
