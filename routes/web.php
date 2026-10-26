<?php

use Illuminate\Support\Facades\Route;

// Die Startseite liefert ab Stufe 1 das Lava-Theme der Standard-Site; bis dahin führt sie ins Backend (TB 00).

// Passkeys: Einrichten und Verwalten liegen im Backend des Kerns (K-04), nicht mehr in den Starter-Kit-Einstellungen.
Route::get('.well-known/passkey-endpoints', static fn () => response()->json([
    'enroll' => route('basalto.passkeys'),
    'manage' => route('basalto.passkeys'),
]))->name('well-known.passkeys');
