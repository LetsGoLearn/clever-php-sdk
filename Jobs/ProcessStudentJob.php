<?php

namespace LGL\Clever\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LGL\Clever\Traits\ProcessCleverUserTrait;
use LGL\Core\Accounts\Models\Client;
use Carbon\Carbon;
use Calc;

class ProcessStudentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ProcessCleverUserTrait;

    protected $cleverUser;
    protected $client;

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
        $dob = new Carbon($this->cleverUser->data['dob']);
        $data = $this->coreData($this->cleverUser, 'student', 1); // Assuming coreData is a shared method
        $data = array_merge($data, [
            'date_of_birth' => $dob->toDateString(),
            'delta' => Calc::driver('delta')->calc([
                'date_of_birth' => $dob->toDateString(),
                'grade' => (int)$this->cleverUser->data['grade'] ?? null,
                'client' => $this->client->id
            ]),
            'updated by' => 'clever',
            'clever_information' => $this->cleverUser->data
        ]);

        $cleverUserArray['data'] = $this->cleverUser->data;
        $cleverUserArray['data']['foreign_id'] = $data['foreign_id'];

        $user = $this->processCleverUserData($cleverUserArray, 'student');
        $this->updateStudentDetails($user, $data);

        $attachToSchools = $this->getSchoolsToAttach($cleverUserArray['data']['schools']);
        $user->sites()->sync($attachToSchools);

        $user->save();

    }
    private function updateStudentDetails($user, $data)
    {
        $user->setMetadata($data);
        $user->roles()->syncWithoutDetaching([4]);
    }
}
