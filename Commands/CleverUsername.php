<?php

namespace LGL\Clever\Commands;

use Illuminate\Console\Command;
use LGL\Auth\Users\EloquentUser;
use LGL\Core\Accounts\Models\Client;
use Carbon\Carbon;

class CleverUsername extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:username {clientId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Change a Clever usernames to match the given pattern defined in the client preferences.'
                            .'This command is valid as of 2023-09-09.';

    protected $preferences;

    protected $client;

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
     * @return mixed
     */
    public function handle()
    {
        //

        $clientId = (int) $this->argument('clientId');
        $students = EloquentUser::whereClientId($clientId)->ofRole('student')->get();
        $this->client = Client::where('id', $clientId)->with('preferences')->first();
        $this->getPreferences();
        foreach ($students as $student) {
            $student->username = strtolower($this->getUsername($student));
            $student->save();
        }

    }

    /**
     * note: grabbed from CleverNew and modified.
     *
     * @param      $object
     * @param null $type
     *
     * @return $this|string
     */
    public function getUsername($object)
    {

        $key = 'user.interpolate.usernames';
        $pattern = config('settings')[$key][$this->preferences[$key]];
        $firstName = $object->first_name;
        $lastName = $object->last_name;
        $metadata = $object->metadata;
        switch ($pattern) {
            case 'email':
                return $object->email;
            case 'filifi':
                return substr($firstName, 0, 1).substr($lastName, 0, 1).$metadata->data['foreign_id'];
            case 'fnlidob':
                // Format mmdd
                $dob = new Carbon($metadata->data['date_of_birth']);
                return strtolower($firstName).substr($lastName, 0, 1).$dob->format('md');
            case 'fndob':
                // Format mmddyy
                $dob = new Carbon($metadata->data['date_of_birth']);
                return $firstName.$dob->format('mdY');
            case 'fnfi':
                return $firstName.$metadata->data['foreign_id'];
        }

        return $this;
    }

    /**
     * note: grabbed from ProcessUser.
     */
    public function getPreferences()
    {
        //set base settings to given then override with any found in the database.
        foreach (config('settings') as $mainKey => $configArray) {
            $this->preferences[$mainKey] = array_flip($configArray)['given'];
        }
        foreach ($this->client->preferences as $preference) {
            $this->preferences[$preference->key] = $preference->value;
        }
    }
}
