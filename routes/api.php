<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeadActivityController;
use App\Http\Controllers\Api\LeadAssignmentController;
use App\Http\Controllers\Api\LeadController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('leads', LeadController::class)->only(['index', 'store', 'show']);
    // PATCH only: every field is optional, so accepting PUT would promise a full replacement that doesn't happen.
    Route::patch('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::post('leads/{lead}/assign', LeadAssignmentController::class)->name('leads.assign');
    Route::post('leads/{lead}/activities', LeadActivityController::class)->name('leads.activities.store');
});
