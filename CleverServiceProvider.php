<?php

namespace LGL\Clever;

use Illuminate\Support\ServiceProvider;
use LGL\Clever\Commands\CleverEvents;
use LGL\Clever\Commands\CleverSync;
use LGL\Clever\Commands\CleverSyncBySections;
use LGL\Clever\Commands\SectionSync;
use LGL\Clever\Commands\SiteSync;
use LGL\Clever\Commands\UserSync;
use LGL\Clever\Commands\CleanCleverAccount;
use LGL\Clever\Commands\CleverUserCleaner;
use LGL\Clever\Commands\CleverRosterCleaner;
use LGL\Clever\Commands\CleverIdCleaner;



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
            CleverSync::class,
            CleverEvents::class,
            CleverSyncBySections::class,
            SiteSync::class,
            SectionSync::class,
            UserSync::class,
            CleanCleverAccount::class,
            CleverUserCleaner::class,
            CleverRosterCleaner::class,
            CleverIdCleaner::class,
        ]);
    }
}
