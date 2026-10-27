<?php

use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductController::class, 'index']);
Route::post('/checkout', CheckoutController::class)->middleware('throttle:10,1');
Route::get('/orders/{uuid}', [OrderController::class, 'show']);
Route::post('/orders/{uuid}/check-status', [OrderController::class, 'checkStatus'])->middleware('throttle:15,1');
