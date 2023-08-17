<?php

namespace LGL\Clever\Commands;

use Carbon\Carbon;
use DB;
use Illuminate\Console\Command;
use LGL\Core\Accounts\Models\Site;
use LGL\Auth\Users\EloquentUser;
use LGL\Core\Models\Metadata;
use LGL\Core\Rosters\Models\Roster;
use Mrdatawolf\MultilineProgressbar\MultilineProgressbar;
use Redis;

class CleverUserDupe extends Command
{
    /**
     * @var array|bool|string|null
     */
    public $clientId;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clever:userdup {--client=: Client to process.}';

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
        $this->clientId = ($this->option('client')) ?? null;

        $duplicates     = $this->findDuplicateEntries();
        if (!is_null($duplicates)) {
            $this->processData($duplicates, $this->type);
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
        $this->processDuplicate($duplicates);
    }


    /**
     * @param $duplicates
     */
    private function processDuplicate($duplicates)
    {
        $objectsToDelete = [];
        $dataToMove      = [];
        $noEntryCount    = 0;

        if (count($duplicates) == 0) {
            return;
        }


        $this->output->note('Working out which duplicate users to remove...');
        $progressBar = new MultilineProgressBar($this->output, 'Processing Duplicate Clever Ids', '|',
            count($duplicates));
        $progressBar->start();
        foreach ($duplicates as $duplicate) {
            $badUserIds = [];
            $newestDate = null;
            $keep       = null;
            if (count($duplicate) < 2 || !isset($duplicate['lgl_ids'])) {
                continue;
            }
            $users = EloquentUser::withTrashed()->whereIn('id', $duplicate['lgl_ids'])->get();
            foreach ($users as $record) {
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
                    array_push($badUserIds, $record->id);
                    $metadata = Metadata::where('metable_id', $record->id)
                        ->where('metable_type', EloquentUser::class)
                        ->get();

                    if (!$metadata->isEmpty()) {
                        foreach ($metadata as $metaRecord) {
                            array_push($objectsToDelete, $metaRecord);
                        }
                    }
                    $noEntryCount++;
                }
            }
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
            $progressBar->setMessageAndSpinAndAdvance('comparing data', 'progress');
        }

        $progressBar->finish();
        $this->info('Found '.$noEntryCount.' With no corresponding users.');
        if (count($objectsToDelete) > 0) {
            $this->output->warning('Moving Records & Deleting duplicate users/metadata. Ensure you want the data removed. Make a choice already.');
            $this->output->newLine();

            if ($this->confirm('Do you wish to continue? [y|N]')) {
                $this->moveData($dataToMove);
                $this->deleteObjects($objectsToDelete);
                $this->removeDupUserObjects($this->clientId);
            }
        }
    }


    /**
     * @param  integer  $to
     * @param  array    $from
     */
    private function moveData($dataToMove)
    {
        // Instruction
        // New Assessments
        // AP Scores
        // Seriously...
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
        $duplicates = [];


        $sql = "SELECT * FROM (SELECT metable_type,data -> 'clever_id' AS clever_id, ROW_NUMBER( ) OVER (PARTITION BY data -> 'clever_id' ) AS rnum FROM metadata INNER JOIN users ON (metable_id = users.id AND users.client_id = ".$this->clientId.") WHERE data ->> 'clever_id' NOTNULL AND ";

        $sql .= "metable_type = '".EloquentUser::class."')";

        $sql .= " t WHERE t.rnum > 1";

        $sql .= ';';

        $entries = DB::select(DB::raw($sql));

        if (count($entries) > 0) {
            $this->info('Gathered '.count($entries).' to clean up.');
            $progressBar = new MultilineProgressBar($this->output, 'Processing Entries', '|', count($entries));
            $progressBar->start();
            foreach ($entries as $entry) {
                $cleverId = trim($entry->clever_id, '"');

                $duplicates[] = [
                    'lgl_ids'   => Metadata::ofCleverId($cleverId)->pluck('metable_id')->toArray(),
                    'clever_id' => $cleverId,
                ];
                $progressBar->setMessageAndSpinAndAdvance('preparing data', 'progress');
            }
            $progressBar->finish();
            return $duplicates;
        }

        return null;
    }

    public function removeDupUserObjects($clientId)
    {
        $ids = collect(DB::select("SELECT array_agg(id order by id desc) FROM users WHERE client_id = ? and username != '' GROUP BY users.username HAVING count(*) > 1",
            [$clientId]))
            ->map(function ($record) {
                return explode(',', trim($record->array_agg, '{}'))[1];
            })->all();
        EloquentUser::withTrashed()->whereIn('id', $ids)->forceDelete();
    }
}
