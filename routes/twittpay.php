<?php

use App\Http\Controllers\TwittPayController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| TwittPay routes
|--------------------------------------------------------------------------
|
| This file is not loaded on its own. Add one line to routes/web.php:
|
|     require __DIR__ . '/twittpay.php';
|
| It is kept separate so that installing or updating this package never
| overwrites your own routes/web.php.
|
*/

// CSRF must be off for the webhook - the gateway's server has no token and never
// will, and a protected route answers 419 while the payment silently never lands.
// Laravel renamed the middleware class, so both names are listed and only the one
// your version actually has is used.
$twittPayWithoutCsrf = array_values(array_filter([
    'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
    'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
], 'class_exists'));

Route::get('twittpay/pay/{amount?}', [TwittPayController::class, 'pay'])
    ->name('twittpay.pay');

Route::get('twittpay/back', [TwittPayController::class, 'back'])
    ->name('twittpay.back');

Route::get('twittpay/cancelled', [TwittPayController::class, 'cancelled'])
    ->name('twittpay.cancelled');

Route::post('twittpay/webhook', [TwittPayController::class, 'webhook'])
    ->withoutMiddleware($twittPayWithoutCsrf)
    ->name('twittpay.webhook');
