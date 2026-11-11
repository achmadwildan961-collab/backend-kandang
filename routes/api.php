<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoopController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SensorController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [ProfileController::class, 'show']);
        Route::get('profile', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('profile/password', [ProfileController::class, 'password']);
        Route::middleware('admin')->group(function () {
            Route::apiResource('users', UserController::class)->only(['index', 'store', 'show', 'update']);
            Route::patch('users/{user}/status', [UserController::class, 'status']);
            Route::post('coops', [CoopController::class, 'store']);
            Route::put('coops/{coop}', [CoopController::class, 'update']);
            Route::put('coops/{coop}/users', [CoopController::class, 'assignUsers']);
            Route::post('coops/{coop}/devices', [DeviceController::class, 'store']);
            Route::put('devices/{device}', [DeviceController::class, 'update']);
        });
        Route::get('coops', [CoopController::class, 'index']);
        Route::get('coops/{coop}', [CoopController::class, 'show']);
        Route::get('coops/{coop}/devices', [DeviceController::class, 'index']);
        Route::get('devices/{device}', [DeviceController::class, 'show']);
        Route::get('coops/{coop}/sensors/latest', [SensorController::class, 'latest']);
        Route::get('coops/{coop}/sensors/indoor', [SensorController::class, 'indoor']);
        Route::get('coops/{coop}/sensors/history', [SensorController::class, 'history']);
        Route::get('coops/{coop}/sensors/chart', [SensorController::class, 'chart']);
        Route::get('coops/{coop}/sensors/export', [SensorController::class, 'export']);
        Route::get('coops/{coop}/settings', [SettingsController::class, 'show']);
        Route::put('coops/{coop}/settings', [SettingsController::class, 'update']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'read']);
        Route::get('coops/{coop}/events', [EventController::class, 'index']);
    });
});
