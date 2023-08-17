<?php

namespace LGL\Clever\Commands;

/*
 * Recreated by PhpStorm.
 * User: pmoon
 * Date: 09/25/17
 * Time: 2:29 PM
 */

use Calc;
use Carbon\Carbon;
use LGL\Clever\Api;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use LGL\Core\Accounts\Models\Address;
use LGL\Core\Accounts\Models\Client;
use LGL\Core\Accounts\Models\Site as Sites;
use LGL\Auth\Users\EloquentUser;
use LGL\Core\Models\Course as Courses;
use LGL\Core\Models\District;
use LGL\Core\Models\Grades;
use LGL\Core\Models\Period as Periods;
use LGL\Core\Models\Subject as Subjects;
use LGL\Core\Models\Term as Terms;
use LGL\Core\Rosters\Models\Roster;
use LGL\Core\Models\Metadata;


/**
 * Exceptions to be thrown
 */
use LGL\Clever\Exceptions\EmailInUse;
use LGL\Clever\Exceptions\ExceededEmailCount;
use LGL\Clever\Exceptions\ExceededCleverIdCount;
use LGL\Clever\Exceptions\CleverIdMissMatch;
use LGL\Clever\Exceptions\EmailMissMatch;
use LGL\Clever\Exceptions\CleverNullUser;

class CleverSync extends Command
{
    public $settings;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $redisKey;

    /**
     * @var Api
     */
    protected $clever;

    protected $districts = [];
    protected $schools   = [];
    protected $teachers  = [];
    protected $students  = [];
    protected $preferences;
    protected $limit;
    protected $districtId;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:sync 
        {clientId : The client ID to begin syncing} 
        {--debug : reset to the event id we had stored } 
        {--limit=10000 :  limit how many records we will get in this run from clever} 
        {--schoolslimit=10000 : limit how many clever schools we will process under this client}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command is a defacto full sync. This will need a mechanism to lock users out till Job is complete.';

    /**
     * Create a new command instance.
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
        if ($this->option('debug')) {
            // $this->lastEvent();
        } else {
            $this->limit = $this->option('limit');
            $clientId    = (int) $this->argument('clientId');

            // Get Clients key
            $this->client   = $this->verify($clientId);
            $this->settings = config('settings');
            $this->redisKey = $this->client->id . ':' . 'clever_sync';
            $this->clever   = new Api($this->client->metadata->data['api_secret']);
            $this->setPreferneces();
            $this->districts();
            $this->admins();
            $this->sites();
            $this->schoolAdmins();
            $this->teachers();
            $this->students();
            $this->sections();
            $this->client->synced_on = Carbon::now()->toDateTimeString();
            $this->client->save();
        }
        $this->info('Sync Complete');
        return true;
    }

    private function verify($clientId)
    {
        try {
            $client = Client::find($clientId);

            if ($client->deleted_at === null && $client->partner_id === 1) {
                return $client;
            }
        } catch (\Exception $e) {
            $this->error('Client not found or is not a Clever Client');
            exit;
        }
        

        throw new \Exception('Client not found or is not a Clever Client');
    }

    public function districts()
    {
        $cleverDistricts = $this->clever->districts();
        $this->output->note('Syncing ' . count($cleverDistricts['data']) . ' District\'s Information...');
        $bar = $this->output->createProgressBar(count($cleverDistricts['data']));
        foreach ($cleverDistricts['data'] as $cleverDistrict) {
            // Check Clever ID through MetaData... the relationship is missleading.

            $this->districtId = $cleverDistrict['data']['id'];
            /** @noinspection PhpUndefinedMethodInspection */
            $cleverDistrictData = $this->clever->district($cleverDistrict['data']['id']);
            /** @noinspection PhpUndefinedMethodInspection */
            $lastEventId = $cleverDistrictData->getEvents(['limit' => '1', 'ending_before' => 'last']);

