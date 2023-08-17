<?php

namespace LGL\Clever\Commands;

/*
 * Recreated by PhpStorm.
 * User: pmoon
 * Date: 09/25/17
 * Time: 2:29 PM
 */

use LGL\Clever\Commands\Exceptions\ExceededCleverIdCount;
use Calc;
use Carbon\Carbon;
use LGL\Clever\Api;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
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
use Illuminate\Support\Facades\Redis;
use LGL\Core\Models\Metadata;

class CleverNew extends Command
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
    protected $errorLog  = ['sites' => [], 'sections' => [], 'students' => [], 'teachers' => []];
    protected $runningLog = ['districts' => [], 'sites' => [], 'sections' => [], 'students' => [], 'teachers' => []];
    protected $status = null;
    protected $preferences;
    protected $limit;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:new 
        {clientId : The client ID to begin syncing} 
        {--debug : reset to the event id we had stored } 
        {--limit=10000 :  limit how many records we will get in this run from clever} 
        {--schoolslimit=10000 : limit how many clever schools we will process under this client}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Client to Clever.';

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

        return true;
    }

    private function verify($clientId)
    {
        $client = Client::find($clientId);
        if ($client->metadata->data['partner_id'] === 1 && $client->deleted_at === null) {
            return $client;
        }

        return $this;
    }

    public function districts()
    {
        $districts = $this->clever->districts();
        $this->output->note('Syncing ' . count($districts['data']) . ' District\'s Information...');
        $bar = $this->output->createProgressBar(count($districts['data']));
        foreach ($districts['data'] as $district) {
            /** @noinspection PhpUndefinedMethodInspection */
            $districtData = $this->clever->district($district['data']['id']);
            /** @noinspection PhpUndefinedMethodInspection */
            $lastEventId = $districtData->getEvents(['limit' => '1', 'ending_before' => 'last']);

            /**
             * Check if district exists
             */
            if($this->cleverIdExists($district['data']['id'], Metadata::$metableClasses['districts'])) {
                // Check we have a district matching the clever id?
                $createDistrict = District::where('client_id', $this->client->id)->with(['metadata' => function($q) use ($district) {
                    $q->ofCleverId($district['data']['id']);
                }])->first();
            }
            else {
                $createDistrict = new District();
                /* @noinspection PhpUndefinedFieldInspection */
                $createDistrict->title = trim($district['data']['name']);
                /* @noinspection PhpUndefinedFieldInspection */
                $createDistrict->client_id = $this->client->id;
                $createDistrict->save();
            }


            $data = [
                'mdr_number' => ($district['data']['mdr_number']) ?? null,
                'clever_id'  => $district['data']['id'],
                'partner_id' => 1,
                'last_event' => $lastEventId,
            ];
            $createDistrict->setMetadata($data);

            /* @noinspection PhpUndefinedFieldInspection */
            $this->districts[$district['data']['id']] = $createDistrict->id;
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
        foreach ($admins['data'] as $adminBah) {
            $admin = $adminBah['data'];

            /**
             * Check if admin exists
             */
            if($this->cleverIdExists($admin['id'], Metadata::$metableClasses['users'])) {
                // Check we have a district matching the clever id?
                $user = EloquentUser::where('email', $admin['email'])
                ->where('client_id', $this->client->id)
                ->with(['metadata' => function ($q) use ($admin) {
                    $q->ofCleverId($admin['id']);
                }])
                ->first();
            }
            else {
                $user = new EloquentUser();
                $user->username   = strtolower($this->getUsername($admin));
                /* @noinspection PhpUndefinedMethodInspection */
                $user->password  =  Hash::make($this->getPassword($admin));
                $user->client_id = $this->client->id;
            }

            $user->first_name = $admin['name']['first'];
            $user->last_name  = $admin['name']['last'];
            $user->email      = $admin['email'];
            $user->save();

            $user->setMetadata(['clever_id' => $admin['id']]);
            $user->roles()->detach(2);
            $user->roles()->attach(2);
            $bar->advance();
        }
        $bar->finish();
        $this->output->newLine();
        $this->output->note('Done.');
    }

    public function sites()
    {
        $this->output->note('Syncing Sites Information...');
        foreach ($this->districts as $clever_id => $district) {
            /** @noinspection PhpUndefinedMethodInspection */
            $district = $this->clever->district($clever_id);
            /** @noinspection PhpUndefinedMethodInspection */
            $object = $district->getSchools(['limit' => $this->option('schoolslimit')]);
            $this->output->note('Processing ' . count($object) . ' schools...');
            $bar = $this->output->createProgressBar(count($object));
            foreach ($object as $school) {
                /**
                * Check if district exists
                */
                if($this->cleverIdExists($school->data['id'], Metadata::$metableClasses['sites'])) {
                    // Check we have a district matching the clever id?
                    $site = Sites::where('client_id', $this->client->id)->with(['metadata' => function($q) use ($school) {
                        $q->ofCleverId($school->data['id']);
                    }])->first();
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
        $this->output->note('Done');
    }

    public function schoolAdmins()
    {
        $this->output->note('Syncing School Admins Information...');
        $admins = $this->clever->school_admins();
        $bar    = $this->output->createProgressBar(count($admins));
        foreach ($admins['data'] as $admin) {
            /**
             * Check if admin exists
             */
            if($this->cleverIdExists($admin['data']['id'], Metadata::$metableClasses['users'])) {
                // Check we have a district matching the clever id?
                $user = EloquentUser::where('email', $admin['data']['email'])
                ->where('client_id', $this->client->id)
                ->with(['metadata' => function ($q) use ($admin) {
                    $q->ofCleverId($admin['data']['id']);
                }])
                ->first();
            }
            else {
                $user = new EloquentUser();
                $user->username   = strtolower($this->getUsername($admin));
                /* @noinspection PhpUndefinedMethodInspection */
                $user->password  =  Hash::make($this->getPassword($admin));
                $user->client_id = $this->client->id;
            }

            $metadata         = ['staff_id' => $admin['data']['staff_id'], 'clever_id' => $admin['data']['id']];
            $user->first_name = $admin['data']['name']['first'];
            $user->last_name  = $admin['data']['name']['last'];
            $user->email      = $admin['data']['email'];
            // Save user
            $user->save();
            $user->setMetadata($metadata);
            $user->roles()->detach(2);
            $user->roles()->attach(2);
            foreach ($this->schools as $schoolId) {
                $site = Sites::find($schoolId);
                /* @noinspection PhpUndefinedMethodInspection */
                $site->users()->save($user);
            }
            $bar->advance();
        }
        $bar->finish();
        $this->output->newLine();
        $this->output->note('Done');
    }

    public function teachers()
    {
        $this->output->note('Syncing Teacher Information...');
        foreach ($this->schools as $cleverId => $schoolId) {
            /** @noinspection PhpUndefinedMethodInspection */
            $school = $this->clever->school($cleverId);
            /** @noinspection PhpUndefinedMethodInspection */
            $object = $school->getTeachers(['limit' => $this->limit]);
            $site   = Sites::find($schoolId);
            if (count($object) >= 1 & $object[0]->id !== null) {
                $this->output->note('Processing ' . count($object) . ' teachers for ' . $site->title . '...');
                $bar = $this->output->createProgressBar(count($object));
                foreach ($object as $teacher) {
                    if (!is_null($teacher->id)) {
                        if($this->cleverIdExists($teacher->id, Metadata::$metableClasses['users'])) {
                            // Check we have a district matching the clever id?
                            $user = EloquentUser::where('email', $teacher->email)
                            ->where('client_id', $this->client->id)
                            ->with(['metadata' => function ($q) use ($teacher) {
                                $q->ofCleverId($teacher->id);
                            }])
                            ->first();
                        }
                        else {
                            $user = new EloquentUser();
                            $user->first_name = $teacher->data['name']['first'];
                            $user->last_name  = $teacher->data['name']['last'];
                            $user->email      = $teacher->data['email'];
                            $user->username   = strtolower($this->getUsername($teacher->data));
                            /* @noinspection PhpUndefinedMethodInspection */
                            $user->password  =  Hash::make($this->getPassword($teacher->data));
                            $user->client_id = $this->client->id;
                        }



                        $data = $this->coreData($teacher, 'teacher', 1);
                        /* @noinspection PhpUndefinedFieldInspection */
                        $data['site_id']  = $site->id;
                        $data['created by'] = 'clever';

                        $user->save();
                        $user->setMetadata($data);
                        $user->roles()->detach(3);
                        $user->roles()->attach(3);
                        /* @noinspection PhpUndefinedMethodInspection */
                        $site->users()->attach($user->id);
                        $this->teachers[$teacher->id] = $user->id;
                    }
                    $bar->advance();
                }
                $bar->finish();
            } else {
                /* @noinspection PhpUndefinedFieldInspection */
                $this->output->error('No teachers processed for ' . $site->title . '.');
                unset($this->schools[$cleverId]);
                /* @noinspection PhpUndefinedFieldInspection */
                $this->errorLog[$site->id][] = 'No teachers found. Site removed from processing.';
            }
        }
        $this->info('Done');
    }

    public function students()
    {
        $this->output->note('Syncing Student Information...');
        foreach ($this->schools as $cleverId => $schoolId) {
            /** @noinspection PhpUndefinedMethodInspection */
            $school = $this->clever->school($cleverId);
            /** @noinspection PhpUndefinedMethodInspection */
            $object = $school->getStudents(['limit' => $this->limit]);
            $site   = Sites::find($schoolId);
            if (count($object) >= 1 && $object[0]->id !== null) {
                /* @noinspection PhpUndefinedFieldInspection */
                $this->output->note('Processing ' . count($object) . ' students for ' . $site->title . '...');
                $bar = $this->output->createProgressBar(count($object));
                foreach ($object as $student) {
                    if (!is_null($student->id)) {
                        $dob                   = new Carbon($student->data['dob']);
                        $data                  = $this->coreData($student, 'student', 1);
                        $data['date_of_birth'] = $dob->toDateString();
                        $data['delta']         = Calc::driver('delta')->calc([
                            'date_of_birth' => $dob->toDateString(),
                            'grade'         => (int) $student->data['grade'] ?? null,
                            'client'        => $this->client
                        ]);
                        $data['updated by'] = 'clever';
                        $tempArray        = $student->data;
                        $tempArray        = array_merge($tempArray, ['foreign_id' => $data['foreign_id']]);
                        $user             = new EloquentUser();
                        $user->first_name = $student->data['name']['first'];
                        $user->last_name  = $student->data['name']['last'];
                        if (isset($student->data['email'])) {
                            $user->email = $student->data['email'];
                        }
                        $user->username = strtolower($this->getUsername($tempArray, 'user'));
                        /* @noinspection PhpUndefinedMethodInspection */
                        $user->password  =  Hash::make($this->getPassword($tempArray, 'user'));
                        $user->client_id = $this->client->id;
                        // Save user
                        $user->save();
                        $user->setMetadata($data);
                        $user->roles()->attach(4);
                        /* @noinspection PhpUndefinedMethodInspection */
                        $site->users()->attach($user->id);
                        $this->students[$student->id] = $user->id;
                    }
                    $bar->advance();
                }
                $bar->finish();
            } else {
                /* @noinspection PhpUndefinedFieldInspection */
                $this->output->error('No students processed for ' . $site->title . '.');
                /* @noinspection PhpUndefinedFieldInspection */
                $this->errorLog[$site->id][] = 'No students found.';
            }
        }
        $this->info('Done');
    }

    public function sections()
    {
        $this->info('Syncing Section/Class Information...');
        foreach ($this->schools as $cleverId => $schoolId) {
            /** @noinspection PhpUndefinedMethodInspection */
            $school = $this->clever->school($cleverId);
            $site   = Sites::find($schoolId);
            /** @noinspection PhpUndefinedMethodInspection */
            $sections = $school->getSections(['limit' => $this->limit]);
            if (count($sections) >= 1 && $sections[0]->id !== null) {
                /* @noinspection PhpUndefinedFieldInspection */
                $this->info('Processing ' . count($sections) . ' sections for ' . $site->title . '...');
                $bar = $this->output->createProgressBar(count($sections));
                foreach ($sections as $section) {
                    $data = $this->coreData($section, 'section', 1);
                    if (!is_null($section->id) & !empty($data['teachers']) & !empty($data['teacher']) && !empty($this->teachers[$data['teacher']])) {

                        // $termInformation = $data['term_id'];
                        // $term            = $this->checkTerm($data['term_id']);
                        $subject         = $this->checkSubject($data['subject']);
                        // $course          = $this->checkCourse($data['course_name'], $data['course_number']);
                        $period          = (isset($data['period'])) ? $this->checkPeriod($data['period']) : null;
                        $metadata        = [
                            'subject_id' => $subject->id ?? null,
                            'term_id'    => null,
                            'course_id'  => null,
                            'period_id'  => $period->id ?? null,
                            'sis_id'     => $data['sis_id'] ?? null,
                            'clever_id'  => $section->id,
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
                        $roster            = new Roster();
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
                        $roster->access()->attach($teachers);
                        // Attach Students to Roster
                        $students = [];
                        foreach ($data['students'] as $cleverStudentId) {
                            if (!empty($this->students[$cleverStudentId])) {
                                array_push($students, $this->students[$cleverStudentId]);
                            }
                        }
                        $roster->users()->attach($students);
                    } else {
                        /* @noinspection PhpUndefinedFieldInspection */
                        $this->errorLog['sites'][$site->id][$data['id']] = 'No teacher associated with class.';
                    }
                    $bar->advance();
                }
                $bar->finish();
            } else {
                /* @noinspection PhpUndefinedFieldInspection */
                $this->error('No sections processed for ' . $site->title . '.');
                /* @noinspection PhpUndefinedFieldInspection */
                $this->errorLog['sites'][$site->id][] = 'No classes found.';
            }
        }
        $this->info('Done');
        /* @noinspection PhpUndefinedMethodInspection */
        Redis::connection('logs')->set($this->redisKey, json_encode((object) $this->errorLog));
    }

    /**
     * @param $object
     * @param $type
     * @param $pId
     * @return array
     */
    public function coreData($object, $type, $pId)
    {
        if ($type === 'section') {
            $return = $object->data;
        } else {
            $basicData = [
                'clever_id'  => $object->id,
                'state_id'   => $object->data['state_id'] ?? null,
                'partner_id' => $pId,
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
                        'sis_id'    => $teacher->data['sis_id'] ?? null,
                        'title'          => $teacher->data['title'] ?? null,
                        'teacher_number' => $teacher->data['teacher_number'] ?? null,
                    ];
                    break;
                case 'site':
                    $data = [
                        'phone'         => $school->data['phone'] ?? null,
                        'school_number' => $school->data['school_number'] ?? null,
                        'nces_id'       => $school->data['nces_id'] ?? null,
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
                return $array['email'];
            case 'filifi':
                return substr($array['name']['first'], 0, 1) . substr($array['name']['last'], 0, 1) . $array['foreign_id'];
            case 'fnlidob':
                // Format mmdd
                $dob = new Carbon($array['dob']);
                return strtolower($array['name']['first']) . substr($array['name']['last'], 0, 1) . $dob->format('md');
            case 'fndob':
                // Format mmddyy
                $dob = new Carbon($array['dob']);

                return $array['name']['first'] . $dob->format('mdY');
            case 'fnfi':
                return $array['name']['first'] . $array['foreign_id'];
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
                $dob = new Carbon($array['dob']);

                return $dob->format('mdy');
            case 'dob-mmddyyyy':
                $dob = new Carbon($array['dob']);

                return $dob->format('mdY');
            case 'fnli':
                return strtolower($array['name']['first'] . substr($array['name']['last'], 0, 1));
            case 'filn':
                return strtolower(substr($array['name']['first'], 0, 1) . $array['name']['last']);
            case 'ln':
                return strtolower($array['name']['last']);
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

        // if (Metadata::ofCleverId($cleverId)->where('metable_type', $metabletype)->count() > 1) {
        //     throw new ExceededCleverIdCount('Clever ID exists more than once in the system. ID: ' . $cleverId . '.', 1000);
        // }

        return (Metadata::ofCleverId($cleverId)->where('metable_type', $metabletype)->first()) ? true : false;
    }
}
