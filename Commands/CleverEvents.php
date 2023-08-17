<?php

namespace LGL\Clever\Commands;

use LGL\Core\Imports\ImportMerge;
use LGL\Core\Imports\Traits\ProcessRoster;
use LGL\Core\Imports\Traits\ProcessSite;
use LGL\Core\Metadata\MetableTrait;
use Illuminate\Console\Command;
use LGL\Core\Imports\Traits\ProcessUser;
use LGL\Core\Models\Metadata;
use LGL\Core\Traits\AddressTrait;
use LGL\Core\Accounts\Models\Client as Clients;
use LGL\Core\Models\District;
use LGL\Clever\Api;
use Log;
use Carbon\Carbon;
use Exception;
use LGL\Core\Tools\Messaging;
use LGL\Core\Clever\Models\CleverEvents as CleverEventsModel;
use Illuminate\Support\Facades\Redis;
use LGL\Core\Models\Course;

class CleverEvents extends Command
{
    use ProcessUser;
    use ProcessRoster;
    use ProcessSite;
    use MetableTrait;
    use AddressTrait;
    use Messaging;


    /**
     * @var Api
     */
    protected $clever;
    protected $preferences;
    protected $limit;
    protected $redisKey;
    protected $settings;
    protected $bar, $messageDisplay;
    protected $stats = [];
    protected $log = [];
    protected $syncing = ['processing' => true, 'lastAttempt' => null];
    protected $softErrors = [];

    /**
     * @var MultilineProgressBar
     */
    protected $progressBar;
    /**
     * @var Clients
     */
    protected $client;

    /**
     * @var District
     */
    protected $district;

    /**
     * @var int
     */
    protected $eventLimit = 5000;

    protected $emailTo, $emailFrom;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:events 
    {clientId : The client ID to begin syncing}
    {--clearEventId : This clears the event Id from the client. This will clear the last_event id from the districts metadata.}
    {--debug : Set to output more messages. Also changes progress frequency to be each time a update happens.} 
    {--setEventLimit=5000 : set record limit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pulls and queues information from Clever to be added/updated in the system.';


    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }


