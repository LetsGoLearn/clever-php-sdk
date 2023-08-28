<?php

namespace LGL\Clever\Commands;

use Illuminate\Console\Command;
use LGL\Clever\Api;
use LGL\Core\Accounts\Models\Client;
//use LGL\Core\Models\District;
//use LGL\Core\Accounts\Models\Site;
//use LGL\Core\Rosters\Models\Roster;
//use LGL\Auth\Users\EloquentUser;


//use LGL\Clever\Lib\District;
//use LGL\Clever\Lib\School;
//use LGL\Clever\Lib\Section;
//use LGL\Clever\Lib\Admin;
//use LGL\Clever\Lib\DistrictAdmin;
//use LGL\Clever\Lib\SchoolAdmin;
//use LGL\Clever\Lib\Teacher;
//use LGL\Clever\Lib\Student;



// other necessary imports

class CleanCleverAccount extends Command
{
    protected API $clever;
    protected Client $client;
    protected array $cleverIds = [];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:clean {clientId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up Clever account. Remove or archive objects no longer present in Clever.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {


        $this->info('Starting Clever account cleanup...');
        $this->client = Client::find($this->argument('clientId'));
        $this->clever = new Api($this->client->metadata->data['api_secret']);

        // Fetch all Clever IDs for each object type
        $this->fetchCleverIds('districts');
        $this->fetchCleverIds('school');
        $this->fetchCleverIds('section');
        $this->fetchCleverIds('teacher');
        $this->fetchCleverIds('student');
        $this->fetchCleverIds('school_admin');
        $this->fetchCleverIds('district_admin');


        //SchoolAdmin
        dd($this->cleverIds);
//        $cleverRosterIds = $this->fetchCleverIds('section');
//        $cleverUserIds = $this->fetchCleverIds('user');

        // Clean each object type
//        $this->cleanObjects(District::class, $cleverDistrictIds);
//        $this->cleanObjects(Site::class, $cleverSiteIds);
//        $this->cleanObjects(Roster::class, $cleverRosterIds);
//        $this->cleanObjects(EloquentUser::class, $cleverUserIds, 'role_column'); // Replace 'role_column' with the actual role column name

        $this->info('Clever account cleanup complete.');

        return 0;
    }

    private function fetchCleverIds(string $type, string $cleverId = null): array
    {

        if ($type == 'districts') {
            $cleverData = $this->clever->districts();
            $cleverIds = [];
            foreach ($cleverData['data'] as $district) {
                $district = $this->clever->district($district['data']['id']);
                $cleverIds[] = $district->data['id'];
            }
            return $cleverIds;
        }
        else {
            $cleverData = $this->clever->$type();
            $cleverIds = [];

            foreach ($cleverData['data'] as $object) {
                $object = $this->clever->$type($object['data']['id']);
                $cleverIds[] = $object->data['id'];
            }
            return $cleverIds;
        }
    }

    private function cleanObjects($modelClass, $cleverIds, $roleColumn = null)
    {
        $query = $modelClass::query();

        if ($roleColumn) {
            // If there's a role column, filter the query by it
            $query->where($roleColumn, 'principal')
                ->orWhere($roleColumn, 'teacher')
                ->orWhere($roleColumn, 'student');
        }

        $localObjects = $query->get();

        foreach ($localObjects as $object) {
            // Assuming the Clever ID is stored in a field named 'clever_id'
            if (!in_array($object->clever_id, $cleverIds)) {
                // Archive or delete the object
                // $object->delete();
                // or
                // $object->archive(); // if you have an archive method
            }
        }
    }
}
