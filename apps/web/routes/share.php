<?php

declare(strict_types=1);

use App\Http\Controllers\Opsbot\ShareDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('s/doc/{document}', [ShareDocumentController::class, 'show'])
    ->whereNumber('document')
    ->middleware('throttle:share')
    ->name('share.document');

Route::get('s/doc/{document}/file', [ShareDocumentController::class, 'file'])
    ->whereNumber('document')
    ->middleware('throttle:share')
    ->name('share.document.file');
