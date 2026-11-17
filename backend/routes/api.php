<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Auth\ResetPasswordOtpController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientOverviewController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReminderPeriodController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::post('/forgot-password', [ResetPasswordOtpController::class, 'requestOtp'])
    ->middleware('throttle:3,1');
Route::post('/verify-reset-otp', [ResetPasswordOtpController::class, 'verifyOtp'])
    ->middleware('throttle:5,1');
Route::post('/reset-password', [ResetPasswordOtpController::class, 'resetPassword'])
    ->middleware('throttle:5,1');
Route::post('/resend-reset-otp', [ResetPasswordOtpController::class, 'resendOtp'])
    ->middleware('throttle:1,1');

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile'])
        ->middleware(RequirePasswordChange::class);
    Route::get('/auth/preferences', [AuthController::class, 'preferences'])
        ->middleware(RequirePasswordChange::class);
    Route::put('/auth/preferences', [AuthController::class, 'updatePreferences'])
        ->middleware(RequirePasswordChange::class);
    Route::put('/auth/password', [AuthController::class, 'changePassword'])
        ->middleware(RequirePasswordChange::class);
    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware(RequirePasswordChange::class);

    Route::middleware(RequirePasswordChange::class)->group(function (): void {
        // Dashboard: one role-aware payload, scoped by the visibleTo scopes.
        Route::get('/dashboard', DashboardController::class);
        Route::get('/search', SearchController::class)->middleware('throttle:60,1');
        Route::get('/calendar', CalendarController::class);
        Route::get('/clients/{client}/overview', ClientOverviewController::class);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

        Route::get('/users/options', [UserController::class, 'options']);

        // Master data reads: every authenticated user, staff and managers need
        // them to fill dropdowns. "/options" is registered before "/{id}" so it
        // is not swallowed by the wildcard.
        Route::get('/clients/options', [ClientController::class, 'options']);
        Route::get('/clients', [ClientController::class, 'index']);
        Route::get('/clients/{client}', [ClientController::class, 'show']);

        Route::get('/departments/options', [DepartmentController::class, 'options']);
        Route::get('/departments', [DepartmentController::class, 'index']);
        Route::get('/departments/{department}', [DepartmentController::class, 'show']);

        Route::get('/reminder-periods/options', [ReminderPeriodController::class, 'options']);
        Route::get('/reminder-periods', [ReminderPeriodController::class, 'index']);
        Route::get('/reminder-periods/{reminderPeriod}', [ReminderPeriodController::class, 'show']);

        // Tasks: readable by every authenticated user, visibility scoped per user.
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::get('/tasks/{task}', [TaskController::class, 'show']);
        Route::put('/tasks/{task}', [TaskController::class, 'update']);
        Route::patch('/tasks/{task}/due-date', [TaskController::class, 'updateDueDate']);
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
        Route::post('/tasks/{task}/status', [TaskController::class, 'status']);
        Route::post('/tasks/{task}/complete', [TaskController::class, 'complete']);
        Route::get('/tasks/{task}/comments', [TaskController::class, 'comments']);
        Route::post('/tasks/{task}/comments', [TaskController::class, 'storeComment']);
        Route::delete('/tasks/{task}/comments/{comment}', [TaskController::class, 'destroyComment']);

        // Items: readable by every authenticated user, visibility scoped per user
        // (staff only see the items they own). Writes are restricted to admins and
        // managers by the policy and the form requests.
        Route::get('/items', [ItemController::class, 'index']);
        Route::post('/items', [ItemController::class, 'store']);
        Route::get('/items/{item}', [ItemController::class, 'show']);
        Route::put('/items/{item}', [ItemController::class, 'update']);
        Route::patch('/items/{item}/due-date', [ItemController::class, 'updateDueDate']);
        Route::delete('/items/{item}', [ItemController::class, 'destroy']);
        Route::post('/items/{item}/renew', [ItemController::class, 'renew']);
        Route::post('/items/{item}/status', [ItemController::class, 'status']);

        Route::middleware('role:admin')->prefix('users')->group(function (): void {
            Route::get('/', [UserController::class, 'index']);
            Route::get('/summary', [UserController::class, 'summary']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('/{user}/profile', [UserController::class, 'profile']);
            Route::get('/{user}', [UserController::class, 'show']);
            Route::put('/{user}', [UserController::class, 'update']);
            Route::post('/{user}/suspend', [UserController::class, 'suspend']);
            Route::post('/{user}/activate', [UserController::class, 'activate']);
            Route::post('/{user}/reset-password', [UserController::class, 'resetPassword']);
            Route::post('/{user}/restore', [UserController::class, 'restore']);
            Route::delete('/{user}', [UserController::class, 'destroy']);
        });

        Route::middleware('role:admin,manager')->prefix('reports')->group(function (): void {
            Route::get('/tasks-by-person', [ReportsController::class, 'tasksByPerson']);
            Route::get('/items-expiry', [ReportsController::class, 'itemsExpiry']);
            Route::get('/tasks-by-client', [ReportsController::class, 'tasksByClient']);
        });

        // Master data writes: admin only.
        Route::middleware('role:admin')->group(function (): void {
            Route::post('/clients', [ClientController::class, 'store']);
            Route::put('/clients/{client}', [ClientController::class, 'update']);
            Route::post('/clients/{client}/activate', [ClientController::class, 'activate']);
            Route::post('/clients/{client}/deactivate', [ClientController::class, 'deactivate']);
            Route::post('/clients/{client}/restore', [ClientController::class, 'restore']);
            Route::delete('/clients/{client}', [ClientController::class, 'destroy']);

            Route::post('/departments', [DepartmentController::class, 'store']);
            Route::put('/departments/{department}', [DepartmentController::class, 'update']);
            Route::post('/departments/{department}/activate', [DepartmentController::class, 'activate']);
            Route::post('/departments/{department}/deactivate', [DepartmentController::class, 'deactivate']);
            Route::post('/departments/{department}/restore', [DepartmentController::class, 'restore']);
            Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);

            Route::post('/reminder-periods', [ReminderPeriodController::class, 'store']);
            Route::put('/reminder-periods/{reminderPeriod}', [ReminderPeriodController::class, 'update']);
            Route::post('/reminder-periods/{reminderPeriod}/activate', [ReminderPeriodController::class, 'activate']);
            Route::post('/reminder-periods/{reminderPeriod}/deactivate', [ReminderPeriodController::class, 'deactivate']);
            Route::delete('/reminder-periods/{reminderPeriod}', [ReminderPeriodController::class, 'destroy']);
        });
    });
});
