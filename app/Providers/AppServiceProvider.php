<?php

namespace App\Providers;

use App\Models\Event;
use App\Models\NominationTopic;
use App\Models\VotingSubject;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        RateLimiter::for('public-voting-lookup', function (Request $request): Limit {
            return Limit::perMinute(60)->by($this->votingThrottleKey([
                $this->votingSubjectSlug($request), $request->ip(),
            ]));
        });

        RateLimiter::for('public-voting-submission', function (Request $request): Limit {
            // Apply this shared venue budget before any eligibility lookup.
            return Limit::perMinute(120)->by($this->votingThrottleKey([
                $this->votingSubjectSlug($request), $request->ip(),
            ]));
        });

        RateLimiter::for('public-voting-registration', function (Request $request): Limit {
            $slug = $this->votingSubjectSlug($request);
            $code = $request->input('registration_code');
            $normalizedCode = is_string($code) ? Str::upper(trim($code)) : null;
            $registration = is_string($code) && mb_strlen($code) <= 255
                ? DB::table('registrations')
                    ->join('voting_subjects', 'voting_subjects.event_id', '=', 'registrations.event_id')
                    ->where('voting_subjects.slug', $slug)
                    ->where('voting_subjects.status', VotingSubject::STATUS_ACTIVE)
                    ->where('registrations.status', 'confirmed')
                    ->where('registrations.registration_code', $normalizedCode)
                    ->first(['registrations.id', 'voting_subjects.id as subject_id'])
                : null;

            // Each normalized candidate gets the same fine limit, including
            // unknown codes, so a lower invalid-only threshold cannot leak eligibility.
            $identity = $registration
                ? ['registration', $registration->subject_id, $registration->id]
                : ['code', $slug, $normalizedCode];

            return Limit::perMinute(10)->by($this->votingThrottleKey($identity));
        });

        RateLimiter::for('public-nomination-lookup', function (Request $request): Limit {
            return Limit::perMinute(60)->by($this->votingThrottleKey([
                'nomination-lookup', $this->votingSubjectSlug($request), $request->ip(),
            ]));
        });

        RateLimiter::for('public-nomination-submission', function (Request $request): Limit {
            return Limit::perMinute(120)->by($this->votingThrottleKey([
                'nomination-submit', $this->votingSubjectSlug($request), $request->ip(),
            ]));
        });

        RateLimiter::for('public-nomination-registration', function (Request $request): Limit {
            $slug = $this->votingSubjectSlug($request);
            $code = $request->input('registration_code');
            $normalizedCode = is_string($code) ? Str::upper(trim($code)) : null;
            $registration = is_string($code) && mb_strlen($code) <= 255
                ? DB::table('registrations')
                    ->join('voting_subjects', 'voting_subjects.event_id', '=', 'registrations.event_id')
                    ->where('voting_subjects.slug', $slug)
                    ->where('voting_subjects.mode', 'nomination')
                    ->where('voting_subjects.status', VotingSubject::STATUS_ACTIVE)
                    ->where('registrations.status', 'confirmed')
                    ->where('registrations.registration_code', $normalizedCode)
                    ->first(['registrations.id', 'voting_subjects.id as subject_id'])
                : null;

            $identity = $registration
                ? ['nomination-registration', $registration->subject_id, $registration->id]
                : ['nomination-code', $slug, $normalizedCode];

            return Limit::perMinute(10)->by($this->votingThrottleKey($identity));
        });

        RateLimiter::for('public-topic-submission', function (Request $request): Limit {
            return Limit::perMinute(120)->by($this->votingThrottleKey([
                ...$this->topicThrottleScope($request), $request->ip(),
            ]));
        });

        RateLimiter::for('public-topic-registration', function (Request $request): Limit {
            [$phase, $topicId] = $this->topicThrottleScope($request);
            $event = $request->route('event');
            $code = $request->input('registration_code');
            $normalizedCode = is_string($code) && mb_strlen($code) <= 255 ? Str::upper(trim($code)) : null;
            $stage = $phase === 'public-topic.nominations'
                ? NominationTopic::STAGE_NOMINATION_ACTIVE
                : NominationTopic::STAGE_VOTING_ACTIVE;
            $registrationId = $normalizedCode !== null && $normalizedCode !== ''
                ? DB::table('registrations')
                    ->join('nomination_topics', 'nomination_topics.event_id', '=', 'registrations.event_id')
                    ->join('events', 'events.id', '=', 'registrations.event_id')
                    ->where('nomination_topics.id', $topicId)
                    ->where('nomination_topics.stage', $stage)
                    ->where('events.slug', $event instanceof Event ? $event->slug : (string) $event)
                    ->whereNull('events.deleted_at')
                    ->where('registrations.status', 'confirmed')
                    ->where('registrations.registration_code', $normalizedCode)
                    ->value('registrations.id')
                : null;

            // Database-equivalent spellings of a valid code share a bucket.
            $identity = $registrationId !== null
                ? ['registration', $registrationId]
                : ['code', $normalizedCode];

            return Limit::perMinute(10)->by($this->votingThrottleKey([$phase, $topicId, ...$identity]));
        });
    }

    private function topicThrottleScope(Request $request): array
    {
        $topic = $request->route('topic');

        return [
            $request->route()->getName(),
            $topic instanceof NominationTopic ? (string) $topic->id : (ltrim((string) $topic, '0') ?: '0'),
        ];
    }

    private function votingSubjectSlug(Request $request): string
    {
        $subject = $request->route('subject');

        return Str::lower($subject instanceof VotingSubject ? $subject->slug : (string) $subject);
    }

    private function votingThrottleKey(array $identity): string
    {
        // Keep registration identities and IP addresses out of cache keys.
        return hash_hmac('sha256', json_encode($identity), (string) config('app.key'));
    }
}
