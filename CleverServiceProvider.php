<?php

namespace LGL\Clever;

use Illuminate\Support\ServiceProvider;
use LGL\Clever\Commands\CleanCleverAccount;
use LGL\Clever\Commands\CleverIdCleaner;
use LGL\Clever\Commands\CleverReport;
use LGL\Clever\Commands\CleverRosterCleaner;
use LGL\Clever\Commands\CleverSync;
use LGL\Clever\Commands\CleverUserCleaner;
use LGL\Clever\Commands\CleverUsername;


class CleverServiceProvider extends ServiceProvider
{

    /**
     * Instruction Service Provider
     *
     * @return void
     */
    public function register(): void
    {


    }

    /**
     * Register Files to publish
     *
     * @return void
     */
    public function boot(): void
    {

//        $resourcePath = __DIR__ . '/resources/';
//        $routesPath = __DIR__ . '/Http/Routes/';

        $this->commands([
            CleanCleverAccount::class,
            CleverIdCleaner::class,
            CleverRosterCleaner::class,
            CleverReport::class,
            CleverSync::class,

            CleverUserCleaner::class,
            CleverUsername::class
        ]);
    }
}
