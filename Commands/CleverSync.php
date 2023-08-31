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
use LGL\Clever\Exceptions\Exception;
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
    protected Client $client;

    /**
     * @var string
     */
    protected string $redisKey;

    /**
     * @var Api
     */
    protected API $clever;

    protected array $districts = [];
    protected array $schools = [];
    protected array $teachers = [];
    protected array $students = [];
    protected $preferences;
    protected $limit = 0;


    /********** New Variables **********/
    protected ?string $districtId = null;
    protected array $cleverDistrictData;
    protected ?string $lastEventId = null;
    protected ?District $district = null;
    protected $progressBar;


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:sync 
        {clientId : The client ID to begin syncing}
        {--reset : reset to the event id we had stored}
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

    public function handle()
    {

        $this->warn('Starting Sync...');
        $this->limit = $this->option('limit');

        $clientId = (int)$this->argument('clientId');

        // Get Clients key
        $this->client = $this->verify($clientId);
        $this->settings = config('settings');
        $this->redisKey = $this->client->id . ':' . 'clever_sync';
        $this->clever = new Api($this->client->metadata->data['api_secret']);

        $this->setPreferneces();

        $this->syncDistrictInformation();
        $this->info('  ! District Information Should Have Synced');


        $this->syncAdminsInformation();
        $this->info('  ! Admins Information Should Have Synced');


        $this->syncSitesInformation();
        $this->info('  ! Sites Information Should Have Synced');

        $this->syncPrincipalsInformation();
        $this->info('  ! Principals Information Should Have Synced');

        $this->syncTeachersInformation();
        $this->info('  ! Teachers Information Should Have Synced');

        $this->syncStudentsInformation();
        $this->info('  ! Students Information Should Have Synced');

        if ($this->option('sections') || $this->option('all')) {
            $this->syncSectionsInformation();
            $this->info('  ! Sections Information Should Have Synced');
        }

        $this->client->synced_on = Carbon::now()->toDateTimeString();
        $this->client->save();

        $this->info('Sync Complete');
        return true;
    }

    /********     | New Methods |         *************/

    private function verify(int $clientId): ?Client
    {
        $client = Client::find($clientId);

        // Check if the client is not found
        if (is_null($client)) {
            throw new \Exception('Client not found.');
        }

        // Check if the client is deleted
        if ($client->deleted_at !== null) {
            throw new \Exception('Client has been deleted.');
        }

        // Check if the client is not a Clever Client
        if ($client->partner_id !== 1) {
            throw new \Exception('Client is not a Clever Client.');
        }

        return $client;
    }


    /************   || Sync District Information ||   ************/
    public function syncDistrictInformation(): void
    {
        $this->output->note('Syncing District Information...');

        $cleverDistricts = $this->getCleverDistricts();

        $this->checkForMultipleDistricts($cleverDistricts);

        $cleverDistrict = $this->getFirstCleverDistrict($cleverDistricts);


        $this->districtId = $cleverDistrict['data']['id'];

        $cleverDistrictData = $this->getCleverDistrictDataById($this->districtId);

        $this->district = $this->ensureDistrictExists($cleverDistrictData);

        $data = [
            'mdr_number' => ($cleverDistrictData->data['mdr_number']) ?? null,
            'clever_id' => $cleverDistrictData->data['id'],
            'partner_id' => 1,
            'last_event' => $cleverDistrictData->getEvents(['limit' => '1', 'ending_before' => 'last'])[0]->data['id'],
            'clever_data' => $cleverDistrictData->data['data'],
            'created_by' => 'Clever Process - Client Sync - CLI',
        ];

        $this->district->title = $cleverDistrictData->data['data']['name'];
        $this->district->setMetadata($data);

        $this->district->save();
    }

    private function getCleverDistricts()
    {
        return $this->clever->districts();
    }

    private function checkForMultipleDistricts(array $cleverDistricts): void
    {
        if (count($cleverDistricts['data']) > 1) {
            throw new Exception("We have more than one district to sync; this is not supported at this time. \n" .
                'Client ID: ' . $this->client->id . ' has ' . count($cleverDistricts['data']) . ' districts to sync.');
        }
    }

    private function getFirstCleverDistrict(array $cleverDistricts)
    {
        return $cleverDistricts['data'][0];
    }

    private function getCleverDistrictDataById(string $districtId)
    {
        return $this->clever->district($districtId);
    }


    public function ensureDistrictExists($cleverDistrict)
    {
        if ($this->cleverIdExists($cleverDistrict->data['id'], Metadata::$metableClasses['districts'])) {
            return $this->findDistrictByCleverId($cleverDistrict->data['id']);
        }

        return $this->createNewDistrict($cleverDistrict->data);
    }

    private function findDistrictByCleverId(string $cleverId)
    {
        $districts = District::where('client_id', $this->client->id)
            ->with(['metadata' => function ($q) use ($cleverId) {
                $q->ofCleverId($cleverId);
            }])
            ->get();

        if ($districts->count() > 1) {
            throw new \Exception("Multiple districts found with the same Clever ID for the client.");
        }

        return $districts->first();
    }

    private function createNewDistrict($cleverData): District
    {
        $district = new District([
            'title' => trim($cleverData->data['name']),
            'client_id' => $this->client->id,
        ]);

        $district->save();

        $data = [
            'mdr_number' => ($cleverData->data['mdr_number']) ?? null,
            'clever_id' => $cleverData->data['id'],
            'partner_id' => 1,
            'last_event' => $this->clever->getEvents($this->districtId)[0]->data['id'] ?? null,
        ];

        $district->setMetadata($data);

        return $district;
    }

    /************   || Sync District Admins ||   ************/

    public function syncAdminsInformation(): void
    {
        $admins = $this->fetchCleverDistrictAdmins();
        $this->initializeProgressBar(count($admins));
        foreach ($admins as $cleverUser) {
            $this->processAdmin($cleverUser);
        }

        $this->finalizeProgressBar();
    }

    private function fetchCleverDistrictAdmins(): array
    {
        $admins = $this->clever->districtAdmins();
        $this->output->note('Processing ' . count($admins['data']) . ' district admins...');
        return $admins['data'];
    }

    private function processAdmin(array $cleverUser): void
    {
        $user = $this->processCleverUserData($cleverUser);
        // ToDO: Ask @ryan tomorrow about this, I don't want to fuck it up.
        $cleverUser['data']['name']['first'] = str_replace('é', 'e', $cleverUser['data']['name']['first']);
        $user->save();
        $user->setMetadata(['clever_id' => $cleverUser['data']['id'], 'clever_information' => $cleverUser['data']]);

        $user->update([
            'first_name' => $cleverUser['data']['name']['first'],
            'last_name' => $cleverUser['data']['name']['last'],
            'email' => $cleverUser['data']['email'],
        ]);


        $user->roles()->syncWithoutDetaching([2]); // Detaches old roles and attaches new ones

        $this->progressBar->advance();
    }

    /********     | Sites |         *************/

    public function syncSitesInformation(): void
    {
        $schools = $this->fetchCleverSchools();
        $this->initializeProgressBar(count($schools));

        foreach ($schools as $school) {
            $this->processSchool($school);
        }

        $this->finalizeProgressBar();

    }

    private function fetchCleverSchools(): array
    {
        $district = $this->clever->district($this->district->id);
        $schools = $district->getSchools(['limit' => $this->option('schoolslimit')]);

        $this->output->note('Processing ' . count($schools) . ' schools...');
        return $schools;
    }

    private function processSchool($school): void
    {
        $site = $this->findOrCreateSite($school);


        $address = $this->createOrUpdateAddress($school);
        $site->address_id = $address;
        $site->save();

        $metadata = $this->coreData($school, 'site', 1);
        $metadata['high_grade'] = $highGrade->id ?? null;
        $metadata['low_grade'] = $lowGrade->id ?? null;
        $metadata['clever_id'] = $school->data['id'];
        $metadata['clever_information'] = $school->data;
        


        $site->setMetadata($metadata);

        $this->schools[$school->data['id']] = $site->id;
    }

    private function findOrCreateSite($school): Sites
    {
        if ($this->cleverIdExists($school->data['id'], Metadata::$metableClasses['sites'])) {
            return $this->findSiteByCleverId($school);
        }

        return $this->createNewSite($school);
    }

    private function findSiteByCleverId($school)
    {
        $site = Sites::where('client_id', $this->client->id)
            ->ofClever($school->data['id'])
            ->first();

        $this->checkCleverIdMatch($site->metadata->data['clever_id'], $school->data['id']);

        return $site;
    }

    private function createNewSite($school)
    {
        $site = new Sites();
        $site->client_id = $this->client->id;
        $site->title = $school->name;
        $site->edit = false;

        return $site;
    }

    private function getGrade($schoolData, $gradeType): Grades|\Illuminate\Database\Query\Builder|null
    {
        return empty($schoolData[$gradeType]) ? null : $this->checkGrade($schoolData[$gradeType]) ?? null;
    }

    private function createOrUpdateAddress($schoolData): int
    {
        $address = new Address();
        $address->street_1 = $schoolData->data['location']['address'] ?? null;
        $address->city = $schoolData->data['location']['city'] ?? null;
        $address->state = $schoolData->data['location']['state'] ?? null;
        $address->zip_code = $schoolData->data['location']['zip'] ?? null;
        $address->save();

        return $address->id;
    }


    /********     | Principals |         *************/
    public function syncPrincipalsInformation(): void
    {
        $admins = $this->clever->school_admins();
        $this->output->note('Processing ' . count($admins['data']) . ' school admins...');
        $this->initializeProgressBar(count($admins['data']));

        foreach ($admins['data'] as $cleverUser) {
            $this->processPrincipal($cleverUser);
        }

        $this->finalizeProgressBar();
    }

    private function processPrincipal($cleverUser): void
    {
        $user = $this->processCleverUserData($cleverUser);
        $metadata = $this->getPrincipalMetadata($cleverUser);
        $this->updateUserDetails($user, $cleverUser, $metadata);
        $attachToSchools = $this->getSchoolsToAttach($cleverUser['data']['schools']);
        $this->syncUserToSites($user, $attachToSchools);

    }

    private function getPrincipalMetadata($cleverUser): array
    {
        return [
            'staff_id' => $cleverUser['data']['staff_id'],
            'clever_id' => $cleverUser['data']['id'],
            'created_by' => 'Clever Process - Client Sync - CLI',
            'clever_information' => $cleverUser['data']['id']
        ];
    }

    private function updateUserDetails(EloquentUser $user, $cleverUser, $metadata)
    {
        $user->first_name = $cleverUser['data']['name']['first'];
        $user->last_name = $cleverUser['data']['name']['last'];
        $user->email = $cleverUser['data']['email'];
        $user->save();
        $user->setMetadata($metadata);
        $user->roles()->syncWithoutDetaching([2]);
    }

    private function getSchoolsToAttach($schoolCleverIds)
    {
        $attachToSchools = [];
        foreach ($schoolCleverIds as $schoolCleverId) {
            if (array_key_exists($schoolCleverId, $this->schools)) {
                array_push($attachToSchools, $this->schools[$schoolCleverId]);
            }
        }
        return $attachToSchools;
    }

    private function syncUserToSites($user, $attachToSchools)
    {
        $user->sites()->syncWithoutDetaching($attachToSchools);
    }


    /**********************     Sync Teacher Information    **********************/
    public function syncTeachersInformation()
    {
        $district = $this->clever->district($this->districtId);
        $object = $district->getTeachers(['limit' => $this->limit]);

        if (count($object) >= 1 && $object[0]->id !== null) {
            $this->output->note('Processing ' . count($object) . ' teachers...');
            echo "\n";
            $bar = $this->output->createProgressBar(count($object));

            foreach ($object as $cleverUser) {
                if (!is_null($cleverUser->id)) {
                    $this->processTeacher($cleverUser);
                }
                $bar->advance();
            }

            $bar->finish();
        } else {
            $this->output->note('No Teacher Information to Sync!');
        }

        $this->output->newLine();
    }

    private function processTeacher($cleverUser)
    {

        // ToDo: This should be cleaned up and put back to using the object
        //       For some reason the object is turned into an array and I can't figure out why.
        //       I think this has to do with processing the Users Data and finding or creating the user.
        //       Type juggling sucks.
        $cleverUserArray['data'] = $cleverUser->data;
        $user = $this->processCleverUserData($cleverUserArray);
        $data = $this->coreData($cleverUser, 'teacher', 1);
        $data['created_by'] = 'Clever Process - Client Sync - CLI';
        $data['clever_information'] = $cleverUserArray['data'];

        $this->updateTeacherDetails($user, $data);
        $attachToSchools = $this->getSchoolsToAttach($cleverUserArray['data']['schools']);
        $this->syncUserToSites($user, $attachToSchools);

        $this->teachers[$cleverUser->id] = $user->id;
    }

    private function updateTeacherDetails($user, $data)
    {
        $user->save();
        $user->setMetadata($data);
        $user->roles()->syncWithoutDetaching([3]);
    }


    /**********************     Sync Student Information    **********************/
    public function syncStudentsInformation()
    {
        $district = $this->clever->district($this->districtId);
        /** @noinspection PhpUndefinedMethodInspection */
        $object = $district->getStudents(['limit' => $this->limit]);

        if (count($object) >= 1 && $object[0]->id !== null) {
            $this->output->note('Processing ' . count($object) . ' students...');
            $bar = $this->output->createProgressBar(count($object));

            foreach ($object as $cleverUser) {
                if (!is_null($cleverUser->id)) {
                    $this->processStudent($cleverUser);
                }
                $bar->advance();
            }
            $bar->finish();
        } else {
            $this->output->error('No Student Information to Sync!');
        }

        $this->output->newLine();
    }

    private function processStudent($cleverUser)
    {
        $dob = new Carbon($cleverUser->data['dob']);
        $data = $this->coreData($cleverUser, 'student', 1);
        $data = array_merge($data, [
            'date_of_birth' => $dob->toDateString(),
            'delta' => Calc::driver('delta')->calc([
                'date_of_birth' => $dob->toDateString(),
                'grade' => (int)$cleverUser->data['grade'] ?? null,
                'client' => $this->client
            ]),
            'updated by' => 'clever',
            'clever_information' => $cleverUser->data
        ]);

        $cleverUserArray['data'] = $cleverUser->data;
        $cleverUserArray['data']['foreign_id'] = $data['foreign_id'];

        $user = $this->processCleverUserData($cleverUserArray);
        $this->updateStudentDetails($user, $data);

        $attachToSchools = $this->getSchoolsToAttach($cleverUserArray['data']['schools']);
        $this->syncUserToSites($user, $attachToSchools);

        $this->students[$cleverUser->id] = $user->id;
    }

    private function updateStudentDetails($user, $data)
    {
        $user->save();
        $user->setMetadata($data);
        $user->roles()->syncWithoutDetaching([4]);
    }


    /**********************     Sync Section Information    **********************/
    public function syncSectionsInformation()
    {
        $district = $this->clever->district($this->districtId);
        $sections = $district->getSections(['limit' => $this->limit]);
        if (count($sections) >= 1 && $sections[0]->id !== null) {
            $this->output->note('Processing ' . count($sections) . ' sections...');
            $bar = $this->output->createProgressBar(count($sections));

            foreach ($sections as $section) {
                if (!is_null($section->id)) {
                    $this->processSection($section);
                }
                $bar->advance();
            }

            $bar->finish();
        } else {
            $this->info('No Section/Class Information!');
        }
        $this->output->newLine();
    }

    private function processSection($section)
    {
        $data = $this->coreData($section, 'section', 1);

        $roster = $this->findOrCreateRoster($section);


        $this->syncTeachersToRoster($roster, $data['teachers']);
        $this->syncStudentsToRoster($roster, $data['students']);
    }

    private function findOrCreateRoster($section)
    {
        $roster = null;

        $startDate = (isset($section->data['start_date'])) ? new Carbon($section->data['start_date']) : null;
        $endDate = (isset($section->data['end_date'])) ? new Carbon($section->data['end_date']) : null;

        $roster = Roster::where('client_id', $this->client->id)
            ->with(['metadata' => function ($q) use ($section) {
                $q->ofCleverId($section->data['id']);
            }])
            ->get();
        if ($roster === null) {
            $roster = new Roster();
            $roster->type_id = 1;


            // Pulls Teacher ID from an Array of Teachers collected
            $roster->user_id = $this->teachers[$section->data['teacher']];
            // Pulls Site ID from an Array of Sites collected
            $roster->site_id = $this->schools[$section->data['school']];
            $roster->client_id = $this->client->id;
            $roster->writeable = false;

            // These may not exist
            $roster->start_date = $startDate;
            $roster->end_date = $endDate;


            $rosterTitle = $section->data['name'];
            $rosterTitle = $rosterTitle . ' (Clever)';


            $roster->title = $rosterTitle;
            $roster->description = "";

            $roster->save();

            $roster->setMetadata($this->buildRosterMetadata($section));
        }

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
        $teachers = [];
        foreach ($teacherCleverIds as $cleverTeacherId) {
            if (isset($this->teachers[$cleverTeacherId])) {
                array_push($teachers, $this->teachers[$cleverTeacherId]);
            }
        }
        $roster->access()->syncWithoutDetaching($teachers); //
    }

    private function syncStudentsToRoster($roster, $studentCleverIds)
    {
        $students = [];
        foreach ($studentCleverIds as $cleverStudentId) {
            if (isset($this->students[$cleverStudentId])) {
                array_push($students, $this->students[$cleverStudentId]);
            }
        }
        $roster->users()->syncWithoutDetaching($students);
    }


