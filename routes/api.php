<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PublicRegistrationController;
use App\Http\Controllers\PublicVotingController;
use App\Http\Controllers\RaffleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VotingResultController;
use App\Http\Controllers\VotingSubjectController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest');

Route::prefix('registration/events')->group(function () {
    Route::get('/{event:slug}', [PublicRegistrationController::class, 'show']);
    Route::post('/{event:slug}', [PublicRegistrationController::class, 'store']);
});

Route::get('/voting/{subject:slug}', [PublicVotingController::class, 'show'])
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->middleware('throttle:public-voting-lookup')
    ->missing(fn () => response()->json(['message' => 'Not Found'], 404));
Route::post('/voting/{subject:slug}/votes', [PublicVotingController::class, 'store'])
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->middleware(['throttle:public-voting-submission', 'throttle:public-voting-registration'])
    ->missing(fn () => response()->json(['message' => 'Not Found'], 404));

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
    Route::put('/password', [PasswordController::class, 'update']);

    Route::middleware('role:Admin,Scanner')->group(function () {
        Route::get('/scanner/events', [EventController::class, 'scannerEvents']);
        Route::post('/events/{event:slug}/check-ins', [EventController::class, 'checkIn']);
    });

    Route::middleware('role:Admin')->group(function () {
        Route::apiResource('users', UserController::class)->except(['show']);
        Route::get('/events', [EventController::class, 'index']);
        Route::post('/events', [EventController::class, 'store']);
        Route::get('/events/{event:slug}/voting-subjects', [VotingSubjectController::class, 'index']);
        Route::post('/events/{event:slug}/voting-subjects', [VotingSubjectController::class, 'store']);
        Route::get('/events/{event:slug}/voting-subjects/{subject}', [VotingSubjectController::class, 'show']);
        Route::get('/events/{event:slug}/voting-subjects/{subject:slug}/results', [VotingResultController::class, 'show']);
        Route::patch('/events/{event:slug}/voting-subjects/{subject}', [VotingSubjectController::class, 'update']);
        Route::delete('/events/{event:slug}/voting-subjects/{subject}', [VotingSubjectController::class, 'destroy']);
        Route::post('/events/{event:slug}/voting-subjects/{subject}/activate', [VotingSubjectController::class, 'activate']);
        Route::post('/events/{event:slug}/voting-subjects/{subject}/close', [VotingSubjectController::class, 'close']);
        Route::get('/events/{event:slug}', [EventController::class, 'show']);
        Route::get('/events/{event:slug}/raffle', [RaffleController::class, 'show']);
        Route::put('/events/{event:slug}/raffle/settings', [RaffleController::class, 'updateSettings']);
        Route::post('/events/{event:slug}/raffle/logo', [RaffleController::class, 'uploadLogo']);
        Route::delete('/events/{event:slug}/raffle/logo', [RaffleController::class, 'removeLogo']);
        Route::post('/events/{event:slug}/raffle/draws', [RaffleController::class, 'store']);
        Route::post('/events/{event:slug}/raffle/draws/{draw}/confirm', [RaffleController::class, 'confirm']);
        Route::delete('/events/{event:slug}/raffle/draws/{draw}', [RaffleController::class, 'destroy']);
        Route::match(['put', 'patch'], '/events/{event:slug}', [EventController::class, 'update']);
        Route::patch('/events/{event:slug}/registration', [EventController::class, 'updateRegistrationAvailability']);
        Route::get('/events/{event:slug}/registrations', [EventController::class, 'registrations']);
        Route::get('/events/{event:slug}/registrations/export', [EventController::class, 'registrationExport']);
        Route::get('/events/{event:slug}/invitations', [EventController::class, 'invitations']);
        Route::post('/events/{event:slug}/invitations', [EventController::class, 'sendInvitation']);
    });
});
