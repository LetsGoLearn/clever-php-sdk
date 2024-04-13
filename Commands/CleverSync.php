<?php

namespace LGL\Clever\Commands;

use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use LGL\Clever\Api;
use Illuminate\Console\Command;
use LGL\Clever\Exceptions\Exception;
use LGL\Core\Accounts\Models\Address;
use LGL\Core\Accounts\Models\Client;
use LGL\Core\Accounts\Models\Site as Sites;
use LGL\Core\Models\District;
use LGL\Core\Models\Metadata;
use LGL\Clever\Traits\ProcessCleverUserTrait;
use LGL\Clever\Jobs\ProcessStudentJob;
use LGL\Clever\Jobs\ProcessTeacherJob;
use LGL\Clever\Jobs\ProcessPrincipalJob;
use LGL\Clever\Jobs\ProcessSectionJob;

class CleverSync extends Command
{
    use ProcessCleverUserTrait;

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
        {--force : force the sync to run}
        {--skipSections : Skip building sections}
        {--reset : reset to the event id we had stored}
        {--limit=10000 :  limit how many records we will get in this run from clever} 
        {--schoolslimit=10000 : limit how many clever schools we will process under this client}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command is a defacto full sync. This will need a mechanism to lock '.
    'users out till Job is complete. This command is still in use as of 2023-09-09.';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {

        $this->limit = $this->option('limit');

        $clientId = (int)$this->argument('clientId');

        // Get Clients key
        $this->client = $this->verify($clientId);
        $this->warn('Starting Sync for Client: ' . $this->client->id . ' - ' . $this->client->title);
        $this->settings = config('settings');
        $this->redisKey = $this->client->id . ':' . 'clever_sync';
        $this->clever = new Api($this->client->metadata->data['api_secret']);

        $this->setPreferneces();

        $this->syncInformation('District');
        $this->syncInformation('Admins');
        $this->syncInformation('Sites');
        $this->syncInformation('Principals');
        $this->syncInformation('Teachers');
        $this->syncInformation('Students');

        if ($this->option('skipSections') !== true) {
            $this->syncSectionsInformation();
            $this->info('  ! Sections Information Synced');
        }

        $this->client->synced_on = Carbon::now()->toDateTimeString();
        $this->client->save();

        $this->info('Sync Complete');
        return true;
    }