    /**
     * Execute the console command.
     */
    public function handle()
    {

        if ($this->option('clearEventId')) {
            $this->clearEventId();
            die();
        }

        $this->emailFrom = config('clever.email.errors.from');
        switch (strtolower(config('app.env'))) {
            case 'production':
                $this->emailTo = config('clever.email.errors.to');
                break;
            case 'staging':
                $this->emailTo = config('clever.email.errors.to');
                break;
            default:
                $this->emailTo = env('LOCAL_MAIL_USER') ?? config('clever.email.errors.to');
        }

        if ($this->option('setEventLimit')) {
            $this->eventLimit = $this->option('setEventLimit');
        }
        $memoryLimit = '3096M';
        ini_set('memory_limit', $memoryLimit);
        if ($this->option('debug')) {
            $this->output->note('allowed memory = ' . $memoryLimit);
        }
        // Get Clients data
        $this->client = Clients::find($this->argument('clientId'));

        $today          = Carbon::now()->tz('America/Los_Angeles')->toDateString();
        $this->redisKey = $this->client->id . ':' . $today . ':' . 'clever_events';
        $this->log      = $this->getLog();
        //check if the client is a valid clever client
        try {
            if (!$this->verifyClient()) {
                throw new Exception('Client ' . $this->client->title . ', Id: ' . $this->client->id . ' is not connected with Clever.');
            }
        } catch (Exception $e) {
            $messageArray = [
                'errorTitle'  => 'Clever Events: Error',
                'clientId'    => $this->client->id,
                'clientTitle' => $this->client->title,
                'errorMessage' => $e->getMessage(),
                'eventData'   => [
                    'action'  => '',
                    'eventId' => '',
                    'file'    => '',
                    'line'    => '',
                    'type'    => '',
                    'message' => $e->getMessage(),
                ],
            ];
            $this->sendMessage(
                config('app.env') . ' | Clever Event Sync Error | ' . Carbon::now()->tz('America/Los_Angeles')->toDateString(),
                $this->emailTo,
                $this->emailFrom,
                $messageArray,
                'emails.notifications.clever_report'
            );
            $this->syncing['processing'] = false;
            $this->syncing['lastAttempt'] = Carbon::now()->tz('America/Los_Angeles')->toDateTimeString();
            $this->buildLog();
            $this->setLog();
            die();
        }
        $this->settings = config('settings');
        $this->clever = new Api($this->client->metadata->data['api_secret']);
        $this->setPreferneces();

        foreach (District::ofClientId($this->client->id)->get() as $district) {
            $this->district = $district;
            $originalLastEventId = isset($this->district->metadata->data['last_event']) ? $this->district->metadata->data['last_event'] : null;
            $this->output->note('District : ' . $this->district->metadata->data['clever_id']);
            $this->info($district->title);
//            $this->output->note('Last Event Id: ' . $originalLastEventId);
            $allEvents = $this->gatherEvents();
            if (empty($allEvents)) {
                $this->setSyncTime();
                $this->info('No event updates to process.');
                $this->syncing['processing'] = true;
                $this->syncing['lastAttempt'] = Carbon::now()->tz('America/Los_Angeles')->toDateTimeString();
                $this->buildLog();
                $this->setLog();
            } else {

                $this->output->newLine();
                $action = '';
                try {
                    $this->progressBar = $this->output->createProgressBar(count($allEvents));
                    $this->progressBar->start();
                    foreach ($allEvents as $event) {
                        list($functionName, $action) = $this->getFunction($event->type);
                        $this->checkCleverId($event->data['data']['object']['id']);
                        if ($functionName === 'contacts' || $functionName == 'studentcontacts' || $functionName == 'terms') {
                            ++$this->stats['all']['ignored'];
                            $processed = false;
                        } else {
                            $this->$functionName($action, $event->data, 'clever events');
                            if (!isset($this->stats['all'][$functionName . '.' . $action])) {
                                $this->stats['all'][$functionName . '.' . $action] = 0;
                            }
                            ++$this->stats['all'][$functionName . '.' . $action];
                            $processed = true;
                        }
                        ++$this->stats['total'];
                        $finalEventId = $event->id;
                        $this->writeRecord($functionName, $action, $event->data, $processed);
                        $this->progressBar->advance();
                    }
                    $districtMetaData = $this->district->metadata->data;
                    $districtMetaData['last_event'] = $finalEventId;
                    $this->district->metadata->data = $districtMetaData;
                    $this->district->metadata->save();
                    $this->setSyncTime();
                    $this->progressBar->finish();
                    $this->output->newLine();
                    $this->syncing['processing'] = true;
                    $this->syncing['lastAttempt'] = Carbon::now()->tz('America/Los_Angeles')->toDateTimeString();
                    $this->buildLog();
                    $this->setLog();
                    $this->output->text($this->client->title . ' has been synced using event updates');
                    $this->output->note('Final ID: ' . $finalEventId . ' | Initial Event ID: ' . $originalLastEventId);
                    $this->output->note('Total Events: ' . $this->stats['total']);

                } catch (Exception $e) {
                    $this->writeRecord($functionName, $action, $event->data, false, $e->getMessage());
                    $action               = (empty($action)) ? 'NoActionGiven' : $action;
                    $eventId              = (empty($event)) ? 'empty!' : $event->data['id'];
                    $eventType            = (empty($event)) ? 'empty!' : $event->type;
                    $clientTitle          = (empty($district->title)) ? 'empty!' : $district->title;
                    $clientId             = (empty($district->client_id)) ? 'empty!' : $district->client_id;
                    $message              = $e->getMessage();
                    $file                 = $e->getFile();
                    $line                 = $e->getLine();
                    $this->output->error('Caught exception: ' . $e->getMessage() . ' File: ' . $file . ' Line: ' . $line);
                    $messageArray = [
                        'errorTitle'  => 'Clever Events: Error',
                        'clientId'    => $clientId,
                        'clientTitle' => $clientTitle,
                        'errorMessage' => $message,
                        'eventData'   => [
                            'action'  => $action,
                            'eventId' => $eventId,
                            'file'    => $file,
                            'line'    => $line,
                            'type'    => $eventType,
                            'message' => $message,
                        ],
                    ];
                    $this->sendMessage(
                        config('app.env') . ' | Clever Event Sync Error | ' . Carbon::now()->tz('America/Los_Angeles')->toDateString(),
                        $this->emailTo,
                        $this->emailFrom,
                        $messageArray,
                        'emails.notifications.clever_report'
                    );
                    $metadata = $district->metadata;
                    $originalMetadata = $metadata->data;
                    $originalMetadata['last_event'] = $event->id;
                    $metadata->data         = $originalMetadata;
                    $metadata->save();

                    $this->syncing['processing'] = false;
                    $this->syncing['lastAttempt'] = Carbon::now()->tz('America/Los_Angeles')->toDateTimeString();
                    $this->syncing['lastError'] = ['File' => $e->getFile(), 'line' => $e->getLine(), 'message' => $e->getMessage()];
                    $this->buildLog();
                    $this->setLog();
                    die();
                }
            }
        }
    }


