<?php

declare(strict_types=1);

use App\Http\Controllers\AuditController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\Opsbot\AnalyticsController;
use App\Http\Controllers\Opsbot\AssistantSettingsController;
use App\Http\Controllers\Opsbot\BotController;
use App\Http\Controllers\Opsbot\DepartmentsController;
use App\Http\Controllers\Opsbot\DeskFeedController;
use App\Http\Controllers\Opsbot\EmployeesController;
use App\Http\Controllers\Opsbot\IncidentsController;
use App\Http\Controllers\Opsbot\OnboardingSetupController;
use App\Http\Controllers\Opsbot\TasksController;
use App\Http\Controllers\Opsbot\UnansweredController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'workspace'])->group(function (): void {
    Route::get('onboarding/setup', [OnboardingSetupController::class, 'show'])->name('onboarding.setup');
    Route::post('onboarding/setup/skip', [OnboardingSetupController::class, 'skip'])->name('onboarding.setup.skip');
    Route::post('onboarding/setup/finish', [OnboardingSetupController::class, 'finish'])->name('onboarding.setup.finish');

    Route::get('desk/feed', DeskFeedController::class)->middleware('throttle:60,1')->name('desk.feed');

    Route::get('bot', [BotController::class, 'index'])->name('bot.index');
    Route::post('bot', [BotController::class, 'store'])->middleware('throttle:bot-connect')->name('bot.store');
    Route::patch('bot/token', [BotController::class, 'updateToken'])->middleware('throttle:bot-connect')->name('bot.token');
    Route::delete('bot', [BotController::class, 'destroy'])->name('bot.destroy');
    Route::post('bot/test-message', [BotController::class, 'testMessage'])->middleware('throttle:6,1')->name('bot.test-message');

    Route::get('employees', [EmployeesController::class, 'index'])->name('employees.index');
    Route::post('employees', [EmployeesController::class, 'store'])->name('employees.store');
    Route::post('employees/links/bulk', [EmployeesController::class, 'bulkLinks'])->middleware('throttle:20,1')->name('employees.links.bulk');
    Route::patch('employees/{user}', [EmployeesController::class, 'update'])->whereNumber('user')->name('employees.update');
    Route::delete('employees/{user}', [EmployeesController::class, 'destroy'])->whereNumber('user')->name('employees.destroy');
    Route::post('employees/{user}/link', [EmployeesController::class, 'link'])->whereNumber('user')->middleware('throttle:60,1')->name('employees.link');
    Route::post('employees/{user}/reactivate', [EmployeesController::class, 'reactivate'])->whereNumber('user')->name('employees.reactivate');
    Route::get('employees/{user}/export', [EmployeesController::class, 'export'])->whereNumber('user')->middleware('throttle:10,1')->name('employees.export');
    Route::post('employees/{user}/erase', [EmployeesController::class, 'erase'])->whereNumber('user')->middleware(RequirePassword::class)->name('employees.erase');

    Route::get('departments', [DepartmentsController::class, 'index'])->name('departments.index');
    Route::post('departments', [DepartmentsController::class, 'store'])->name('departments.store');
    Route::put('departments/routing', [DepartmentsController::class, 'routing'])->name('departments.routing');
    Route::patch('departments/{department}', [DepartmentsController::class, 'update'])->whereNumber('department')->name('departments.update');
    Route::delete('departments/{department}', [DepartmentsController::class, 'destroy'])->whereNumber('department')->name('departments.destroy');
    Route::get('routing/preview', [DepartmentsController::class, 'preview'])->name('routing.preview');

    Route::post('knowledge/starter-pack', [KnowledgeController::class, 'starterPack'])
        ->middleware('throttle:uploads')
        ->name('knowledge.starter-pack');
    Route::post('knowledge/approve-bulk', [KnowledgeController::class, 'approveBulk'])->name('knowledge.approve-bulk');
    Route::post('knowledge/{document}/approve', [KnowledgeController::class, 'approve'])->whereNumber('document')->name('knowledge.approve');
    Route::post('knowledge/{document}/archive', [KnowledgeController::class, 'archive'])->whereNumber('document')->name('knowledge.archive');
    Route::post('knowledge/{document}/retry', [KnowledgeController::class, 'retry'])->whereNumber('document')->name('knowledge.retry');
    Route::post('knowledge/{document}/test', [KnowledgeController::class, 'test'])->whereNumber('document')->middleware('throttle:assistant')->name('knowledge.test');

    Route::get('incidents', [IncidentsController::class, 'index'])->name('incidents.index');
    Route::post('incidents', [IncidentsController::class, 'store'])->name('incidents.store');
    Route::get('incidents/{incident}', [IncidentsController::class, 'show'])->whereNumber('incident')->name('incidents.show');
    Route::patch('incidents/{incident}', [IncidentsController::class, 'update'])->whereNumber('incident')->name('incidents.update');
    Route::post('incidents/{incident}/comments', [IncidentsController::class, 'comment'])->whereNumber('incident')->name('incidents.comments');
    Route::post('incidents/{incident}/tasks', [IncidentsController::class, 'storeTask'])->whereNumber('incident')->name('incidents.tasks');

    Route::get('tasks', [TasksController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TasksController::class, 'store'])->name('tasks.store');
    Route::patch('tasks/{task}', [TasksController::class, 'update'])->whereNumber('task')->name('tasks.update');

    Route::get('unanswered', [UnansweredController::class, 'index'])->name('unanswered.index');
    Route::post('unanswered/{question}/answer', [UnansweredController::class, 'answer'])->whereNumber('question')->name('unanswered.answer');
    Route::post('unanswered/{question}/dismiss', [UnansweredController::class, 'dismiss'])->whereNumber('question')->name('unanswered.dismiss');
    Route::post('unanswered/{question}/convert', [UnansweredController::class, 'convert'])->whereNumber('question')->name('unanswered.convert');
    Route::patch('unanswered/{question}', [UnansweredController::class, 'update'])->whereNumber('question')->name('unanswered.update');

    Route::get('analytics', AnalyticsController::class)->name('analytics.index');

    Route::get('settings/assistant', [AssistantSettingsController::class, 'edit'])->name('settings.assistant');
    Route::patch('settings/assistant', [AssistantSettingsController::class, 'update'])->name('settings.assistant.update');

    Route::get('audit/export', [AuditController::class, 'export'])->middleware('throttle:10,1')->name('audit.export');
});

Route::get('employees/exports/{export}', [EmployeesController::class, 'download'])
    ->whereUuid('export')
    ->middleware(['auth', 'verified', 'signed:relative', 'workspace'])
    ->name('employees.exports.download');
