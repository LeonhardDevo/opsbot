<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\AiQueriesController;
use App\Http\Controllers\Internal\DeliveriesController;
use App\Http\Controllers\Internal\DocumentsController;
use App\Http\Controllers\Internal\OpsbotController;
use App\Http\Controllers\Internal\PingController;
use App\Http\Controllers\Internal\PrivacyController;
use App\Http\Controllers\Internal\StripeEventsController;
use App\Http\Controllers\Internal\TelegramBotsController;
use App\Http\Controllers\Internal\TelegramController;
use App\Http\Controllers\Internal\YooKassaController;
use Illuminate\Support\Facades\Route;

Route::get('ping', PingController::class)->name('ping');

Route::middleware('internal.caller:worker')->group(function (): void {
    Route::post('telegram/link', [TelegramController::class, 'link'])->name('telegram.link');
    Route::get('telegram/context', [TelegramController::class, 'context'])->name('telegram.context');
    Route::post('telegram/unlink', [TelegramController::class, 'unlink'])->name('telegram.unlink');
    Route::post('telegram/notice/ack', [TelegramController::class, 'noticeAck'])->name('telegram.notice.ack');
    Route::post('telegram/actions', [TelegramController::class, 'action'])->name('telegram.actions');
    Route::post('telegram/chats/blocked', [TelegramController::class, 'chatBlocked'])->name('telegram.chats.blocked');

    Route::get('telegram/bots/active', [TelegramBotsController::class, 'active'])->name('telegram.bots.active');
    Route::get('telegram/bots/{bot}/credentials', [TelegramBotsController::class, 'credentials'])->whereNumber('bot')->name('telegram.bots.credentials');
    Route::post('telegram/bots/{bot}/status', [TelegramBotsController::class, 'status'])->whereNumber('bot')->name('telegram.bots.status');

    Route::post('privacy/purge', [PrivacyController::class, 'purge'])->name('privacy.purge');
    Route::post('privacy/exports/{export}/build', [PrivacyController::class, 'buildExport'])
        ->whereNumber('export')
        ->name('privacy.exports.build');

    Route::post('deliveries', DeliveriesController::class)->name('deliveries');

    Route::post('stripe/events', StripeEventsController::class)->name('stripe.events');

    Route::get('documents/{document}/file', [DocumentsController::class, 'file'])->whereNumber('document')->name('documents.file');
    Route::post('documents/{document}/status', [DocumentsController::class, 'status'])->whereNumber('document')->name('documents.status');

    Route::prefix('opsbot')->name('opsbot.')->group(function (): void {
        Route::get('history', [OpsbotController::class, 'history'])->name('history');
        Route::get('queue', [OpsbotController::class, 'queue'])->name('queue');
        Route::post('incidents', [OpsbotController::class, 'createIncident'])->name('incidents.store');
        Route::post('questions/escalate-last', [OpsbotController::class, 'escalateLast'])->name('questions.escalate-last');
        Route::post('questions/{question}/answer', [OpsbotController::class, 'answerQuestion'])->whereNumber('question')->name('questions.answer');
        Route::post('reminders/dispatch', [OpsbotController::class, 'remindersDispatch'])->name('reminders.dispatch');
        Route::post('documents/tick', [OpsbotController::class, 'documentsTick'])->name('documents.tick');
        Route::post('limits/tick', [OpsbotController::class, 'limitsTick'])->name('limits.tick');
        Route::post('bots/reconcile', [OpsbotController::class, 'botsReconcile'])->name('bots.reconcile');
    });

    Route::post('yookassa/notifications', [YooKassaController::class, 'notification'])->name('yookassa.notifications');
    Route::post('billing/renewals', [YooKassaController::class, 'renewals'])->name('billing.renewals');
});

Route::middleware('internal.caller:ai')->prefix('opsbot')->name('opsbot.')->group(function (): void {
    Route::get('routing/resolve', [OpsbotController::class, 'routing'])->name('routing.resolve');
    Route::get('incidents/{number}', [OpsbotController::class, 'incident'])->whereNumber('number')->name('incidents.show');
});

Route::get('opsbot/tasks', [OpsbotController::class, 'tasks'])
    ->middleware('internal.caller:ai,worker')
    ->name('opsbot.tasks');

Route::post('ai-queries', AiQueriesController::class)
    ->middleware('internal.caller:ai,worker')
    ->name('ai-queries');