    /**
     * @return array
     */
    public function gatherEvents()
    {
        /* @noinspection PhpUndefinedMethodInspection PhpUndefinedFieldInspection */
        $cleverDistrict = $this->clever->district($this->district->metadata->data['clever_id']);
        // /** @noinspection PhpUndefinedFieldInspection */
        $lastEventId = isset($this->district->metadata->data['last_event']) ? $this->district->metadata->data['last_event'] : null;

        $allEvents   = [];
        /* @noinspection PhpUndefinedMethodInspection */
        $events = $cleverDistrict->getEvents(['limit' => $this->eventLimit,'starting_after' => $lastEventId]);
        if (!is_null($events[0]->data['id'])) {
            $allEvents = array_merge($allEvents, $events);
            while (count($events) === $this->eventLimit) {

                // Get Last Event Id in grouping
                $lastElement = array_slice($events, -1);
                /* @noinspection PhpUndefinedMethodInspection */
                $events    = $cleverDistrict->getEvents([
                    'limit'          => $this->eventLimit,
                    'starting_after' => $lastElement[0]->id,
                ]);
                $allEvents = array_merge($allEvents, $events);
            }
        }
        return $allEvents;
    }


    /**
     * @param $action
     * @param $dataInput
     *
     * @throws \Exception
     */
    public function students($action, $dataInput)
    {
        $data             = (isset($dataInput['data']['object'])) ? $dataInput['data']['object']
            : $dataInput['data']['data'];
        $data['clientId'] = $this->client->id;
        $result           = null;
        switch ($action) {
            case 'created':
            case 'updated':
                $this->upsertUser('student', $data, 'Clever Events Command');
                break;
            case 'deleted':
                $this->deleteUsers('student', $data);
                break;
            default:
                throw new Exception('Unsupported action ' . $action . ' for student ' . $data['id']);
        }
    }


    /**
     * @param $type
     * @param $data
     *
     * note: delete one or more users
     *
     * @throws \Exception
     */
    public function deleteUsers($type, $data)
    {
        $users = $this->findUser($type, $data);
        if (empty($users) || $users->count() === 0) {
            array_push($this->softErrors, ['lglid'    => 'n/a', 'eventid' => $data['id'], 'message'  => $type . ' not found to close.', 'type' => $type, 'attempted_on' => Carbon::now()->tz('America/Los_Angeles')->toDateTimeString()]);
        } else {
            if ($users->count() > 1) {
                array_push($this->softErrors, ['lglid'    => 'n/a', 'eventid' => $data['id'], 'message'  => 'More than one ' . $type . ' returned to delete!', 'type' => $type, 'attempted_on' => Carbon::now()->tz('America/Los_Angeles')->toDateTimeString()]);
            }
            foreach ($users->get() as $user) {
                if ($type === 'student') {
                    /* @noinspection PhpUndefinedMethodInspection */
                    $user->rosters()->detach();
                } else {
                    /* @noinspection PhpUndefinedMethodInspection */
                    $user->rosterAccess()->detach();
                    /* @noinspection PhpUndefinedMethodInspection */
                    $user->myRosters()->delete();
                }
                $user->deleted_at = Carbon::now()->toDateTimeString();
                $user->save();
            }
        }
    }


