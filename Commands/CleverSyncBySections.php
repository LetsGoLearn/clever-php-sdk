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
class CleverSyncBySections extends Command
{
    use CleverBase;
    use ProcessUser;
    use ProcessRoster;

    protected $client, $clientId, $cleverId, $type;
    protected $metadata, $clever;
    protected $adminTypes;

    /**
     * @var EloquentUser
     */
    protected $user;

    /**
     * @var Roster
     */
    protected $roster;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:sync:by:section 
    {clientId : The client ID to begin syncing} 
    {--debug : Show more information.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Used to re-sync some information about a section object from Clever using a given client LGL Id and a clever section ID.';

    /**
     * CleverSync constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }


    /**
     * @throws \Exception
     */
    public function handle()
    {
        $cleverSections   = null;
        $this->adminTypes = config('clever')['adminTypes'];
        try {
            $this->clientId = $this->argument('clientId');
            $this->client   = Clients::where('id', $this->clientId)->with('metadata')->first();
            $this->type     = 'section';
            $this->clever   = new Api($this->client->metadata->data['api_secret']);


            $rosterLinkNumber = 0;
            $rosters = $this->client->rosters()->get();

            foreach($rosters as $roster) {
                if(isset($roster->metadata->data['clever_id'])) {
                    $section = $this->clever->section($roster->metadata->data['clever_id']);
                    if (isset($section->data['error'])) {
                        // We no longer have access to this section...
                        $this->warn('we no longer have access to this section...');
                        $roster->delete();
                    }
                    else {
                        $this->info('Syncing '.$roster->metadata->data['clever_id'].'...');
                        $this->workWithStaffRosterData($section->data);
                    }
                } else {
                    $this->warn('nothing to do');
                }
            }

//            $this->metadata = $this->getCleverMetadata($this->cleverId);
//            /* @noinspection PhpUndefinedFieldInspection */
//            $cleverData                     = $this->pullInCleverData()->data;
//            $cleverData['data']['clientId'] = $this->client->id;
//            $cleverRosterLink               = $cleverData['links'][$rosterLinkNumber]['uri'];
//
//            $this->updateRosterData($cleverRosterLink);
        } catch (Exception $e) {
            $this->error('Caught exception: '.$e->getMessage().' File: '.$e->getFile().' Line: '.$e->getLine());
            die();
        }
        $this->info('Roster information has been updated.');
    }



    /**
     * @return mixed
     * @throws \Clever\Exceptions\Exception
     */
    protected function pullInCleverData()
    {
        $type = $this->type;
        if ($this->option('debug')) {
            $this->output->note('Looking for clever data for '.$type.'...');
        }
        $cleverData = $this->clever->$type($this->cleverId);
        if (isset($cleverData->data['error'])) {
            throw new Exception('clever returned an error: "' . $cleverData->data['error'] . '" while fetching ' . $type . ' data');
        }

        return $cleverData;
    }


    /**
     * @param $section
     *
     * @throws \Exception
     */
    protected function workWithStaffRosterData($section)
    {
        $teacherData = $this->clever->teacher($section['data']['teacher']);
        if (isset($section['data']['id'])) {
            // Here we are going to update the roster information
            $this->upsertSection($section['data'], $teacherData->data['data']);
            if ($this->option('debug')) {
                $this->info('Roster created/updated...');
            }
        } else {
            $this->error('No id for Roster.');
        }
    }
}
