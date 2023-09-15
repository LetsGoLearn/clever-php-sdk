<?php

namespace LGL\Clever\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LGL\Clever\Traits\ProcessCleverUserTrait;
use LGL\Core\Accounts\Models\Client;

class ProcessTeacherJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ProcessCleverUserTrait;

    protected $cleverUser;
    protected $client;
    protected $schools;


    public function __construct($cleverUser, $clientId, $schoolIds)
    {
        $this->onQueue('clever_full_sync');
        $collection = collect([]);
        $collection->data = $cleverUser;
        $collection->id = $cleverUser['id'];
        $this->cleverUser = $collection;
        $this->client = $clientId;
        $this->schools = $schoolIds;
    }

    public function handle()
    {
        $this->client = Client::find($this->client);
        $this->setPreferneces();

        $cleverUserArray['data'] = $this->cleverUser->data;
        $user = $this->processCleverUserData($cleverUserArray);
        $data = $this->coreData($this->cleverUser, 'teacher', 1);
        $data['created_by'] = 'Clever Process - Client Sync - CLI';
        $data['clever_information'] = $cleverUserArray['data'];

        $this->updateTeacherDetails($user, $data);
        $attachToSchools = $this->getSchoolsToAttach($cleverUserArray['data']['schools']);
        $this->syncUserToSites($user, $attachToSchools);
    }

    private function updateTeacherDetails($user, $data)
    {
        $user->setMetadata($data);
        $user->roles()->syncWithoutDetaching([3]);
    }
}
