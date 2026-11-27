<?php

use App\Http\Controllers\Settings\EmailChangeController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Middleware\EnsurePasswordConfirmedIfSet;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'active'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::post('settings/email/change-request', [EmailChangeController::class, 'requestChange'])
        ->middleware('throttle:10,1')
        ->name('settings.email.change-request');
    Route::post('settings/email/verify-otp', [EmailChangeController::class, 'verifyChange'])
        ->middleware('throttle:15,1')
        ->name('settings.email.verify-otp');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(EnsurePasswordConfirmedIfSet::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::delete('settings/other-browser-sessions', [SecurityController::class, 'logoutOtherSessions'])
        ->middleware('throttle:6,1')
        ->name('other-browser-sessions.destroy');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
