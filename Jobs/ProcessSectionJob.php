<?php

namespace LGL\Clever\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LGL\Auth\Users\EloquentUser;
use LGL\Clever\Exceptions\CleverNullUser;
use LGL\Clever\Exceptions\ExceededCleverIdCount;
use LGL\Clever\Traits\ProcessCleverUserTrait;
use LGL\Core\Accounts\Models\Client;
use Carbon\Carbon;
use LGL\Core\Models\Course as Courses;
use LGL\Core\Models\Metadata;
use LGL\Core\Models\Period as Periods;
use LGL\Core\Models\Subject as Subjects;
use LGL\Core\Rosters\Models\Roster;
use LGL\Core\Accounts\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Batchable;

class ProcessSectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ProcessCleverUserTrait, Batchable;

    /* Steps to Process a roster from Clever
    / 1. Get Teacher or Fail
    / 2. Get Site or Fail
    / 3. Get Roster or Create / Set Metadata
    / 4. Sync Students
    / 5. Sync Teachers
    */

    protected $cleverSection;
    protected $client;
    protected $schools;
    protected $site;
    protected $teacher;
    protected $roster;
    public $tries = 1;

    public function __construct($cleverSection, $clientId)
    {

        // Set the queue name
        $this->onQueue('default');
        
        // Data sent from Clever, set the JSON Object for usage during processing.
        $this->cleverSection = json_decode($cleverSection);
        
        // Set the client object to an int, is replaced by the actual client during processing
        $this->client = $clientId;
    }

    public function handle()
    {
        $this->client = Client::find($this->client);
        $this->checkCleverIdCount();
        $this->setSite($this->cleverSection->data->school);
        $this->setPrimaryTeacher($this->cleverSection->data->teacher);
        $this->setRoster($this->cleverSection->data->id);
        $this->syncStudents($this->cleverSection->data->students);
        $this->syncTeachers($this->cleverSection->data->teachers);
    }

    private function setSite(string $siteCleverId) {
    
        // whereHas() is expensive but works, needs to be adjusted.
        $this->site = Site::whereClientId($this->client->id)->whereHas('metadata', function ($q) use ($siteCleverId) {
            $q->where('data->clever_id', $siteCleverId);
        })->first();
        
        // If its null we should log it and move on. Shouldn't matter if we need to create a floating roster to sync later.
        // This is a good solution for fewer processes later.
        if ($this->site === null) {
            $this->logMessage(['Clever', 'Missing Site', 'Roster Sync'], "Could not find the site with Clever Id: ". $siteCleverId . " | Client Id:" . $this->client->id);
            return;
        }
    }
    
    private function setRoster(string $cleverRosterId) {
        $roster = Roster::where('client_id', $this->client->id)
            ->whereHas('metadata', function ($q) use ($cleverRosterId) {
                $q->where('data->clever_id', $cleverRosterId);
            })
            ->with('metadata')  // This is optional, only if you want to eager-load metadata
            ->first();
        if ($roster === null) {
            $roster = new Roster();
            $roster->type_id = 1;
        }
        $roster->client_id = $this->client->id;
        $roster->writeable = false;
        $roster->user_id = $this->teacher->id;
        $roster->site_id = $this->site->id;
        $roster->deleted_at = null;
        $roster->title = $this->cleverSection->data->name . ' (Clever)';
        $roster->description = "Clever Sync Job";
        $roster->save();

        $roster->setMetadata($this->buildRosterMetadata($this->cleverSection));

        $this->roster = $roster;
    }

    private function setPrimaryTeacher($cleverPrimaryTeacherId) {
        $this->teacher = EloquentUser::whereClientId($this->client->id)->whereHas('metadata', function ($q) use ($cleverPrimaryTeacherId) {
            $q->where('data->clever_id', $cleverPrimaryTeacherId);
        })->first();
    }
    
    private function syncTeachers($teacherCleverIds)
    {
        $teachers = EloquentUser::whereClientId($this->client->id)->whereHas('metadata', function ($q) use ($teacherCleverIds) {
            $q->whereIn('data->clever_id', $teacherCleverIds);
        })->get();

        $teacherIds = $teachers->pluck('id')->toArray();

        $this->roster->access()->attach($teacherIds);
    }

    private function syncStudents($studentCleverIds)
    {
        $students = EloquentUser::whereClientId($this->client->id)->whereHas('metadata', function ($q) use ($studentCleverIds) {
            $q->whereIn('data->clever_id', $studentCleverIds);
        })->get();

        $studentIds = $students->pluck('id')->toArray();
        $this->roster->users()->sync($studentIds);
    }

    public function buildRosterMetadata($section) {
        $subject = (isset($section->data->subject)) ? $this->checkSubject($section->data->subject) : null;
        $period = (isset($section->data->period)) ? $this->checkPeriod($section->data->period) : null;
        $course = (isset($section->data->course) && isset($section->data->number)) ? $this->checkCourse($section->data->name, $section->data->number) : null;


        $metadata = [
            'subject_id' => $subject->id ?? null,
            'course_id' => $course->id ?? null,
            'period_id' => $period->id ?? null,
            'sis_id' => $section->data->sis_id ?? null,
            'clever_id' => $section->data->id ?? null,
            'created_by' => 'Clever Process - Client Sync - CLI',
            'clever_data' => $section->data,
        ];

        return $metadata;
    }
    
    private function logMessage(array $tags, string $message) {
        Log::info('['.Carbon::now()->toDateTimeString().'][Clever][NullUser] : ' . $message);
    }

    public function checkSubject($subject)
    {
        /* @noinspection PhpUndefinedMethodInspection */
        return Subjects::firstOrCreate(['name' => $subject, 'slug' => strtolower($subject)]);
    }

    public function checkCourse($name, $slug)
    {
        /* @noinspection PhpUndefinedMethodInspection */
        return Courses::firstOrCreate(['name' => $name, 'slug' => $slug]);
    }

    public function checkPeriod($name)
    {
        /* @noinspection PhpUndefinedMethodInspection */
        return Periods::firstOrCreate(['name' => $name, 'slug' => strtolower($name)]);
    }

    // DEPRICATED
    
    private function findOrCreateRoster($section)
    {
        $roster = null;

        $startDate = (isset($section->data['start_date'])) ? new Carbon($section->data['start_date']) : null;
        $endDate = (isset($section->data['end_date'])) ? new Carbon($section->data['end_date']) : null;

        $teacher = EloquentUser::whereClientId($this->client->id)->whereHas('metadata', function ($q) use ($section) {
            $q->where('data->clever_id', $section->data['teacher']);
        })->first();

        if ($teacher === null) {
            Log::info('['.Carbon::now()->toDateTimeString().'][Clever][NullUser] '. 'Teacher not found for section ' . $section->data['name'] . ' (' . $section->data['id'] . ')' . ' in client ' . $this->client->id . '. Skipping roster creation.  | ' . json_encode($section));
            throw new CleverNullUser('Teacher not found for section ' . $section->data['name'] . ' (' . $section->data['id'] . ')' . ' in client ' . $this->client->id);
        }



        $site = Site::whereClientId($this->client->id)->whereHas('metadata', function ($q) use ($section) {
            $q->where('data->clever_id', $section->data['school']);
        })->first();

        // Pulls Site ID from an Array of Sites collected Ok lets fix this
        $roster->site_id = $site->id;
        $roster->client_id = $this->client->id;
        $roster->writeable = false;

        // These may not exist
        $roster->start_date = $startDate;
        $roster->end_date = $endDate;

        $roster->deleted_at = null;
        $rosterTitle = $section->data['name'];
        $rosterTitle = $rosterTitle . ' (Clever)';


        $roster->title = $rosterTitle;
        $roster->description = "";

        $roster->save();

        $roster->setMetadata($this->buildRosterMetadata($section));

        return $roster;
    }

    private function checkCleverIdCount() {
        $metadataRecords = Metadata::ofCleverId($this->cleverSection->data->id)->ofType('rosters');

        if ($metadataRecords->count() > 1) {
            throw new ExceededCleverIdCount();
        }
    }

}
