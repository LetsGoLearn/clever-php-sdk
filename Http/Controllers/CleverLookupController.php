<?php

namespace LGL\Clever\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use LGL\Auth\Users\EloquentUser;
use LGL\Clever\Api;
use LGL\Core\Accounts\Models\Client;
use LGL\Core\Accounts\Models\Site;
use LGL\Core\Models\Metadata;
use LGL\Core\Rosters\Models\Roster;


class CleverLookupController extends Controller
{

    public function getLookup(Request $request, $client)
    {
        Inertia::setRootView('inertia-staff');

        $client = Client::find($client);

        return Inertia::render('Clever/EntityReconciliation', [
            'client' => $client
        ]);
    }


    public function runSync(Request $request, $clientId, $type, $cleverId)
    {

        Artisan::queue('clever:userSync', [
            'clientId' => $clientId,
            'type'     => $type,
            'cleverId' => $cleverId
        ]);
        return back()->with('success', 'Started user sync');
    }

    public function getSearchEntity(Request $request, $client, $type, $id)
    {
        $cleverObject = null;
        $entityObject = null;
        $lglId = $id;
        $client = Client::find($client);
        $isUser = false;
        if ($type === 'student' || $type === 'teacher') {
            $isUser = true;
            $entityObject = EloquentUser::withTrashed()->find($lglId);
        } elseif ($type === 'roster') {
            $entityObject = Roster::withTrashed()->find($lglId);
        } elseif ($type === 'site') {
            $entityObject = Site::withTrashed()->find($lglId);
        }

        if (!is_null($entityObject)) {
            $this->clever = new Api($client->metadata->data['api_secret']);
            $cleverId = $entityObject->getMetadata('clever_id', false);
            if ($cleverId) {
                if ($isUser && $entityObject->inRole('student')) {
                    $entityObject->role = 'student';
                    $cleverObject = $this->clever->student($cleverId)->data;
                } elseif ($isUser && $entityObject->inRole('teacher')) {
                    $entityObject->role = 'teacher';
                    $cleverObject = $this->clever->teacher($cleverId)->data;
                } elseif ($type == 'site') {
                    $cleverObject = $this->clever->school($cleverId)->data;
                } elseif ($type == 'roster') {
                    $cleverObject = $this->clever->section($cleverId)->data;
                }
            }
            return [
                'entity'     => is_null($entityObject) ? [] : $entityObject,
                'cleverData' => is_null($cleverObject) ? [] : $cleverObject,
                'cleverId'   => $cleverId
            ];
        } else {
            return [
                'error' => 'Entity not found'
            ];
        }
    }

    public function getSearchClever(Request $request, $client, $type, $cleverId)
    {
        $entityObject = null;
        $cleverObject = null;

        $client = Client::find($client);

        $metadata = Metadata::where('data->clever_id', $cleverId)->first();

        if (!is_null($metadata)) {
            if ($type === 'student' || $type === 'teacher') {
                $entityObject = EloquentUser::withTrashed()->with('metadata')->find($metadata->metable_id);
            } elseif ($type === 'roster') {
                $entityObject = Roster::withTrashed()->with('metadata')->find($metadata->metable_id);
            } elseif ($type === 'site') {
                $entityObject = Site::withTrashed()->with('metadata')->find($metadata->metable_id);
            }
        }

        $this->clever = new Api($client->metadata->data['api_secret']);

        if ($type == 'student') {
            $cleverObject = $this->clever->student($cleverId)->data;
        } elseif ($type === 'teacher') {
            $cleverObject = $this->clever->teacher($cleverId)->data;
        } elseif ($type === 'site') {
            $cleverObject = $this->clever->school($cleverId)->data;
        } elseif ($type === 'roster') {
            $cleverObject = $this->clever->section($cleverId)->data;
        }

        if (!is_null($cleverObject)) {
            return [
                'entity'     => $entityObject,
                'cleverData' => $cleverObject,
                'cleverId'   => $cleverId
            ];
        } else {
            return [
                'error' => 'Entity not found'
            ];
        }
    }

    public function postRemoveCleverId(Request $request, $client, $type, $cleverId)
    {
        $shouldDelete = (bool) $request->get('delete', false);
        $metadata = Metadata::where('data->clever_id', $cleverId)->first();

        $metadata->removeData('clever_id');

        if ($shouldDelete) {
            if ($type === 'student' || $type === 'teacher') {
                EloquentUser::where('id', $metadata->metable_id)->delete();
            } elseif ($type === 'section') {
                Roster::where('id', $metadata->metable_id)->delete();
            } elseif ($type === 'site') {
                Site::where('id', $metadata->metable_id)->delete();
            }
        }
        return back()->with('success', 'Removed Clever ID');
    }

    public function postAddCleverId(Request $request, $entityId, $type, $cleverId)
    {
        $entityObject = null;

        if ($type === 'student' || $type === 'teacher') {
            $entityObject = EloquentUser::withTrashed()->with('metadata')->find($entityId);
        } elseif ($type === 'section') {
            $entityObject = Roster::withTrashed()->with('metadata')->find($entityId);
        } elseif ($type === 'site') {
            $entityObject = Site::withTrashed()->with('metadata')->find($entityId);
        }

        if ($entityObject) {
            $entityObject->setMetadata([
                'clever_id' => $cleverId
            ]);
            return back()->with('success', 'Added Clever ID');
        }
        return back()->with('error', 'Entity not found!');
    }
}