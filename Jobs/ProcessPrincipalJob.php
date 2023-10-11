<?php

namespace LGL\Clever\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LGL\Auth\Users\EloquentUser;
use LGL\Clever\Traits\ProcessCleverUserTrait;
use LGL\Core\Accounts\Models\Client;

class ProcessPrincipalJob implements ShouldQueue
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

        $userDataArray['data'] = $this->cleverUser->data;
        $user = $this->processCleverUserData($userDataArray, 'principal');
        $metadata = $this->getPrincipalMetadata();
        $this->updateUserDetails($user, $metadata);
        $attachToSchools = $this->getSchoolsToAttach($this->cleverUser->data['schools']);
        $this->syncUserToSites($user, $attachToSchools);
    }

    private function updateUserDetails(EloquentUser $user, $metadata)
    {

        $user->first_name = $this->cleverUser->data['name']['first'];
        $user->last_name = $this->cleverUser->data['name']['last'];
        $user->email = $this->cleverUser->data['email'];
        $user->save();
        $user->setMetadata($metadata);
        $user->roles()->syncWithoutDetaching([2]);
    }

    private function getPrincipalMetadata(): array
    {
        return [
            'staff_id' => $this->cleverUser->data['staff_id'],
            'clever_id' => $this->cleverUser->data['id'],
            'created_by' => 'Clever Process - Client Sync - CLI',
            'clever_information' => $this->cleverUser->data['id']
        ];
    }

}