// Additional helper functions like buildMetadata, getTermInformation,
// updateRosterDetails,


    private function initializeProgressBar(int $count): void
    {
        $this->progressBar = $this->output->createProgressBar($count);
    }

    private function finalizeProgressBar(): void
    {
        $this->progressBar->finish();
        $this->output->newLine();
    }

    /********     | Needed Older Methods |         *************/

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
                'clever_id' => $object->id,
                'state_id' => $object->data['state_id'] ?? null,
                'partner_id' => $partnerId,
            ];

            switch ($type) {
                case 'student':
                    $data = [
                        'foreign_id' => $this->getForeignId($object->data),
                        'hispanic_ethnicity' => (isset($object->data['hispanic_ethnicity']) ? $object->data['hispanic_ethnicity'] : null),
                        'sis_id' => $object->data['sis_id'] ?? null,
                        'iep_status' => $object->data['iep_status'] ?? null,
                        'ell_status' => $object->data['ell_status'] ?? null,
                        'email' => $object->data['email'] ?? null,
                        'frl_status' => $object->data['frl_status'] ?? null,
                        'grade' => $object->data['grade'] ?? null,
                        'race' => $object->data['race'] ?? null,
                        'student_number' => $object->data['student_number'] ?? null,
                        'gender' => $object->data['gender'],
                    ];
                    break;
                case 'teacher':
                    $data = [
                        'sis_id' => $object->data['sis_id'] ?? null,
                        'title' => $object->data['title'] ?? null,
                        'teacher_number' => $object->data['teacher_number'] ?? null,
                    ];
                    break;
                case 'site':
                    $data = [
                        'phone' => $object->data['phone'] ?? null,
                        'school_number' => $object->data['school_number'] ?? null,
                        'nces_id' => $object->data['nces_id'] ?? null,
                    ];
                    break;
                default:
                    $data = [];
            }
            $return = array_merge($basicData, $data);
        }

        return $return;
    }

    /****************** Older Methods *******************************/

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
                return 'letsgolearn';
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

    public function getTermInformation($term)
    {
        dd($term);
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
    public function cleverIdExists($cleverId, $metabletype)
    {
        /* @noinspection PhpUndefinedMethodInspection */

        if (Metadata::ofCleverId($cleverId)->where('metable_type', $metabletype)->count() > 1) {
            throw new ExceededCleverIdCount('Clever ID exists more than once in the system. ID: ' . $cleverId . ' .');
        }
        return (Metadata::ofCleverId($cleverId)->where('metable_type', $metabletype)->first()) ? true : false;
    }

    public function emailExists($email)
    {
        if ($email !== '' && EloquentUser::where('email', $email)->count() > 1) {
            throw new ExceededEmailCount('Email exists more than once in the system. Email: ' . $email . ' .');
        }
        return (EloquentUser::where('email', $email)->first()) ? true : false;
    }

    public function checkCleverIdMatch($syncCleverId, $systemCleverId): bool
    {
        if ($syncCleverId !== $systemCleverId) {
            throw new CleverIdMissmatch('Clever ID mismatch. Sync ID: ' . $syncCleverId . '. System ID: ' . $systemCleverId . ' .');
        }
        return false;
    }

    public function checkEmailMatch($syncEmail, $systemEmail): CleverSync
    {
        if ($syncEmail !== $systemEmail) {
            throw new EmailMissmatch('Email mismatch. Sync Email: ' . $syncEmail . '. System Email: ' . $systemEmail . ' .');
        }
        return $this;
    }

    public function processCleverUserData($cleverUser): EloquentUser
    {

        if ($this->cleverIdExists($cleverUser['data']['id'], Metadata::$metableClasses['users'])) {
            $metadata = Metadata::ofCleverId($cleverUser['data']['id'])->where('metable_type', Metadata::$metableClasses['users'])->first();
            $user = EloquentUser::withTrashed()->where('id', $metadata->metable_id)->first();
            if (!is_null($metadata) & is_null($user)) {
                throw new Exception('Clever ID exists in MetaData, but no user found. ID: ' . $cleverUser['data']['id'] . ' Name: ' . $cleverUser['data']['name']['first'] . ' ' . $cleverUser['data']['name']['last'] . ' | eMail: ' . $cleverUser['data']['email'] . '. Metadata Record Present.');
            }
            if ($user->trashed()) {
                $user->restore();
            }
        } else if ($this->cleverIdExists($cleverUser['data']['id'], Metadata::$metableClasses['users']) && $this->emailExists($cleverUser['data']['email'])) {
            // Check we have a district matching the clever id?
            $user = EloquentUser::withTrashed()->where('email', $cleverUser['data']['email'])
                ->where('client_id', $this->client->id)
                ->with('metadata')->first();
            if (is_null($user->metadata)) {
                throw new Exception('Clever ID exists, but no metadata found due to empty email. ID: ' . $cleverUser['data']['id'] . ' Name: ' . $cleverUser['data']['name']['first'] . ' ' . $cleverUser['data']['name']['last'] . ' | eMail: ' . $cleverUser['data']['email'] . '. Usually indicates a duplicate User record. One is missing the email.');
            }
            // What Scenario is this?
            if ($user->metadata->exists() && isset($user->metadata->data['clever_id'])) {
                ($user->metadata->exists()) ? $this->checkCleverIdMatch($cleverUser['data']['id'], $user->metadata->data['clever_id']) : null;
            }
            $this->checkEmailMatch($cleverUser['data']['email'], $user->email);
        } else {
            $user = new EloquentUser();
            $user->username = strtolower($this->getUsername($cleverUser));
            $user->email = ($cleverUser['data']['email'] ?? null);
            $user->password = Hash::make($this->getPassword($cleverUser));
            $user->client_id = $this->client->id;
            $user->first_name = $cleverUser['data']['name']['first'];
            $user->last_name = $cleverUser['data']['name']['last'];
            $user->email = ($cleverUser['data']['email'] ?? null);
            $user->save();
        }
        if (!is_null($user)) {

            $user->first_name = $cleverUser['data']['name']['first'];
            $user->last_name = $cleverUser['data']['name']['last'];
            $user->email = ($cleverUser['data']['email'] ?? null);
            $user->save();
            return $user;
        }
        else {
            dd('not sure what happened'. $cleverUser['data']['id'] . ' Name: ' . $cleverUser['data']['name']['first'] . ' ' . $cleverUser['data']['name']['last'] . ' | eMail: ' . $cleverUser['data']['email'] . '. Usually indicates a duplicate User record. One is missing the email.');
        }
        throw new CleverNullUser('Clever ID exists, but no user found/null. ID: ' . $cleverUser['data']['id'] . ' Name: ' . $cleverUser['data']['name']['first'] . ' ' . $cleverUser['data']['name']['last'] . ' | eMail: ' . $cleverUser['data']['email'] . '. Usually indicates a duplicate User record. One is missing the email.');
    }

    public function rest() {
        dd('Should reset Last Event ID');
    }
}
