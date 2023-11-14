<?php

namespace LGL\Clever\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LGL\Auth\Users\EloquentUser;
use LGL\Clever\Exceptions\CleverNullUser;
use LGL\Clever\Traits\ProcessCleverUserTrait;
use LGL\Core\Accounts\Models\Client;
use Carbon\Carbon;
use LGL\Core\Models\Course as Courses;
use LGL\Core\Models\Period as Periods;
use LGL\Core\Models\Subject as Subjects;
use LGL\Core\Rosters\Models\Roster;
use LGL\Core\Accounts\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Batchable;

class ProcessSectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ProcessCleverUserTrait, Batchable;

    protected $cleverSection;
    protected $client;
    protected $schools;

    public $tries = 1;

    public function __construct($cleverSection, $clientId)
    {

        $this->onQueue('clever_full_sync');
        $cleverSection = json_decode($cleverSection);
        $sectionData = json_decode(json_encode($cleverSection->data), true);
        $cleverSection->data = $sectionData;
        $this->cleverSection = $cleverSection;
        $this->client = $clientId;
    }

    public function handle()
    {
        $this->client = Client::find($this->client);
        $roster = $this->findOrCreateRoster($this->cleverSection);

        $this->syncTeachersToRoster($roster, $this->cleverSection->data['teachers']);
        $this->syncStudentsToRoster($roster, $this->cleverSection->data['students']);
    }

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

        $roster = Roster::where('client_id', $this->client->id)
            ->whereHas('metadata', function ($q) use ($section) {
                $q->where('data->clever_id', $section->data['id']);
            })
            ->with('metadata')  // This is optional, only if you want to eager-load metadata
            ->first();

        if ($roster === null) {
            $roster = new Roster();
            $roster->type_id = 1;
        }

        $site = Site::whereClientId($this->client->id)->whereHas('metadata', function ($q) use ($section) {
            $q->where('data->clever_id', $section->data['school']);
        })->first();

        // Pulls Site ID from an Array of Sites collected
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

    public function buildRosterMetadata($section)
    {
        // Clever Data Start Dates

        $subject = (isset($section->data['subject'])) ? $this->checkSubject($section->data['subject']) : null;
        $period = (isset($section->data['period'])) ? $this->checkPeriod($section->data['period']) : null;
        $course = (isset($section->data['course']) && isset($section->data['number'])) ? $this->checkCourse($section->data['name'], $section->data['number']) : null;


        $metadata = [
            'subject_id' => $subject->id ?? null,
            'course_id' => $course->id ?? null,
            'period_id' => $period->id ?? null,
            'sis_id' => $section->data['sis_id'] ?? null,
            'clever_id' => $section->data['id'] ?? null,
            'created_by' => 'Clever Process - Client Sync - CLI',
            'clever_data' => $section->data,
        ];

        return $metadata;
    }

    private function syncTeachersToRoster($roster, $teacherCleverIds)
    {
        $teachers = EloquentUser::whereClientId($this->client->id)->whereHas('metadata', function ($q) use ($teacherCleverIds) {
            $q->whereIn('data->clever_id', $teacherCleverIds);
        })->get();

        $teacherIds = $teachers->pluck('id')->toArray();

        $roster->access()->attach($teacherIds);
    }

    private function syncStudentsToRoster($roster, $studentCleverIds)
    {
        $students = EloquentUser::whereClientId($this->client->id)->whereHas('metadata', function ($q) use ($studentCleverIds) {
            $q->whereIn('data->clever_id', $studentCleverIds);
        })->get();

        $studentIds = $students->pluck('id')->toArray();
        $roster->users()->sync($studentIds);
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

}
