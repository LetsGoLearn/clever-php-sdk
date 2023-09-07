<?php

namespace LGL\Clever\Commands;

use LGL\Core\Accounts\Models\Site;
use Carbon\Carbon;
use LGL\Core\Assessments\Models\TestBasicData;
use LGL\Auth\Users\EloquentUser;
use LGL\Core\Instructions\Models\AssignmentDetail;
use LGL\Core\Instructions\Models\CourseAssignment;
use LGL\Core\Rosters\Models\Roster;
use Illuminate\Console\Command;
use LGL\Core\Models\Metadata;
use DB;
use Redis;
use Mrdatawolf\MultilineProgressbar\MultilineProgressbar;

class CleverIdDupe extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:iddupe {--recordLimit=all : Limit the number of records to process.} {--limitTo= : Clever type to limit the duplicate fix to. user, roster, site} {--moveData : Move student data from old accounts to new accounts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleans up cleverIds, can be limited by clever user type, that have multiple user accounts.';

    /**
     * The client object.
     *
     * @var string
     */
    protected $type;

    /**
     * Gather all the records with dupelicate Clever Id's.
     */
    protected $dupeSQL;

    protected $multipleRecords;

    protected $recordLimit;

    protected $metableKey = [
        'LGL\Auth\Users\EloquentUser' => 'user',
        'LGL\Core\Rosters\Models\Roster'   => 'roster',
        'LGL\Core\Accounts\Models\Site'    => 'site',
        'LGL\Core\Accounts\Models\District' => 'district'
    ];

