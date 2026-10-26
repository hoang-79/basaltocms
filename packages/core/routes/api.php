<?php

declare(strict_types=1);

use Basalto\Core\Http\Controllers\Api\EntryController;
use Basalto\Core\Http\Controllers\Api\MediaController;
use Basalto\Core\Http\Controllers\Api\NavigationController;
use Basalto\Core\Http\Middleware\ResolveSiteFromToken;
use Illuminate\Support\Facades\Route;

// REST-API (M-17): ohne Sitzung und Cookies, Anmeldung mit einem Sanctum-Token je Site (Profil → API-Tokens).
// Die Site kommt aus dem Token, lesen braucht «read», schreiben «write»; Rechte danach über die Policies.
// Eigenes Limit je Token («basalto-api») — ein Limit ohne Namen teilte den Zähler mit anderen Routen.
Route::middleware(['api', 'auth:sanctum', ResolveSiteFromToken::class, 'throttle:basalto-api'])->prefix('api/v1')->name('basalto.api.')->group(static function (): void {
    Route::get('entries', [EntryController::class, 'index'])->name('entries.index');
    Route::post('entries', [EntryController::class, 'store'])->name('entries.store');
    Route::get('entries/{id}', [EntryController::class, 'show'])->whereNumber('id')->name('entries.show');
    Route::patch('entries/{id}', [EntryController::class, 'update'])->whereNumber('id')->name('entries.update');
    Route::delete('entries/{id}', [EntryController::class, 'destroy'])->whereNumber('id')->name('entries.destroy');
    Route::post('entries/{id}/workflow/{action}', [EntryController::class, 'workflow'])->whereNumber('id')
        ->whereIn('action', ['submit', 'withdraw', 'approve', 'reject', 'schedule', 'unschedule', 'publish', 'unpublish'])->name('entries.workflow');

    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media', [MediaController::class, 'store'])->name('media.store');
    Route::get('media/{id}', [MediaController::class, 'show'])->whereNumber('id')->name('media.show');
    Route::get('media/{id}/file', [MediaController::class, 'file'])->whereNumber('id')->name('media.file');
    Route::patch('media/{id}', [MediaController::class, 'update'])->whereNumber('id')->name('media.update');
    Route::delete('media/{id}', [MediaController::class, 'destroy'])->whereNumber('id')->name('media.destroy');

    Route::get('navigation', [NavigationController::class, 'index'])->name('navigation.index');
    Route::get('navigation/{nav}', [NavigationController::class, 'show'])->where('nav', '[a-z][a-z0-9_-]*')->name('navigation.show');
    Route::post('navigation/{nav}/items', [NavigationController::class, 'store'])->where('nav', '[a-z][a-z0-9_-]*')->name('navigation.items.store');
    Route::put('navigation/items/{id}', [NavigationController::class, 'update'])->whereNumber('id')->name('navigation.items.update');
    Route::delete('navigation/items/{id}', [NavigationController::class, 'destroy'])->whereNumber('id')->name('navigation.items.destroy');
});