    /**
     * @param $action
     * @param $data
     *
     * @throws \Exception
     */
    public function districts($action, $data)
    {
        $data = $data['data']['object'];
        switch ($action) {
            case 'created':
                $this->district = District::create([
                    'title'    => $data['name'],
                    'metadata' => [
                        'clever_id' => $data['id'],
                    ],
                ]);
                break;
            case 'updated':
                /* @noinspection PhpUndefinedFieldInspection */
                $this->district->title = $data['name'];
                $this->district->save();
                break;
            case 'deleted':
                /* @noinspection PhpUndefinedMethodInspection */
                $this->district->delete();
                break;
        }
    }


    /**
     * District Admins
     */

     public function districtadmins() {
//            $admins = $this->clever->districtAdmins();
//            foreach ($admins as $admin) {
//                $this->upsertUser('districtadmin', $admin->data, 'Clever Events Command');
//            }
     }

    /**
     *  i.e. Sites.
     *
     * @param $action
     * @param $data
     *
     * @throws \Exception
     */
    public function schools($action, $data)
    {
        $data = $data['data']['object'];
        switch ($action) {
            case 'created':
            case 'updated':
                $this->upsertSchool($data);
                break;
            case 'deleted':
                $site = $this->findSchool($data);
                if (!empty($site)) {
                    /* @noinspection PhpUndefinedFieldInspection */
                    $this->closeSite($site->id);
                } else {
                    array_push($this->softErrors, ['lglid' => 'n/a', 'eventid' => $data['id'], 'message'  => 'School not found to delete.', 'type' => 'school', 'attempted_on' => Carbon::now()->tz('America/Los_Angeles')->toDateTimeString()]);
                }
                break;
        }
    }


    /**
     * @param $action
     * @param $data
     *
     * @throws \Exception
     */
    public function teachers($action, $data)
    {
        $data             = (isset($data['data']['object'])) ? $data['data']['object'] : $data['data']['data'];
        $data['clientId'] = $this->client->id;
        switch ($action) {
            case 'created':
            case 'updated':
                $this->upsertUser('teacher', $data, 'Clever Events Command');
                break;
            case 'deleted':
                $this->deleteUsers('teacher', $data);
                break;
        }
    }


    /**
     * @param $action
     * @param $data
     *
     * @throws \Exception
     */
    public function sections($action, $data)
    {
        $data             = $data['data']['object'];
        $data['clientId'] = $this->client->id;
        switch ($action) {
            case 'created':
            case 'updated':
                $this->upsertSection($data);
                break;
            case 'deleted':
                $rosters = $this->findRoster($data['id']);

                if (!is_null($rosters)) {
                    $roster = $rosters->first();
                    $roster->delete();
                } else {
                    array_push($this->softErrors, ['lglid' => 'n/a', 'eventid' => $data['id'], 'message' => 'No roster found to delete.', 'type' => 'roster', 'attempted_on' => Carbon::now()->tz('America/Los_Angeles')->toDateTimeString()]);
                }
                break;
        }
    }

    public function courses($action, $data) {
        // switch ($action) {
        //     case 'created':
        //     case 'updated':
        //         $this->upsertCourse($data);
        //         break;
        //     case 'deleted':
        //         $course = $this->findCourse($data['id']);
        //         if (!is_null($course)) {
        //             $course->delete();
        //         } else {
        //             array_push($this->softErrors, ['lglid' => 'n/a', 'eventid' => $data['id'], 'message' => 'No course found to delete.', 'type' => 'course', 'attempted_on' => Carbon::now()->tz('America/Los_Angeles')->toDateTimeString()]);
        //         }
        //         break;
        // }
        return $this;
    }

    private function upsertCourse($data) {
        $course = $this->findCourse($data['id']);
        if (is_null($course)) {
            $course = new Course();
            $metadata = new Metadata();
            $metadata->data = $data['data'];
            $metadata->save();
            $course->clever_id = $data['id'];
        }
        $course->title = $data['name'];
        $course->save();
    }

    private function findCourse($id) {
        return Course::ofCleverId('clever_id', $id)->first();
    }

    /**
     * @param $action
     * @param $data
     *
     * @throws \Exception
     */
    public function schooladmins($action, $data)
    {
        $data             = (isset($data['data']['object'])) ? $data['data']['object'] : $data['data']['data'];
        $data['clientId'] = $this->client->id;
        switch ($action) {
            case 'created':
            case 'updated':
                $this->upsertUser('admin', $data, 'Clever Events Command');
                break;
            case 'deleted':
                $this->deleteUsers('admin', $data);
                break;
        }
    }