    protected $log = [];
    protected $redisKey = 'clever:id_duplication:';


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
        $this->redisKey    = 'clever:id_duplication:'.Carbon::now()->timestamp;
        $this->recordLimit = $this->option('recordLimit');
        $this->type        = ($this->option('limitTo')) ?? null;
        $this->moveData    = $this->option('moveData');
        $duplicates        = $this->findDuplicateEntries();
        if (!is_null($duplicates)) {
            if (!empty($this->type)) {
                $this->processData($duplicates[$this->type], $this->type);
            } else {
                foreach ($duplicates as $key => $data) {
                    $this->processData($duplicates[$key], $key);
                }
            }
            $this->output->newline();
            $this->output->note('Finished deleting duplicate entries!');
        } else {
            $this->info('Nothing to process.');
        }
    }


    /**
     * @param $duplicates
     * @param $type
     */
    private function processData($duplicates, $type)
    {
        $this->processDuplicate($duplicates, $type);
    }


    /**
     * @param $duplicates
     */
    private function processDuplicate($duplicates, $type)
    {
        $objectsToDelete = [];
        $dataToMove      = [];
        $noEntryCount    = 0;

        if (count($duplicates) == 0) {
            return;
        }

        $model = array_flip($this->metableKey)[$type];
        $this->output->note('Working out which duplicate '.$type.'s to remove...');
        $progressBar = new MultilineProgressBar($this->output, 'Processing Duplicate Clever Ids', '|', count($duplicates));
        $progressBar->start();
        foreach ($duplicates as $duplicate) {
            $badUserIds = [];
            $newestDate = null;
            $keep       = null;
            foreach ($duplicate['lgl_ids'] as $id) {
                $record = $this->getRecord($id, $type);
                if (!empty($record)) {
                    if (empty($record->deleted_at)) {
                        $createdAt = Carbon::parse($record->created_at);

                        if (empty($newestDate)) {
                            // Check if we have a date to look at and if we have no date yet set some variables
                            // for processing through the array
                            $newestDate = $createdAt;
                            $keep       = $record;
                        } elseif ($newestDate->lt($createdAt)) {
                            array_push($badUserIds, $keep->id);
                            $objectsToDelete[] = $keep;
                            $newestDate        = $createdAt;
                            $keep              = $record;
                        } else {
                            $objectsToDelete[] = $record;
                        }
                    } else {
                        $objectsToDelete[] = $record;
                    }
                } else {
                    // Metadata has no user record. Add both badUserIds.
                    // Add the object to the array of objects to delete from the system.
                    array_push($badUserIds, $id);
                    $metadata = Metadata::where('metable_id', $id)->where('metable_type', $model)->get();
                    if (!$metadata->isEmpty()) {
                        foreach ($metadata as $metaRecord) {
                            array_push($objectsToDelete, $metaRecord);
                        }
                    }
                    $noEntryCount++;
                }
            }
            if ($this->option('moveData') && $type === 'user') {
                if (!is_null($keep)) {
                    $recordId = $keep->id;
                } elseif (!is_null($record) && is_null($keep)) {
                    $recordId = $record->id;
                }
                if (!is_null($keep) || !is_null($record)) {
                    $tempDataToMove = [
                        'toId'      => $recordId,
                        'fromIds'   => $badUserIds,
                        'clever_id' => $duplicate['clever_id'],
                    ];
                    array_push($dataToMove, $tempDataToMove);
                }
            }

            $progressBar->setMessageAndSpinAndAdvance('comparing data', 'progress');
        }

        $progressBar->finish();
        $this->info('Found '.$noEntryCount.' With no corresponding '.$type.'s.');
        if (count($objectsToDelete) > 0) {
            $this->output->warning('Moving Records & Deleting duplicate users/metadata. Ensure you want the data removed. Make a choice already.');
            $this->output->newLine();

            if ($this->confirm('Do you wish to continue? [y|N]')) {
                $this->moveData($dataToMove);
                $this->recordLog();
                $this->deleteObjects($objectsToDelete);
                $this->recordLog();
            }
        }
    }


    private function recordLog()
    {
        /* @noinspection PhpUndefinedMethodInspection */
        Redis::connection('logging')->set($this->redisKey, json_encode((object) $this->log));
        /* @noinspection PhpUndefinedMethodInspection */
        Redis::connection('logging')->expireat($this->redisKey, strtotime('+30 days'));
    }


    /**
     * @param  integer  $to
     * @param  array    $from
     */
    private function moveData($dataToMove)
    {
        if (count($dataToMove) == 0) {
            return;
        }
        $progressBar = new MultilineProgressBar($this->output,
            'Moving data from old accounts to new accounts. ('.count($dataToMove).')', '|', count($dataToMove));
        $progressBar->start();
        foreach ($dataToMove as $key => $data) {
            // Legacy Data Seriously...
            $logData = [
                'assignment_detail'               => null,
                'course_assignments'              => null,
                'tests_basic_data'                => null,
                'test_id_to_user_id'              => null,
                'course_id_to_user_id'            => null,
                'assignment_detail_id_to_user_id' => null,
            ];
            $data    = array_merge($data, $logData);

            /************************ Data Information Gathering for data integrity, rollback purposes ****************************/
            // ToDo: Remove legacy data, Data Warehoused
            // Due: 12/31/2023

            /***** LEGACY DATA *****/
            $tests           = TestBasicData::where('status', '=', 3)->whereIn('user_id', $data['fromIds'])->get();
            $courseData      = CourseAssignment::whereIn('user_id', $data['fromIds'])->where('status', '=', 3)->get();
            $courseIds       = $courseData->pluck('id')->toArray();
            $assignmentsData = AssignmentDetail::whereIn('user_id', $data['fromIds'])
                ->whereIn('assignment_id', $courseIds)
                ->get();

            /***** CURRENT DATA *****/

            $toId = $data['toId'];


            $this->moveStudentData([
                // === Assessments
                'queue',
                'formative_attempts',
                'formative_bubble_summary',
                'formative_strand_summary',
                'formative_summary',
                'formative_tier4_summary',

                // === Instruction
                'assignments',
                'instruction_scores',
                'instruction_assignment_students',

                // == Scores
                'student_activity',
                'live_layer_scores_yearly',
                'student_live_score_snapshots'
            ], $data['fromIds'], $toId);

            // Scores and survey
            $this->moveStudentData(['student_scores', 'survey_sessions'], $data['fromIds'], $toId, 'user_id');

            // === User preferences
            DB::table('metadata')
                ->where('metable_type', 'LGL\Auth\Users\EloquentUser')
                ->whereIn('metable_id', $data['fromIds'])
                ->delete();

            DB::table('preferences')
                ->where('preferable_type', 'LGL\Auth\Users\EloquentUser')
                ->whereIn('preferable_id', $data['fromIds'])
                ->update([
                    'preferable_id' => $toId
                ]);


            // Legacy Data
            if (!$tests->isEmpty()) {
                $data['tests_basic_data'] = [
                    'count'    => $tests->count(),
                    'test_ids' => $tests->pluck('test_id')->toArray()
                ];

                $testsSql = "UPDATE tests_basic_data SET user_id = $toId WHERE test_id IN (".implode(",",
                        $tests->pluck('test_id')->toArray()).")";
                DB::select(DB::raw($testsSql));
                $data['test_id_to_user_id'] = $tests->pluck('user_id', 'test_id')->toArray();
            }

            if (!$courseData->isEmpty()) {
                $data['course_assignments'] = ['count' => $courseData->count(), 'course_ids' => $courseIds];

                $courseSql = "UPDATE course_assignment SET user_id = $toId WHERE user_id IN (".implode(",",
                        $courseData->pluck('user_id')->toArray()).")";
                DB::select(DB::raw($courseSql));
                $data['course_id_to_user_id'] = $courseData->pluck('user_id', 'id')->toArray();
            }

            if (!$assignmentsData->isEmpty()) {
                $data['assignment_detail'] = [
                    'count'          => $assignmentsData->count(),
                    'assignment_ids' => $assignmentsData->pluck('id')->toArray()
                ];
                $assignmentSql             = "UPDATE assignment_detail SET user_id = $toId WHERE user_id IN (".implode(",",
                        $assignmentsData->pluck('user_id')->toArray()).") AND assignment_id IN (".implode(",",
                        $courseIds).")";
                DB::select(DB::raw($assignmentSql));
                $data['assignment_detail_id_to_user_id'] = $assignmentsData->pluck('user_id', 'id')->toArray();
            }

            if (count($data) > 0) {
                array_push($this->log, $data);
            }
            $progressBar->setMessageAndSpinAndAdvance('processing', 'progress');
        }
        $progressBar->finish();
    }

    protected function moveStudentData($table, $from, $to, $studentIdColumn = 'student_id')
    {
        if (is_array($table)) {
            foreach ($table as $tbl) {
                $this->moveStudentData($tbl, $from, $to, $studentIdColumn);
            }
        } else {
            return DB::table($table)->whereIn($studentIdColumn, $from)->update([
                $studentIdColumn => $to
            ]);
        }
    }

    private function getRecord($id, $type)
    {
        switch ($type) {
            case 'user':
                return EloquentUser::with('metadata')->whereNull('last_login')->withTrashed()->find($id);
                break;
            case 'roster':
                return Roster::with('metadata')->withTrashed()->find($id);
                break;
            case 'site':
                return Site::with('metadata')->withTrashed()->find($id);
                break;
        }
    }


    /**
     * @param $objects
     */
    private function deleteObjects($objects)
    {
        $progressBar = new MultilineProgressBar($this->output, 'Deleting system records...', '|', count($objects));
        $progressBar->start();
        $deletedObjectsArray = [];
        foreach ($objects as $object) {
            array_push($deletedObjectsArray, $object->toArray());
            $object->forceDelete();
            $progressBar->setMessageAndSpinAndAdvance('processing', 'progress');
        }
        $progressBar->finish();
        $this->log['deleted_data'] = $deletedObjectsArray;
    }


    /**
     * @return array
     */
    private function findDuplicateEntries()
    {
        $duplicates = [
            'user'   => [],
            'roster' => [],
            'site'   => [],
        ];


        $sql = "SELECT * FROM (SELECT metable_type,data -> 'clever_id' AS clever_id, ROW_NUMBER( ) OVER (PARTITION BY data -> 'clever_id' ) AS rnum FROM metadata WHERE data ->> 'clever_id' NOTNULL AND ";

        // entity users,rosters,sites
        if (!empty($this->type)) {
            $type = array_flip($this->metableKey)[$this->type];
            $sql  .= "metable_type = '$type')";
        } else {
            $sql .= "metable_type IN ";
            $sql .= "('".implode("', '", array_keys($this->metableKey))."'))";
        }

        $sql .= " t WHERE t.rnum > 1";

        // Set a record limit
        if ($this->recordLimit !== 'all') {
            $sql .= ' LIMIT '.$this->recordLimit;
        }

        // fin
        $sql .= ';';

        $entries = DB::select(DB::raw($sql));

        if (count($entries) > 0) {
            $this->info('Gathered '.count($entries).' to clean up.');
            $progressBar = new MultilineProgressBar($this->output, 'Processing Entries', '|', count($entries));
            $progressBar->start();
            foreach ($entries as $entry) {
                $key                = $this->metableKey[$entry->metable_type];
                $cleverId           = trim($entry->clever_id, '"');
                $ids                = Metadata::ofCleverId($cleverId)->get();
                $duplicates[$key][] = [
                    'lgl_ids'   => $ids->pluck('metable_id')->toArray(),
                    'clever_id' => $cleverId,
                ];
                $progressBar->setMessageAndSpinAndAdvance('preparing data', 'progress');
            }
            $progressBar->finish();
            return $duplicates;
        }

        return null;
    }
}
