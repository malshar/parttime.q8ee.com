<?php

use App\Http\Controllers\Admin\ApplicationController as AdminApplicationController;
use App\Http\Controllers\Admin\AssignmentController;
use App\Http\Controllers\Admin\AttestationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentController as AdminDocumentController;
use App\Http\Controllers\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Admin\SectionController;
use App\Http\Controllers\Admin\SectionImportController;
use App\Http\Controllers\Admin\TermController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\Instructor\ApplicationController as InstructorApplicationController;
use App\Http\Controllers\Instructor\DocumentController as InstructorDocumentController;
use App\Http\Controllers\Instructor\ProfileController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:register')->name('register.store');
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.attempt');
    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:6,1')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('email/verify', [VerificationController::class, 'notice'])->name('verification.notice');
    Route::get('email/verify/{id}/{hash}', [VerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('email/verification-notification', [VerificationController::class, 'resend'])->middleware('throttle:6,1')->name('verification.send');
});

// Instructor area (verified only).
Route::middleware(['auth', 'verified', 'role:instructor'])->prefix('my')->name('instructor.')->group(function () {
    Route::get('/', [InstructorApplicationController::class, 'home'])->name('home');
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('applications', [InstructorApplicationController::class, 'start'])->name('applications.start');
    Route::get('applications/{application}', [InstructorApplicationController::class, 'show'])->name('applications.show');
    Route::post('applications/{application}/submit', [InstructorApplicationController::class, 'submit'])->name('applications.submit');
    Route::post('applications/{application}/withdraw', [InstructorApplicationController::class, 'withdraw'])->name('applications.withdraw');
    Route::post('applications/{application}/documents/{item:code}', [InstructorDocumentController::class, 'store'])->name('documents.store')->withoutScopedBindings();
    Route::get('documents/{document}', [InstructorDocumentController::class, 'download'])->name('documents.download');
});

// Admin area.
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('terms', [TermController::class, 'index'])->name('terms.index');
    Route::get('terms/create', [TermController::class, 'create'])->name('terms.create');
    Route::post('terms', [TermController::class, 'store'])->name('terms.store');
    Route::get('terms/{term}/edit', [TermController::class, 'edit'])->name('terms.edit');
    Route::put('terms/{term}', [TermController::class, 'update'])->name('terms.update');
    Route::post('terms/{term}/close', [TermController::class, 'close'])->name('terms.close');
    Route::get('applications', [AdminApplicationController::class, 'index'])->name('applications.index');
    Route::get('applications/{application}', [AdminApplicationController::class, 'show'])->name('applications.show');
    Route::get('applications/{application}/profile', [AdminProfileController::class, 'edit'])->name('applications.profile.edit');
    Route::put('applications/{application}/profile', [AdminProfileController::class, 'update'])->name('applications.profile.update');
    Route::get('applications/{application}/checklist', [AdminApplicationController::class, 'checklist'])->name('applications.checklist');
    Route::post('applications/{application}/reveal', [AdminApplicationController::class, 'reveal'])->name('applications.reveal');
    Route::post('applications/{application}/complete', [AdminApplicationController::class, 'complete'])->name('applications.complete');
    Route::post('applications/{application}/renewals/{item:code}', [AdminApplicationController::class, 'requestFreshCopy'])->name('applications.renewals.store')->withoutScopedBindings();
    Route::post('applications/{application}/notify-rejections', [AdminApplicationController::class, 'notifyRejections'])->name('applications.notify_rejections');
    Route::post('applications/{application}/committee', [AdminApplicationController::class, 'committee'])->name('applications.committee');
    Route::post('applications/{application}/reopen', [AdminApplicationController::class, 'reopen'])->name('applications.reopen');
    Route::post('applications/{application}/decision', [AdminApplicationController::class, 'decision'])->name('applications.decision');
    Route::post('documents/{document}/review', [AdminDocumentController::class, 'review'])->name('documents.review');
    Route::get('documents/{document}', [AdminDocumentController::class, 'download'])->name('documents.download');
    Route::get('documents/{document}/view', [AdminDocumentController::class, 'view'])->name('documents.view');
    Route::get('sections', [SectionController::class, 'index'])->name('sections.index');
    Route::get('sections/import', [SectionImportController::class, 'form'])->name('sections.import.form');
    Route::post('sections/import/preview', [SectionImportController::class, 'preview'])->name('sections.import.preview');
    Route::post('sections/import/confirm', [SectionImportController::class, 'confirm'])->name('sections.import.confirm');
    Route::get('assignments', [AssignmentController::class, 'index'])->name('assignments.index');
    Route::post('sections/{section}/assign', [AssignmentController::class, 'store'])->name('assignments.store');
    Route::delete('sections/{section}/assign', [AssignmentController::class, 'destroy'])->name('assignments.destroy');
    Route::get('attestations', [AttestationController::class, 'index'])->name('attestations.index');
    Route::post('attestations/generate', [AttestationController::class, 'generate'])->name('attestations.generate');
    Route::get('attestations/combined', [AttestationController::class, 'combined'])->name('attestations.combined');
    Route::get('attestations/{attestation}/download', [AttestationController::class, 'download'])->name('attestations.download');
    Route::get('attestations/{attestation}', [AttestationController::class, 'show'])->name('attestations.show');
    Route::put('attestations/{attestation}', [AttestationController::class, 'update'])->name('attestations.update');
    Route::post('attestations/{attestation}/regenerate', [AttestationController::class, 'regenerate'])->name('attestations.regenerate');
    Route::post('attestations/{attestation}/unlock', [AttestationController::class, 'unlock'])->name('attestations.unlock');
});
