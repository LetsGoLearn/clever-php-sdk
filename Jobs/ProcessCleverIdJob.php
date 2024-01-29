<?php

namespace LGL\Clever\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use LGL\Auth\Users\EloquentUser;
use LGL\Core\Accounts\Models\Client;
use LGL\Clever\Api;
class ProcessCleverIdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;
    protected $client;
    protected string $role;
    protected $clever;
    public $tries = 1;

    public function __construct($userId, $clientId, $role)
    {
        $this->onQueue('default');
        $this->client = Client::find($clientId);
        $this->user = EloquentUser::withTrashed()->where('id', '=', $userId)->with('metadata')->first();
        $this->role = $role;
    }

    public function handle()
    {
        $this->clever = new Api($this->client->metadata->data['api_secret']);
        $return = $this->clever->{$this->role}($this->user->metadata->data['clever_id']);
        if (isset($return->data['error'])) {
            $cleverId = $this->user->metadata->data['clever_id'];

            $logData = [
                'command' => 'clever:user:fixer',
                'clever_id' => $cleverId,
                'lglId' => $this->user->id,
                'client_id' => $this->user->client_id,
                'role' => $this->role,
                'email' => $this->user->email
            ];

            if ($return->data['error'] == 'Resource not found') {
                $data = $this->user->metadata->data;
                $data['old_clever_id'] = $data['clever_id'];
                unset($data['clever_id']);
                $metadata = $this->user->metadata;
                $metadata->data = $data;
                $metadata->save();
                $logData['softDelete'] = true;
                $logData['description'] = 'Removed Clever ID from metadata & soft deleted user.';
                $this->user->delete();
            }
            else {
                $logData['softDelete'] = false;
                $logData['description'] = 'Removed Clever ID from metadata & soft deleted user.';
            }
            Log::warning('['.Carbon::now()->toDateTimeString().'][CleverIdUserCleaner] '. json_encode($logData));
        }
    }

}