    /**
     * @return bool
     */
    private function verifyClient()
    {
        /* @noinspection PhpUndefinedFieldInspection */
        return !(empty($this->client->metadata->data['partner_id']) || $this->client->metadata->data['partner_id'] !== 1 || $this->client->deleted_at !== null);
    }


    public function setPreferneces()
    {
        foreach ($this->client->preferences as $preference) {
            $this->preferences[$preference->key] = $preference->value;
        }
    }


    /**
     * @param $type
     *
     * @return array
     */
    public function getFunction($type)
    {
        return explode('.', $type);
    }


    private function setSyncTime()
    {
        $this->client->synced_on = Carbon::now();
        $this->client->save();
        $this->syncedOn = Carbon::now();
    }


    /**
     * @param $cleverId
     *
     * @return $this
     * @throws \Exception
     */
    private function checkCleverId($cleverId)
    {
        $metadata = Metadata::ofCleverId($cleverId);
        if ($metadata->count() > 2) {
            Log::alert('More then two record returned for Clever Id: ' . $cleverId);
            throw new Exception('More then two record returned for Clever Id: ' . $cleverId);
        } elseif ($metadata->count() > 1) {
            $importMerge = new ImportMerge();
            $metableIds = $metadata->orderBy('metable_id')->pluck('metable_id')->toArray();
            Log::alert('Merging two records returned for Clever Id: ' . $cleverId . ' metable_ids: ' . $metableIds[0] . ' and ' . $metableIds[1]);
            $importMerge->mergeIds($metableIds[0], $importMerge->getUser($metableIds[1]), 1);
        }

        return $this;
    }

    private function buildLog()
    {
        $this->log['stats'] = $this->stats;
        $this->log['syncing'] = $this->syncing;
        $this->log['softErrors'] = $this->softErrors;
    }


    /**
     * @return array
     */
    private function getLog()
    {
        /* @noinspection PhpUndefinedMethodInspection */
        $redisLog = Redis::connection('logs')->get($this->redisKey);
        $log      = ($redisLog) ? json_decode($redisLog, true) : [];

        // Build variables to work with
        $this->stats['all']            = (empty($log['stats']['all'])) ? [] : $log['stats']['all'];
        $this->stats['all']['ignored'] = (empty($log['stats']['all']['ignored'])) ? 0 : $log['stats']['all']['ignored'];
        $this->stats['total']          = (empty($log['stats']['total'])) ? 0 : $log['stats']['total'];
        $this->softErrors = (empty($log['softErrors'])) ? [] : $log['softErrors'];

        return $log;
    }

    private function setLog()
    {
        /* @noinspection PhpUndefinedMethodInspection */
        Redis::connection('logs')->set($this->redisKey, json_encode((object) $this->log));
        /* @noinspection PhpUndefinedMethodInspection */
        Redis::connection('logs')->expireat($this->redisKey, strtotime('+7 days'));
    }

    private function writeRecord($functionName, $action, $eventData, $processed = true, $exception = null)
    {
        $metadata = Metadata::ofCleverId($eventData['data']['object']['id'])->first();
        $lglId = (is_null($metadata)) ? -9999 : $metadata->metable_id;
        $data = [];
        if (!is_null($exception)) {
            $data['exception'] = $exception;
        }
        $data = $eventData['data']['object'];
        $eventRecord = CleverEventsModel::firstOrNew(['clever_event_id' => $eventData['id']]);
        $eventRecord->client_id = $this->client->id;
        $eventRecord->lgl_id = $lglId;
        $eventRecord->clever_event_id = $eventData['id'];
        $eventRecord->clever_event_date = $eventData['created'];
        $eventRecord->clever_id = $eventData['data']['object']['id'];
        $eventRecord->type = $functionName;
        $eventRecord->action = $action;
        $eventRecord->data = json_encode($data);
        $eventRecord->processed = $processed;
        $eventRecord->save();

        return $this;
    }

    private function clearEventId() {
        $district = District::ofClientId($this->argument('clientId'))->first();
        $data = $district->metadata->data;
        $data['last_event'] = null;
        $district->metadata->data = $data;
        $district->metadata->save();
    }
}