  private function syncInformation(string $infoType): void {
    $this->info("Syncing $infoType...");
    $syncFunction = "sync" . $infoType . "Information";
    $this->$syncFunction();
    $this->info("$infoType Information Synced");
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
        $this->output->note('Checking for multiple districts...');
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
            'title' => trim($cleverData['data']['name']),
            'client_id' => $this->client->id,
        ]);

        $district->save();

        $data = [
            'mdr_number' => ($cleverData['data']['mdr_number']) ?? null,
            'clever_id' => $cleverData['data']['id'],
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
        $user = $this->processCleverUserData($cleverUser, 'admin');
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
        if ($this->cleverIdExists($school->id, Metadata::$metableClasses['sites'])) {
            return $this->findSiteByCleverId($school);
        }

        return $this->createNewSite($school);
    }

    private function findSiteByCleverId($school): Sites
    {
        $site = Sites::withTrashed()->where('client_id', $this->client->id)->whereHas('metadata', function ($q) use ($school) {
            $q->where('data->clever_id', $school->data['id']);
        })->first();
        $site->restore();
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
        $district = $this->clever->district($this->districtId);
        $admins = $district->getSchoolAdmins(['limit' => $this->limit]);
        if ($admins[0]->id !== null) { // So dumb
            $this->output->note('Processing ' . count($admins) . ' school admins...');
            $this->initializeProgressBar(count($admins));
            $jobs = [];
            foreach ($admins as $cleverUser) {
                if ($this->option('force')) {
                    $job = new ProcessPrincipalJob($cleverUser->data, $this->client->id, $this->schools);
                    $job->handle();
                }
                else {
//                    dispatch(new ProcessPrincipalJob($cleverUser->data, $this->client->id, $this->schools));
                    $jobs[] = new ProcessPrincipalJob($cleverUser->data, $this->client->id, $this->schools);
                }

                $this->progressBar->advance();
            }
            Bus::batch($jobs)->then(function (Batch $batch) {

            })->name('Clever Principal Sync: ' . $this->client->id)->dispatch();
            $this->finalizeProgressBar();
            $this->newLine();
            $this->info('  ! Principals Information Synced');
        }
        else {
            $this->newLine();
            $this->info('No Principal Information to Sync!');
        }

    }

    /**********************     Sync Teacher Information    **********************/
    public function syncTeachersInformation()
    {
        $district = $this->clever->district($this->districtId);
        $object = $district->getTeachers(['limit' => $this->limit]);

        // This is duplicate ID Check
        if (count($object) >= 1 && $object[0]->id !== null) {
            $this->output->note('Processing ' . count($object) . ' teachers...');
            echo "\n";
            $bar = $this->output->createProgressBar(count($object));
            $jobs = [];
            foreach ($object as $cleverUser) {
                if (!is_null($cleverUser->id)) {
                    if($this->option('force')) {
                        $job = new ProcessTeacherJob($cleverUser->data, $this->client->id, $this->schools);
                        $job->handle();
                    }
                    else {
//                        dispatch(new ProcessTeacherJob($cleverUser->data, $this->client->id, $this->schools));
                        $jobs[] = new ProcessTeacherJob($cleverUser->data, $this->client->id, $this->schools);
                    }
                }
                $bar->advance();
            }
            Bus::batch($jobs)->name('Clever Teacher Sync: ' . $this->client->id)->dispatch();;

            $bar->finish();
        } else {
            $this->output->note('No Teacher Information to Sync!');
        }

        $this->output->newLine();
    }


    /**********************     Sync Student Information    **********************/
    public function syncStudentsInformation()
    {
        $district = $this->clever->district($this->districtId);

        $object = $district->getStudents(['limit' => $this->limit]);

        if (count($object) >= 1 && $object[0]->id !== null) {
            $this->output->note('Processing ' . count($object) . ' students...');
            $bar = $this->output->createProgressBar(count($object));
            $jobs = [];
            foreach ($object as $cleverUser) {
                if (!is_null($cleverUser->id)) {

                    if ($this->option('force')) {
                        $job = new ProcessStudentJob($cleverUser->data, $this->client->id, $this->schools);
                        $job->handle();
                    }
                    else {
                        $jobs[] = new ProcessStudentJob($cleverUser->data, $this->client->id, $this->schools);
                    }
                }
                $bar->advance();
            }
            Bus::batch($jobs)->name('Clever Student Sync: ' . $this->client->id)->dispatch();;
            $bar->finish();
        } else {
            $this->output->error('No Student Information to Sync!');
        }

        $this->output->newLine();
    }
//
    /**********************     Sync Section Information    **********************/
    public function syncSectionsInformation()
    {
        $district = $this->clever->district($this->districtId);
        $sections = $district->getSections(['limit' => $this->limit]);
        if (count($sections) >= 1 && $sections[0]->id !== null) {
            $this->output->note('Processing ' . count($sections) . ' sections...');
            $bar = $this->output->createProgressBar(count($sections));
            $jobs = [];
            foreach ($sections as $section) {
                if (!is_null($section->id)) {
                    if ($this->option('force')) {
                        $section = json_encode($section);
                        $job = new ProcessSectionJob($section, $this->client->id);
                        $job->handle();
                    }
                    else {
                        $section = json_encode($section);
//                        dispatch(new ProcessSectionJob($section, $this->client->id));
                        $jobs[] = new ProcessSectionJob($section, $this->client->id);
                    }
                }
                $bar->advance();
            }
            Bus::batch($jobs)->name('Clever Section Sync: ' . $this->client->id)->dispatch();
            $bar->finish();
        } else {
            $this->info('No Section/Class Information!');
        }
        $this->output->newLine();
    }



    private function initializeProgressBar(int $count): void
    {
        $this->progressBar = $this->output->createProgressBar($count);
    }

    private function finalizeProgressBar(): void
    {
        $this->progressBar->finish();
        $this->output->newLine();
    }

    /****************** Older Methods *******************************/



    public function reset()
    {
        dd('Should reset Last Event ID');
    }
}
