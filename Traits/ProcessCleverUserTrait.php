<?php

namespace LGL\Clever\Traits;


use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use LGL\Auth\Users\EloquentUser;
use LGL\Clever\Commands\CleverSync;
use LGL\Clever\Exceptions\CleverIdMissMatch;
use LGL\Clever\Exceptions\EmailMissMatch;
use LGL\Clever\Exceptions\ExceededCleverIdCount;
use LGL\Clever\Exceptions\Exception;
use LGL\Core\Accounts\Models\Site;
use LGL\Core\Models\Metadata;

trait ProcessCleverUserTrait {


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

    public function processCleverUserData($cleverUser, $role): EloquentUser
    {
        $userFound = false;
        $user = null;
        // First see if we have a CleverID in the system.
        if ($this->cleverIdExists($cleverUser['data']['id'], Metadata::$metableClasses['users'])) {
            $metadata = Metadata::ofCleverId($cleverUser['data']['id'])->where('metable_type', Metadata::$metableClasses['users'])->first();
            $user = EloquentUser::withTrashed()->where('id', $metadata->metable_id)->first();
            if (!is_null($metadata) & is_null($user)) {
                Log::alert('['.Carbon::now()->toDateTimeString().'][Clever][IdMetadata] '.'Clever ID exists in MetaData, but no user found. ID: ' . $cleverUser['data']['id'] . ' Name: ' . $cleverUser['data']['name']['first'] . ' ' . $cleverUser['data']['name']['last'] . ' | eMail: ' . $cleverUser['data']['email'] . '. Metadata Record Present. ');
                throw new Exception('['.Carbon::now()->toDateTimeString().'][Clever][IdMetadata] '.'Clever ID exists in MetaData, but no user found. ID: ' . $cleverUser['data']['id'] . ' Name: ' . $cleverUser['data']['name']['first'] . ' ' . $cleverUser['data']['name']['last'] . ' | eMail: ' . $cleverUser['data']['email'] . '. Metadata Record Present. ');
            }
            $userFound = true;
        }


        // Get user by Foreign ID

        if ($role == 'student' && isset($cleverUser['data']['student_number']) && $userFound !== true) {
            $user = EloquentUser::where('client_id', $this->client->id)
                ->whereHas('metadata', function ($q) use ($cleverUser) {
                    $q->where('data->foreign_id', $cleverUser['data']['student_number']);
                })
                ->with('metadata')
                ->first();

            if($user) {
                $userFound = true;
            }
            else {
                $dob = new Carbon($cleverUser['data']['dob']);

                $user = EloquentUser::where('client_id', $this->client->id)
                    ->where('first_name', $cleverUser['data']['name']['first'])
                    ->where('last_name', $cleverUser['data']['name']['last'])
                    ->whereHas('metadata', function ($q) use ($cleverUser, $dob) {
                        $q->where('data->sis_id', $dob->toDateString());
                    })
                    ->with('metadata')
                    ->first();
            }
            if ($user) {
                $userFound = true;
            }
        }


        // Get User By Email
        if (isset($cleverUser['data']['email']) && $userFound !== true) {
           $user = EloquentUser::where('email', $cleverUser['data']['email'])
                    ->orWhere('username', $cleverUser['data']['email'])
                    ->where('client_id', $this->client->id)
                    ->with('metadata')->first();
        }


        // We have a user otherwise we should create one.
        if (!is_null($user)) {
            $user->first_name = $cleverUser['data']['name']['first'];
            $user->last_name = $cleverUser['data']['name']['last'];
            $user->email = ($cleverUser['data']['email'] ?? null);
            $user->deleted_at = null;
            $user->save();
            return $user;
        } else {
            // Must be a new user, we found no matches
            $user = new EloquentUser();
            $user->username = strtolower($this->getUsername($cleverUser));
            $user->email = ($cleverUser['data']['email'] ?? null);
            $user->password = Hash::make($this->getPassword($cleverUser));
            $user->client_id = $this->client->id;
            $user->first_name = $cleverUser['data']['name']['first'];
            $user->last_name = $cleverUser['data']['name']['last'];
            $user->email = ($cleverUser['data']['email'] ?? null);
            $user->save();
            return $user;
        }

        Log::alert('Clever ID exists unsure what to do. ID: ' . $cleverUser['data']['id'] . ' Name: ' . $cleverUser['data']['name']['first'] . ' ' . $cleverUser['data']['name']['last'] . ' | eMail: ' . $cleverUser['data']['email'] . '. Usually indicates a duplicate User record. One is missing the email.');
//        throw new CleverNullUser('Clever ID exists, but no user found/null. ID: ' . $cleverUser['data']['id'] . ' Name: ' . $cleverUser['data']['name']['first'] . ' ' . $cleverUser['data']['name']['last'] . ' | eMail: ' . $cleverUser['data']['email'] . '. Usually indicates a duplicate User record. One is missing the email.');
    }

    private function getSchoolsToAttach($schoolCleverIds)
    {
        $attachToSchools = [];

        $sites = Site::whereClientId($this->client->id)
            ->whereHas('metadata', function ($q) {
                $q->where('data->clever_id', '!=', null);
            })
            ->with('metadata')  // Eager-load the metadata
            ->get();

        $schools = $sites->mapWithKeys(function ($site) {
            $cleverId = $site->metadata->data['clever_id'] ?? null;
            if ($cleverId) {
                return [$cleverId => $site->id];
            }
            return [];
        })->toArray();

        foreach ($schoolCleverIds as $schoolCleverId) {
            if (array_key_exists($schoolCleverId, $schools)) {
                array_push($attachToSchools, $schools[$schoolCleverId]);
            }
        }

        return $attachToSchools;
    }

    public function getTeachersToAttach() {

    }

    public function getForeignId($array)
    {
        $option = config('settings')['user.interpolate.foreign_ids'][$this->preferences['user.interpolate.foreign_ids']];
        if ($option !== 'none') {
            return $array[$option];
        }

        return '';
    }

    public function setPreferneces()
    {
        foreach ($this->client->preferences as $preference) {
            $this->preferences[$preference->key] = $preference->value;
        }
    }

    /**
     * HACKS!!!!!
     *
     * This is probably not the best way to do this, but it works for now.
     */
    public function cleverIdExists($cleverId, $metabletype)
    {
        if (Metadata::ofCleverId($cleverId)->where('metable_type', $metabletype)->count() > 1) {
            Log::alert('['.Carbon::now()->toDateTimeString().'][Clever][MultipleIds] Clever ID exists more than once in the system. ID: ' . $cleverId . ' | Type: ' . $metabletype . '.');
            throw new ExceededCleverIdCount('['.Carbon::now()->toDateTimeString().'][Clever][MultipleIds] Clever ID exists more than once in the system. ID: ' . $cleverId . ' | Type: ' . $metabletype . '.');
        }

        return (Metadata::ofCleverId($cleverId)->where('metable_type', $metabletype)->first()) ? true : false;
    }

    private function syncUserToSites($user, $attachToSchools)
    {
        $user->sites()->sync($attachToSchools);
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

    public function emailExists($email)
    {
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

    public function checkCleverIdCount() {
        $metadataRecords = Metadata::ofCleverId($this->cleverUser->id)->ofType('users');

        if ($metadataRecords->count() > 1) {
            throw new ExceededCleverIdCount();
        }
    }
}
