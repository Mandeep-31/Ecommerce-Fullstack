<?php

use App\Http\Controllers\EsewaCallbackController;
use App\Http\Controllers\KhaltiCallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/esewa/success', [EsewaCallbackController::class, 'success'])->name('esewa.success');
Route::get('/esewa/failure', [EsewaCallbackController::class, 'failure'])->name('esewa.failure');
Route::get('/khalti/return', KhaltiCallbackController::class)->name('khalti.return');
