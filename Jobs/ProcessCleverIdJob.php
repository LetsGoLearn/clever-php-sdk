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
use Illuminate\Support\Facades\Redis;
class ProcessCleverIdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;
    protected $client;
    protected string $role;
    protected $clever;
    protected $forceDelete;
    public $tries = 1;

    public function __construct($userId, $clientId, $role, $forceDelete = false)
    {
        $this->onQueue('default');
        $this->forceDelete = $forceDelete;
        $this->client = Client::find($clientId);
        $this->user = EloquentUser::withTrashed()->where('id', '=', $userId)->with('metadata')->first();
        $this->role = $role;

    }
    public function handle()
    {
        $redisListKey = 'clever_user_cleaner:' . $this->client->id . ':' . Carbon::now()->format('Y:m:d');
        $this->clever = new Api($this->client->metadata->data['api_secret']);
        $return = $this->clever->{$this->role}($this->user->metadata->data['clever_id']);
        $cleverId = $this->user->metadata->data['clever_id'];
        $logData = [
            'command' => 'clever:user:fixer',
            'clever_id' => $this->user->metadata->data['clever_id'],
            'lglId' => $this->user->id,
            'client_id' => $this->user->client_id,
            'role' => $this->role,
            'email' => $this->user->email,
            'description' => 'N/A'
        ]; $this->clever->{$this->role}($this->user->metadata->data['clever_id']);

        if (isset($return->data['error'])) {
            $logData['clever_error'] = $return->data['error'];
            if ($this->forceDelete === true) {
                $this->user->metadata->delete();
                $this->user->forceDelete();
                $logData['description'] = "Force Deleted user with Clever ID: $cleverId";
            }
            else
            {
                $data = $this->user->metadata->data;
                $data['old_clever_id'] = $data['clever_id'];
                unset($data['clever_id']);
                $metadata = $this->user->metadata;
                $metadata->data = $data;
                $metadata->save();
                $logData['description'] = 'Removed Clever ID from metadata & soft deleted user.';
                $this->user->delete();
            }
            $logData['date_time'] = Carbon::now()->toDateTimeString();
            $logData['process'] = 'CleverIdUserCleaner';
        }

        Redis::rpush($redisListKey, json_encode($logData));
        Log::warning('['.Carbon::now()->toDateTimeString().'][CleverIdUserCleaner] '. json_encode($logData));
    }
}
