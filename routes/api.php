<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SharingController;
use App\Http\Controllers\Api\V1\SmartListController;
use App\Http\Controllers\Api\V1\StepController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskListController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class);

    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('apple', [AuthController::class, 'apple']);
        Route::post('google', [AuthController::class, 'google']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('me', [MeController::class, 'show']);
        Route::patch('me', [MeController::class, 'update']);
        Route::delete('me', [MeController::class, 'destroy']);

        Route::get('groups', [GroupController::class, 'index']);
        Route::post('groups', [GroupController::class, 'store']);
        Route::patch('groups/{group}', [GroupController::class, 'update']);
        Route::delete('groups/{group}', [GroupController::class, 'destroy']);

        Route::get('lists', [TaskListController::class, 'index']);
        Route::post('lists', [TaskListController::class, 'store']);
        Route::get('lists/{list}', [TaskListController::class, 'show']);
        Route::patch('lists/{list}', [TaskListController::class, 'update']);
        Route::delete('lists/{list}', [TaskListController::class, 'destroy']);
        Route::post('lists/{list}/duplicate', [TaskListController::class, 'duplicate']);

        Route::get('lists/{list}/tasks', [TaskController::class, 'index']);
        Route::post('lists/{list}/tasks', [TaskController::class, 'store']);
        Route::post('tasks', [TaskController::class, 'storeQuick']);
        Route::get('tasks/{task}', [TaskController::class, 'show']);
        Route::patch('tasks/{task}', [TaskController::class, 'update']);
        Route::delete('tasks/{task}', [TaskController::class, 'destroy']);

        Route::get('tasks/{task}/steps', [StepController::class, 'index']);
        Route::post('tasks/{task}/steps', [StepController::class, 'store']);
        Route::patch('steps/{step}', [StepController::class, 'update']);
        Route::delete('steps/{step}', [StepController::class, 'destroy']);
        Route::post('steps/{step}/promote', [StepController::class, 'promote']);

        Route::get('tasks/{task}/attachments', [AttachmentController::class, 'index']);
        Route::post('tasks/{task}/attachments', [AttachmentController::class, 'store']);
        Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download']);
        Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy']);

        Route::get('smart-lists/counts', [SmartListController::class, 'counts']);
        Route::get('smart-lists/{name}/tasks', [SmartListController::class, 'tasks']);
        Route::get('my-day/suggestions', [SmartListController::class, 'suggestions']);
        Route::get('search', SearchController::class);

        Route::post('lists/{list}/invites', [SharingController::class, 'createInvite']);
        Route::get('invites/{token}', [SharingController::class, 'preview']);
        Route::post('invites/{token}/accept', [SharingController::class, 'accept']);
        Route::patch('lists/{list}/sharing', [SharingController::class, 'updateSharing']);
        Route::delete('lists/{list}/sharing', [SharingController::class, 'stopSharing']);
        Route::get('lists/{list}/members', [SharingController::class, 'members']);
        Route::patch('lists/{list}/members/me', [SharingController::class, 'updateMyMembership']);
        Route::delete('lists/{list}/members/{userId}', [SharingController::class, 'removeMember']);

        Route::get('sync', SyncController::class);
    });
});