            /**
             * Check if district exists
             */
            if($this->cleverIdExists($cleverDistrict['data']['id'], Metadata::$metableClasses['districts'])) {
                // Check we have a district matching the clever id?
                $createDistrict = District::where('client_id', $this->client->id)->with(['metadata' => function($q) use ($cleverDistrict) {
                    $q->ofCleverId($cleverDistrict['data']['id']);
                }])->first();
            }
            else {
                $createDistrict = new District();
                /* @noinspection PhpUndefinedFieldInspection */
                $createDistrict->title = trim($cleverDistrict['data']['name']);
                /* @noinspection PhpUndefinedFieldInspection */
                $createDistrict->client_id = $this->client->id;
                $createDistrict->save();
            }
            
            
            $data = [
                'mdr_number' => ($cleverDistrict['data']['mdr_number']) ?? null,
                'clever_id'  => $cleverDistrict['data']['id'],
                'partner_id' => 1,
                'last_event' => $lastEventId,
            ];
            $createDistrict->setMetadata($data);

            /* @noinspection PhpUndefinedFieldInspection */
            $this->districts[$cleverDistrict['data']['id']] = $createDistrict->id;
            $bar->advance();
        }
        $bar->finish();
        $this->output->newLine();
    }

    public function admins()
    {
        $admins = $this->clever->districtAdmins();
        $this->output->note('Processing ' . count($admins['data']) . ' district admins...');
        $bar = $this->output->createProgressBar(count($admins['data']));
        foreach ($admins['data'] as $cleverUser) {

            /**
             * Check if admin exists
             * All: We have to many users associated with the Clever Id | Count Exception: (Human Intervention) Handeled in the checks/exixts methods below
             * All: We have to many users associated with the eMail address | Count Exception: (Human Intervention) Handeled in the checks/exixts methods below
             * If: We have an ID match && email match then update the user
             * ElseIf: We have an ID match && eMails don't match Exception: Missmatch (Human Intervention)
             * ElseIf: We have an email match && no ID match Exception: Missmatch (Human Intervention)
             * Else: We have no ID match && no email match then create a new user
             */

            $user = $this->processCleverUserData($cleverUser);

            $user->first_name = $cleverUser['data']['name']['first'];
            $user->last_name  = $cleverUser['data']['name']['last'];
            $user->email      = $cleverUser['data']['email'];
            $user->save();
            $user->setMetadata(['clever_id' => $cleverUser['data']['id']]);
            $user->roles()->detach(2);
            $user->roles()->attach(2);
            $bar->advance();
        }

        $bar->finish();
        $this->output->newLine();
    }

    
    public function sites()
    {
        foreach ($this->districts as $clever_id => $district) {
            /** @noinspection PhpUndefinedMethodInspection */
            $district = $this->clever->district($clever_id);

            /** @noinspection PhpUndefinedMethodInspection */
            $object = $district->getSchools(['limit' => $this->option('schoolslimit')]);
            $this->output->note('Processing ' . count($object) . ' schools...');
            $bar = $this->output->createProgressBar(count($object));
            foreach ($object as $school) {
                /**
                 * Check if site exists - Limited on the check - maybe tolerate site names being the same?
                 * All: We have to many sites associated with the Clever Id | Count Exception: (Human Intervention) Handeled in the checks/exixts methods below
                 * If: We have an ID match | Update the site
                 * Else: We have no ID match | Create a new site
                 */
                if($this->cleverIdExists($school->data['id'], Metadata::$metableClasses['sites'])) {
                    // Check we have a site id matching the clever site id?
                    // $site = Sites::where('client_id', $this->client->id)->with(['metadata' => function($q) use ($school) {
                    //     $q->ofClever($school->data['id']);
                    // }])->get();
                    $site = Sites::where('client_id', $this->client->id)->ofClever($school->data['id'])->first();
                    $this->checkCleverIdMatch($site->metadata->data['clever_id'], $school->data['id']);
                }
                else {
                    $site             = new Sites();
                    $site->client_id  = $this->client->id;
                    $site->title      = $school->name;
                    /* @noinspection PhpUndefinedFieldInspection */
                    $site->edit = false;
                }

                if (empty($school->data['high_grade'])) {
                    $highGrade = null;
                } else {
                    $highGrade = $this->checkGrade($school->data['high_grade']) ?? null;
                }
                if (empty($school->data['low_grade'])) {
                    $lowGrade = null;
                } else {
                    $lowGrade = $this->checkGrade($school->data['low_grade']) ?? null;
                }

                $metaInformation               = $this->coreData($school, 'site', 1);
                $metaInformation['high_grade'] = $highGrade->id ?? null;
                $metaInformation['low_grade']  = $lowGrade->id ?? null;
                $address                       = new Address();
                $address->street_1             = $school->data['location']['address'] ?? null;
                $address->city                 = $school->data['location']['city'] ?? null;
                $address->state                = $school->data['location']['state'] ?? null;
                $address->zip_code             = $school->data['location']['zip'] ?? null;
                $address->save();
                
                $site->address_id = $address->id;
                $site->save();

                $site->setMetadata($metaInformation);
                $this->schools[$school->data['id']] = $site->id;
                $bar->advance();
            }
            $bar->finish();
        }
        $this->output->newLine();
    }

    // Should this be principles?
    public function schoolAdmins()
    {

        $admins = $this->clever->school_admins();
        $this->output->note('Processing ' . count($admins['data']) . ' school admins...');
        $bar    = $this->output->createProgressBar(count($admins['data']));
        foreach ($admins['data'] as $cleverUser) {
            /**
             * Check if schoolAdmin exists
             * All: We have to many users associated with the Clever Id | Count Exception: (Human Intervention) Handeled in the checks/exixts methods below
             * All: We have to many users associated with the eMail address | Count Exception: (Human Intervention) Handeled in the checks/exixts methods below
             * If: We have an ID match && email match then update the user
             * ElseIf: We have an ID match && eMails don't match Exception: Missmatch (Human Intervention)
             * ElseIf: We have an email match && no ID match Exception: Missmatch (Human Intervention)
             * Else: We have no ID match && no email match then create a new user
             */
            $user = $this->processCleverUserData($cleverUser);
            
            $metadata         = ['staff_id' => $cleverUser['data']['staff_id'], 'clever_id' => $cleverUser['data']['id']];
            $user->first_name = $cleverUser['data']['name']['first'];
            $user->last_name  = $cleverUser['data']['name']['last'];
            $user->email      = $cleverUser['data']['email'];
            // Save user
            $user->save();
            $user->setMetadata($metadata);
            $user->roles()->detach(2);
            $user->roles()->attach(2);
            $attachToSchools = [];
            foreach($cleverUser['data']['schools'] as $schoolCleverId) {
                (array_key_exists($schoolCleverId, $this->schools)) ? array_push($attachToSchools, $this->schools[$schoolCleverId]) : null;
            }
            $user->sites()->sync($attachToSchools);
            $bar->advance();
        }
        $bar->finish();
        $this->output->newLine();
    }

    public function teachers()
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $district = $this->clever->district($this->districtId);
//        $directTeachers = $this->clever->teacher();
//        $teachers = $this->clever->teachers();
        /** @noinspection PhpUndefinedMethodInspection */
        $object = $district->getTeachers(['limit' => $this->limit]);
        if (count($object) >= 1 & $object[0]->id !== null) {
            $this->output->note('Processing ' . count($object) . ' teachers...');
            echo "\n";
            $bar = $this->output->createProgressBar(count($object));
            foreach ($object as $cleverUser) {
                if (!is_null($cleverUser->id)) {

                    /**
                     * Check if admin exists
                     * All: We have to many users associated with the Clever Id | Count Exception: (Human Intervention) Handeled in the checks/exixts methods below
                     * All: We have to many users associated with the eMail address | Count Exception: (Human Intervention) Handeled in the checks/exixts methods below
                     * If: We have an ID match && email match then update the user
                     * ElseIf: We have an ID match && eMails don't match Exception: Missmatch (Human Intervention)
                     * ElseIf: We have an email match && no ID match Exception: Missmatch (Human Intervention)
                     * Else: We have no ID match && no email match then create a new user
                     */
                    /** Hacked to work with every other users for Fsake */
                    $cleverUserArray['data'] = $cleverUser->data;
                    $user = $this->processCleverUserData($cleverUserArray);

                    $data = $this->coreData($cleverUser, 'teacher', 1);
                    /* @noinspection PhpUndefinedFieldInspection */
                    $data['created by'] = 'clever';
                    $user->save();
                    $user->setMetadata($data);
                    $user->roles()->detach(3);
                    $user->roles()->attach(3);
                    /* @noinspection PhpUndefinedMethodInspection */
                    $attachToSchools = [];
                    foreach($cleverUserArray['data']['schools'] as $schoolCleverId) {
                        (array_key_exists($schoolCleverId, $this->schools)) ? array_push($attachToSchools, $this->schools[$schoolCleverId]) : null;
                    }
                    $user->sites()->sync($attachToSchools);
                    $this->teachers[$cleverUser->id] = $user->id;
                }
                $bar->advance();
            }
            $bar->finish();
        } else {
            /* @noinspection PhpUndefinedFieldInspection */
            $this->output->note('No Teacher Information to Sync!');
        }
        $this->output->newLine();
    }

    public function students()
    {
//        $this->output->note('Syncing Student Information...');
        $district = $this->clever->district($this->districtId);
        /** @noinspection PhpUndefinedMethodInspection */
        $object = $district->getStudents(['limit' => $this->limit]);

        if (count($object) >= 1 && $object[0]->id !== null) {
            /* @noinspection PhpUndefinedFieldInspection */
            $this->output->note('Processing ' . count($object) . ' students...');
            $bar = $this->output->createProgressBar(count($object));
            foreach ($object as $cleverUser) {
                if (!is_null($cleverUser->id)) {
                    $dob                   = new Carbon($cleverUser->data['dob']);
                    $data                  = $this->coreData($cleverUser, 'student', 1);
                    $data['date_of_birth'] = $dob->toDateString();
                    $data['delta']         = Calc::driver('delta')->calc([
                        'date_of_birth' => $dob->toDateString(),
                        'grade'         => (int) $cleverUser->data['grade'] ?? null,
                        'client'        => $this->client
                    ]);
                    $data['updated by'] = 'clever';
                    $cleverUserArray['data'] = $cleverUser->data;
                    $cleverUserArray['data']['foreign_id'] = $data['foreign_id'];
                    

                    $user = $this->processCleverUserData($cleverUserArray);

                    // $user->username = strtolower($this->getUsername($cleverUserArray, 'user'));
                    // /* @noinspection PhpUndefinedMethodInspection */
                    // $user->password  = Crypt::encrypt($this->getPassword($cleverUserArray, 'user'));
                    // $user->client_id = $this->client->id;
                    // Save user
                    $user->save();
                    $user->setMetadata($data);
                    $user->roles()->detach(4);
                    $user->roles()->attach(4);
                    /* @noinspection PhpUndefinedMethodInspection */
                    $attachToSchools = [];
                    foreach($cleverUserArray['data']['schools'] as $schoolCleverId) {
                        (array_key_exists($schoolCleverId, $this->schools)) ? array_push($attachToSchools, $this->schools[$schoolCleverId]) : null;
                    }
                    $user->sites()->sync($attachToSchools);
                    $this->students[$cleverUser->id] = $user->id;
                }
                $bar->advance();
            }
            $bar->finish();
        } else {
            /* @noinspection PhpUndefinedFieldInspection */
            $this->output->error('No Student Information to Sync!');
        }
        $this->output->newLine();
    }

    public function sections()
    {
        
        $district = $this->clever->district($this->districtId);
        /** @noinspection PhpUndefinedMethodInspection */
        $sections = $district->getSections(['limit' => $this->limit]);
        if (count($sections) >= 1 && $sections[0]->id !== null) {
            /* @noinspection PhpUndefinedFieldInspection */
            $this->output->note('Processing ' . count($sections) . ' sections...');
            $bar = $this->output->createProgressBar(count($sections));
            foreach ($sections as $section) {
                $data = $this->coreData($section, 'section', 1);
                if (!is_null($section->id) & !empty($data['teachers']) & !empty($data['teacher']) && !empty($this->teachers[$data['teacher']])) {
                    /**
                     * Check if roster exists
                     * All: We have to many rosters associated with the Clever Id | Count Exception: (Human Intervention) Handeled in the checks/exixts methods below
                     * If: We have an ID match update the section
                     * Else: We have no ID match create the section
                     */
                    if ($this->cleverIdExists($section->data['id'], Metadata::$metableClasses['rosters'])) {
                        $roster = Roster::where('client_id', $this->client->id)->with(['metadata' => function($q) use ($section) {
                            $q->ofCleverId($section->data['id']);
                        }])->first();
                    } else {
                        $roster = new Roster();
                    }

                    /** Process the fuck out of the data... */
                    $term = (isset($data['term_id'])) ? $this->checkTerm($data['term_id']) : null;
                    $subject = (isset($data['subject_id'])) ? $this->checkSubject($data['subject']): null;
                    $course = (isset($data['course']) && isset($data['number'])) ? $this->checkCourse($data['name'], $data['number']) : null; 
                    $period = (isset($data['period'])) ? $this->checkPeriod($data['period']) : null;

                    // Build Metadata
                    $metadata        = [
                        'subject_id' => $subject->id ?? null,
                        'term_id'    => $term->id ?? null,
                        'course_id'  => $course->id ?? null,
                        'period_id'  => $period->id ?? null,
                        'sis_id'     => $data['sis_id'] ?? null,
                        'clever_id'  => $section->id ?? null,
                        'created by' => 'clever'
                    ];

                    if (!isset($termInformation['start_date']) || !isset($termInformation['end_date'])) {
                        $startDateString = null;
                        $endDateString = null;
                        $description = 'Term: N/A';
                    } else {
                        $startDate        = new Carbon($termInformation['start_date']);
                        $startDateString = $startDate->toDateTimeString();
                        $endDate          = new Carbon($termInformation['end_date']);
                        $endDateString   = $endDate->toDateTimeString();
                        $description = $termInformation['name'] . ', ' . $startDateString;
                    }

                    $roster->type_id   = 1;
                    $roster->title     = $data['name'] . 'Start:' . $startDateString . ' (Clever)';
                    $roster->user_id   = $this->teachers[$data['teacher']];
                    $roster->site_id   = $this->schools[$data['school']];
                    $roster->client_id = $this->client->id;
                    /* @noinspection PhpUndefinedFieldInspection */
                    $roster->writeable = false;
                    /* @noinspection PhpUndefinedFieldInspection */
                    $roster->start_date = $startDateString;
                    /* @noinspection PhpUndefinedFieldInspection */
                    $roster->end_date = $endDateString;
                    /* @noinspection PhpUndefinedFieldInspection */
                    $roster->description = $description;
                    $roster->save();
                    $roster->setMetadata($metadata);
                    // Attach Teachers to Rosters
                    $teachers = [];
                    foreach ($data['teachers'] as $cleverTeacherId) {
                        array_push($teachers, $this->teachers[$cleverTeacherId]);
                    }
                    $roster->access()->detach();
                    $roster->access()->attach($teachers);
                    // Attach Students to Roster
                    $students = [];
                    foreach ($data['students'] as $cleverStudentId) {
                        if (!empty($this->students[$cleverStudentId])) {
                            array_push($students, $this->students[$cleverStudentId]);
                        }
                    }
                    $roster->users()->detach();
                    $roster->users()->attach($students);

                } else {
                    /* @noinspection PhpUndefinedFieldInspection */

                }
                $bar->advance();
            }
            $bar->finish();
        } else {
            $this->info('No Section/Class Information!');
        }
        $this->output->newLine();
    }

    /**
     * @param $object
     * @param $type
     * @param $pid
     * @return array
     */
    public function coreData($object, $type, $partnerId)
    {
        if ($type === 'section') {
            $return = $object->data;
        } else {
            $basicData = [
                'clever_id'  => $object->id,
                'state_id'   => $object->data['state_id'] ?? null,
                'partner_id' => $partnerId,
            ];

            switch ($type) {
                case 'student':
                    $data = [
                        'foreign_id'         => $this->getForeignId($object->data),
                        'hispanic_ethnicity' => (isset($object->data['hispanic_ethnicity']) ? $object->data['hispanic_ethnicity'] : null),
                        'sis_id'             => $object->data['sis_id'] ?? null,
                        'iep_status'         => $object->data['iep_status'] ?? null,
                        'ell_status'         => $object->data['ell_status'] ?? null,
                        'email'              => $object->data['email'] ?? null,
                        'frl_status'         => $object->data['frl_status'] ?? null,
                        'grade'              => $object->data['grade'] ?? null,
                        'race'               => $object->data['race'] ?? null,
                        'student_number'     => $object->data['student_number'] ?? null,
                        'gender'             => $object->data['gender'],
                    ];
                    break;
                case 'teacher':
                    $data = [
                        'sis_id'    => $object->data['sis_id'] ?? null,
                        'title'          => $object->data['title'] ?? null,
                        'teacher_number' => $object->data['teacher_number'] ?? null,
                    ];
                    break;
                case 'site':
                    $data = [
                        'phone'         => $object->data['phone'] ?? null,
                        'school_number' => $object->data['school_number'] ?? null,
                        'nces_id'       => $object->data['nces_id'] ?? null,
                    ];
                    break;
                default:
                    $data = [];
            }
            $return = array_merge($basicData, $data);
        }

        return $return;
    }

    public function setPreferneces()
    {
        foreach ($this->client->preferences as $preference) {
            $this->preferences[$preference->key] = $preference->value;
        }
    }

    public function getUsername($array, $type = null)
    {
        $key = 'admins.interpolate.usernames';
        if ($type === 'user') {
            $key = 'user.interpolate.usernames';
        }
        $pattern = config('settings')[$key][$this->preferences[$key]];
        switch ($pattern) {
            case 'email':
                return (isset($array['data']['email'])) ? $array['data']['email'] : null;
            case 'filifi':
                return substr($array['data']['name']['first'], 0, 1) . substr($array['data']['name']['last'], 0, 1) . $array['data']['foreign_id'];
            case 'fnlidob':
                // Format mmdd
                $dob = new Carbon($array['data']['dob']);
                return strtolower($array['data']['name']['first']) . substr($array['data']['name']['last'], 0, 1) . $dob->format('md');
            case 'fndob':
                // Format mmddyy
                $dob = new Carbon($array['data']['dob']);

                return $array['data']['name']['first'] . $dob->format('mdY');
            case 'fnfi':
                return $array['data']['name']['first'] . $array['data']['foreign_id'];
        }

        return $this;
    }

    public function getPassword($array, $type = null)
    {
        $key = 'admins.interpolate.passwords';
        if ($type === 'user') {
            $key = 'user.interpolate.passwords';
        }
        $pattern = config('settings')[$key][$this->preferences[$key]];
        switch ($pattern) {
            case 'dob-mmddyy':
                $dob = new Carbon($array['data']['dob']);

                return $dob->format('mdy');
            case 'dob-mmddyyyy':
                $dob = new Carbon($array['data']['dob']);

                return $dob->format('mdY');
            case 'fnli':
                return strtolower($array['data']['name']['first'] . substr($array['data']['name']['last'], 0, 1));
            case 'filn':
                return strtolower(substr($array['data']['name']['first'], 0, 1) . $array['data']['name']['last']);
            case 'ln':
                return strtolower($array['data']['name']['last']);
            case 'randnum':
                return '1234';
                break;
            case 'fixed':
                return $this->preferences['user.static.password'];
                break;
        }

        return $this;
    }

    public function getForeignId($array)
    {
        $option = config('settings')['user.interpolate.foreign_ids'][$this->preferences['user.interpolate.foreign_ids']];
        if ($option !== 'none') {
            return $array[$option];
        }

        return '';
    }

    public function checkGrade($grade)
    {
        return Grades::firstOrCreate(['name' => $grade, 'slug' => strtolower($grade), 'value' => intval($grade)]);
    }

    public function checkTerm($term)
    {
        /* @noinspection PhpUndefinedMethodInspection */
        return Terms::firstOrCreate(['name' => $term, 'slug' => strtolower($term)]);
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

    /**
     * HACKS!!!!!
     * 
     * This is probably not the best way to do this, but it works for now.
     */
    public function cleverIdExists($cleverId, $metabletype) {
        /* @noinspection PhpUndefinedMethodInspection */
        
        if (Metadata::ofCleverId($cleverId)->where('metable_type', $metabletype)->count() > 1) {
            throw new ExceededCleverIdCount('Clever ID exists more than once in the system. ID: ' . $cleverId . ' .');
        }
        return (Metadata::ofCleverId($cleverId)->where('metable_type', $metabletype)->first()) ? true : false;
    }

    public function emailExists($email) {
        if ($email !== '' && EloquentUser::where('email', $email)->count() > 1) {
            throw new ExceededEmailCount('Email exists more than once in the system. Email: ' . $email . ' .');
        }
        return (EloquentUser::where('email', $email)->first()) ? true : false;
    }

    public function emailInUse($email) {
        if (EloquentUser::where('email', $email)->first()) {
            throw new EmailInUse('Email exists more than once in the system. Email: ' . $email . ' .');
        }
        return $this;
    }

    public function checkCleverIdMatch($syncCleverId, $systemCleverId) {
        if ($syncCleverId !== $systemCleverId) {
            throw new CleverIdMissmatch('Clever ID mismatch. Sync ID: ' . $syncCleverId . '. System ID: ' . $systemCleverId . ' .');
        }
        return false;
    }

    public function checkEmailMatch($syncEmail, $systemEmail) {
        if ($syncEmail !== $systemEmail) {
            throw new EmailMissmatch('Email mismatch. Sync Email: ' . $syncEmail . '. System Email: ' . $systemEmail . ' .');
        }
        return $this;
    }

    public function processCleverUserData($cleverUser) {

        if ($this->cleverIdExists($cleverUser['data']['id'], Metadata::$metableClasses['users'])) {
            $metadata = Metadata::ofCleverId($cleverUser['data']['id'])->where('metable_type', Metadata::$metableClasses['users'])->first();
            $user = EloquentUser::withTrashed()->where('id', $metadata->metable_id)->first();
            if (!is_null($metadata) & is_null($user)) {
                throw new Exception('Clever ID exists in MetaData, but no user found. ID: ' . $cleverUser['data']['id'] . ' Name: '.$cleverUser['data']['name']['first']. ' ' . $cleverUser['data']['name']['last']. ' | eMail: '. $cleverUser['data']['email']  .'. Metadata Record Present.');
            }
            if ($user->trashed()) {
                $user->restore();
            }
        }
        else if($this->cleverIdExists($cleverUser['data']['id'], Metadata::$metableClasses['users']) && $this->emailExists($cleverUser['data']['email'])) {
            // Check we have a district matching the clever id?
            $user = EloquentUser::withTrashed()->where('email', $cleverUser['data']['email'])
            ->where('client_id', $this->client->id)
            ->with('metadata')->first();
            if (is_null($user->metadata)) {
                throw new Exception('Clever ID exists, but no metadata found due to empty email. ID: ' . $cleverUser['data']['id'] . ' Name: '.$cleverUser['data']['name']['first']. ' ' . $cleverUser['data']['name']['last']. ' | eMail: '. $cleverUser['data']['email']  .'. Usually indicates a duplicate User record. One is missing the email.');
            }
            if ($user->metadata->exists() && isset($user->metadata->data['clever_id'])) {
                ($user->metadata->exists()) ? $this->checkCleverIdMatch($cleverUser['data']['id'], $user->metadata->data['clever_id']) : null ;
            }
            $this->checkEmailMatch($cleverUser['data']['email'], $user->email);
        }
        else {
            $user = new EloquentUser();
            $user->username   = strtolower($this->getUsername($cleverUser));
            /* @noinspection PhpUndefinedMethodInspection */
            $user->password  =  Hash::make($this->getPassword($cleverUser));
            $user->client_id = $this->client->id;
        }
        if (!is_null($user)) {
            $user->first_name = $cleverUser['data']['name']['first'];
            $user->last_name  = $cleverUser['data']['name']['last'];
            $user->email = ($cleverUser->data['email'] ?? null);
            return $user;

        }
        throw new CleverNullUser('Clever ID exists, but no user found/null. ID: ' . $cleverUser['data']['id'] . ' Name: '.$cleverUser['data']['name']['first']. ' ' . $cleverUser['data']['name']['last']. ' | eMail: '. $cleverUser['data']['email']  .'. Usually indicates a duplicate User record. One is missing the email.');


    }
}
