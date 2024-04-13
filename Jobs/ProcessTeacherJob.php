<?php

namespace LGL\Clever\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LGL\Clever\Exceptions\ExceededCleverIdCount;
use LGL\Clever\Traits\ProcessCleverUserTrait;
use LGL\Core\Accounts\Models\Client;
use LGL\Core\Models\Metadata;

class ProcessTeacherJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ProcessCleverUserTrait, Batchable;

    protected $cleverUser;
    protected $client;
    protected $schools;
    public $tries = 1;

    public function __construct($cleverUser, $clientId, $schoolIds)
    {
        $this->onQueue('default');
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

        $this->checkCleverIdCount();

        $cleverUserArray['data'] = $this->cleverUser->data;
        $user = $this->processCleverUserData($cleverUserArray, 'teacher');
        $data = $this->coreData($this->cleverUser, 'teacher', 1);
        $data['created_by'] = 'Clever Process - Client Sync - CLI';
        $data['foreign_id'] = $cleverUserArray['data']['teacher_number'];
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
