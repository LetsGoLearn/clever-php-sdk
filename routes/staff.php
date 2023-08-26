<?php


use LGL\Clever\Http\Controllers\CleverLookupController;


Route::group([
    'domain'     => config('urls.staff', 'staff.lgl.test'),
    'as'         => 'staff.',
    'middleware' => ['auth', 'staffauth', 'inertia.spa']
], function ($route) {
    $route->get('/client/{client}/clever/matcher', [
        CleverLookupController::class, 'getLookup'
    ])->name('clever.entity.lookup');

    $route->get('/client/{client}/clever/search/{type}/{id}', [
        CleverLookupController::class, 'getSearchEntity'
    ])->name('clever.entity.search');

    $route->get('/client/{client}/clever/search/{type}/{id}/clever', [
        CleverLookupController::class, 'getSearchClever'
    ])->name('clever.entity.search.clever');

    $route->get('/client/{client}/clever/sync/{type}/{id}', [
        CleverLookupController::class, 'runSync'
    ])->name('clever.entity.sync');

    $route->post('/client/{client}/clever/remove/{type}/{id}', [
        CleverLookupController::class, 'postRemoveCleverId'
    ])->name('clever.entity.remove');

    $route->post('/client/{user}/clever/add/{type}/{id}', [
        CleverLookupController::class, 'postAddCleverId'
    ])->name('clever.entity.add');
});