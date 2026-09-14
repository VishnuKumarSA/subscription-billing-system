<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/dashboard');
});

/**
 * A thin static shell: all real data comes from an authenticated client-
 * side fetch() against GET /api/merchants/{id}/dashboard (see
 * resources/views/dashboard.blade.php). There is no server-side session
 * auth in this system - the viewer supplies the API key printed by
 * `php artisan db:seed`, which the page stores in localStorage and sends
 * as the X-API-Key header on every request.
 */
Route::get('/dashboard/{merchant?}', function (?string $merchant = null) {
    return view('dashboard', ['merchantId' => $merchant ?? '']);
})->name('dashboard');
