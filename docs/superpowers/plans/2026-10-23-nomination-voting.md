# Nomination-to-Voting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an admin nomination round that selects event attendees, gathers one vote per confirmed registration, broadcasts private live statistics, and creates an editable regular-voting draft from the top N nominees when closed.

**Architecture:** Extend the current voting subjects and votes rather than creating a second voting engine. Add event-scoped nominee search and nomination lifecycle APIs; keep the existing public vote route and admin results endpoint as the data source of truth. Use Laravel Broadcasting/Reverb and a private admin channel for live statistics, with Laravel Echo in Next.js; retain polling for ordinary voting leaderboards.

**Tech Stack:** Laravel 12, PHP 8.2+, MySQL, Sanctum SPA cookies, Laravel Reverb/Broadcasting, Next.js 16, React 19, Laravel Echo, `pusher-js`, Pest, Node test runner.

**Spec:** `event-api/docs/superpowers/specs/2026-10-23-nomination-voting-design.md`

## Global Constraints

- Preserve the existing manual voting-subject creation and lifecycle.
- A nomination round has a title, selected event attendees, and a top-N value; nominees are limited to 250.
- Candidate search includes every registration status, deduplicates attendee IDs, and returns only attendee ID and full name.
- Only confirmed registrations can vote, once per subject; preserve the current public voting route and response privacy.
- Only the event-owning admin may manage nominations, read stats, or subscribe to the event-scoped private stats channel.
- Closing requires at least one vote, includes all ties at the Nth-place cutoff, and atomically creates one same-title regular voting subject draft.
- Broadcast aggregate statistics only after the vote transaction commits. Broadcast/queue failure must not undo a saved vote.
- Use Laravel Reverb for hosted WebSockets when available; use the admin results endpoint for initial load and reconnect recovery.

## Review Focus

- A crafted attendee ID from another event or a repeated ID must be rejected; pin this to nomination request tests.
- Multiple registrations for one attendee and non-confirmed registration states must still produce one searchable nominee; pin this to nominee-search tests.
- A duplicate vote, a vote racing with close, or a close racing with another close must preserve one-vote and one-generated-draft invariants; pin these to voting/lifecycle feature tests.
- Private-channel authorization must reject unauthenticated users, scanners, and other admins; pin this to broadcast channel tests.
- A queue/broadcast outage must not change a successful vote response, while reconnect must restore the latest stats; pin this to broadcast and frontend subscription tests.

---

### Task 1: Add nomination metadata and attendee links to voting tables

**Files:**
- Create: `event-api/database/migrations/2026_10_23_000000_add_nomination_support_to_voting_tables.php`
- Modify: `event-api/app/Models/VotingSubject.php`
- Modify: `event-api/app/Models/VotingContestant.php`
- Modify: `event-api/app/Models/Event.php`
- Create: `event-api/tests/Feature/NominationPersistenceTest.php`

**Interfaces:**
- `VotingSubject.mode`: `manual` or `nomination`, defaulting existing and new ordinary subjects to `manual`.
- `VotingSubject.top_n`: nullable integer, populated only for nomination subjects.
- `VotingSubject.generated_from_subject_id`: nullable unique self-reference on a generated regular subject, pointing to its source nomination.
- `VotingContestant.attendee_id`: nullable attendee foreign key; manual contestants continue to use `name` only.
- Model relationships expose each nominee attendee and the source/generated subject without changing existing public voting relationships.

- [ ] **Step 1: Write failing persistence tests** for existing-subject `manual` defaults, nomination metadata, attendee-linked contestants, and unique generated-draft source linkage.
- [ ] **Step 2: Run `php artisan test tests/Feature/NominationPersistenceTest.php`** and confirm it fails because the columns/relationships are absent.
- [ ] **Step 3: Add the additive migration and model relationships/casts**; do not rewrite or remove the already-deployed voting migration.
- [ ] **Step 4: Re-run the persistence tests** and confirm existing manual subjects still load as `manual` and nomination relations work.
- [ ] **Step 5: Commit** as `feat: add nomination voting schema`.

### Task 2: Add event-scoped nominee search

**Files:**
- Create: `event-api/app/Http/Controllers/NominationController.php`
- Create: `event-api/app/Services/NomineeSearchService.php`
- Modify: `event-api/routes/api.php`
- Create: `event-api/tests/Feature/NomineeSearchTest.php`

**Interfaces:**
- `GET /api/events/{event:slug}/nomination-candidates?q={query}` is Admin-only and event-owner-only.
- Response: `{ "data": [{ "id": number, "full_name": string }] }`; no email, registration code, or registration data.
- Search first ranks exact/partial matches, then fuzzy name matches using normalized full-name Levenshtein distance; return at most 20 distinct attendee IDs, scoped through registrations for the requested event and independent of registration status.

- [ ] **Step 1: Write failing feature tests** for exact/partial hits, `Jeremaya Quintano` matching `Jeremiah Quintano`, distinct results for repeat registrations, inclusion of all statuses, empty results, owner authorization, and no cross-event or private-field leakage.
- [ ] **Step 2: Run `php artisan test tests/Feature/NomineeSearchTest.php`** and confirm the route is missing.
- [ ] **Step 3: Implement `NomineeSearchService::search(Event $event, string $query): Collection`** and the owner-authorized controller action; normalize a full name, query event registrations, deduplicate attendee IDs, rank exact/partial before fuzzy matches, and cap the response at 20.
- [ ] **Step 4: Re-run the search feature tests** and confirm all response and scoping assertions pass.
- [ ] **Step 5: Commit** as `feat: search event attendees for nominations`.

### Task 3: Create nominations and generate finalist drafts on close

**Files:**
- Create: `event-api/app/Http/Requests/StoreNominationRequest.php`
- Create: `event-api/app/Http/Requests/UpdateNominationRequest.php`
- Create: `event-api/app/Services/NominationService.php`
- Modify: `event-api/app/Http/Controllers/NominationController.php`
- Modify: `event-api/app/Http/Controllers/VotingSubjectController.php`
- Modify: `event-api/routes/api.php`
- Create: `event-api/tests/Feature/NominationManagementTest.php`

**Interfaces:**
- `POST /api/events/{event:slug}/nominations` accepts `{title, top_n, attendee_ids}` and creates a `draft` nomination subject with attendee-linked contestants.
- `PATCH /api/events/{event:slug}/nominations/{subject:slug}` edits only a draft nomination and replaces its selected nominees/top-N.
- `POST /api/events/{event:slug}/nominations/{subject:slug}/open` opens a draft nomination.
- `POST /api/events/{event:slug}/nominations/{subject:slug}/close` closes an open nomination and returns its generated draft under `data.generated_subject`.
- Generated subjects use `mode=manual`, the same title, finalist names in ranking order, `status=draft`, and the unique `generated_from_subject_id` link.

- [ ] **Step 1: Write failing feature tests** for create/edit validation, owner-only access, opening, confirmed-only one-vote eligibility for nomination subjects, closing with top-N ties, N greater than nominee count, zero-vote rejection, already-closed conflict, and exactly-one generated draft after repeated/concurrent close attempts.
- [ ] **Step 2: Run `php artisan test tests/Feature/NominationManagementTest.php`** and confirm the nomination endpoints are missing.
- [ ] **Step 3: Implement request validation and `NominationService` lifecycle methods**; validate every attendee belongs to the event through any registration status, require 2–250 distinct attendees and `top_n >= 2`, and allow edits only in draft state.
- [ ] **Step 4: Implement close in one transaction**: lock the nomination row, reject zero votes, rank counts descending with existing contestant order as tie-breaker, include everyone tied at the Nth-place cutoff, create the linked draft, then mark the nomination closed. Let the unique source link prevent duplicate generation on retries.
- [ ] **Step 5: Update the admin subject serializer** to include `mode`, `top_n`, and generated-draft linkage while leaving public voting lookup unchanged.
- [ ] **Step 6: Re-run nomination management tests and existing `VotingManagementTest.php`/`PublicVotingTest.php`**; confirm manual subject routes and public vote behavior remain unchanged.
- [ ] **Step 7: Commit** as `feat: manage nomination rounds and finalist drafts`.

### Task 4: Broadcast committed nomination statistics securely

**Files:**
- Create: `event-api/app/Services/VotingStatistics.php`
- Create: `event-api/app/Events/NominationStatisticsUpdated.php`
- Create: `event-api/routes/channels.php`
- Modify: `event-api/bootstrap/app.php`
- Modify: `event-api/app/Http/Controllers/VotingResultController.php`
- Modify: `event-api/app/Http/Controllers/PublicVotingController.php`
- Modify: `event-api/composer.json` and `event-api/composer.lock` (install Reverb)
- Create: `event-api/config/broadcasting.php` and `event-api/config/reverb.php` if generated by the Laravel installer
- Modify: `event-api/.env.example`
- Create: `event-api/tests/Feature/NominationBroadcastingTest.php`

**Interfaces:**
- `VotingStatistics::forSubject(VotingSubject $subject): array` provides the canonical admin result snapshot used by the results endpoint and broadcast payload.
- `NominationStatisticsUpdated` implements `ShouldBroadcast`, `ShouldDispatchAfterCommit`, and `ShouldRescue`; it broadcasts the same aggregate result shape as the admin results endpoint on `PrivateChannel('events.{eventId}.nominations.{subjectId}')` and uses `broadcastWhen()` to suppress delayed broadcasts after closure.
- The channel authorization callback allows only the owning admin for an event's active nomination subject.
- The existing results endpoint remains owner-only; public lookup and vote responses continue to omit totals.

- [ ] **Step 1: Write failing tests** for canonical count/rank/share payloads, broadcast dispatch after a saved nomination vote, no broadcast for manual subjects/failed votes, and channel authorization for owner, other admin, scanner, and guest.
- [ ] **Step 2: Run `php artisan test tests/Feature/NominationBroadcastingTest.php`** and confirm broadcasting support/channel are missing.
- [ ] **Step 3: Install/configure Laravel Reverb broadcasting** using Laravel's installer or equivalent generated configuration; register `routes/channels.php` through `withBroadcasting` with the API and `auth:sanctum` middleware. Keep credentials in environment configuration, never in source control.
- [ ] **Step 4: Extract result aggregation to `VotingStatistics::forSubject`** and update the existing owner-only results endpoint to use it without changing its response contract.
- [ ] **Step 5: Implement the private event and owner-only channel callback**; use the results endpoint's response shape, containing aggregate totals, nominee IDs/names/counts/ranks/shares, and participation values only. `broadcastWhen()` must verify the subject remains an active nomination so queued events are suppressed after close; implement `ShouldRescue` so broadcast failures cannot disrupt vote submission.
- [ ] **Step 6: Dispatch the event only for a successful vote on an active nomination, after the vote commits**; keep delivery queued so WebSocket work does not block the public vote request.
- [ ] **Step 7: Re-run broadcasting tests and existing voting tests**; verify a saved vote stays saved and successful if a queued broadcast later fails.
- [ ] **Step 8: Commit** as `feat: broadcast nomination statistics securely`.

### Task 5: Configure Laravel Echo in the Next.js client

**Files:**
- Modify: `event-management/package.json` and `event-management/package-lock.json`
- Create: `event-management/lib/echo.js`
- Create: `event-management/.env.example` if absent
- Create: `event-management/tests/echo-config.test.mjs`

**Interfaces:**
- `getEcho(): Echo | null` lazily creates one browser-only Echo/Reverb connection and returns `null` during server rendering or when public connection settings are absent.
- `getNominationChannelName(eventId: number, subjectId: number): string` returns `events.{eventId}.nominations.{subjectId}` for both subscription and tests.
- Private-channel authorization uses the existing `@/lib/api` Axios instance to POST to `/api/broadcasting/auth`, preserving Sanctum cookies, credentials, and XSRF behavior.
- Frontend public configuration uses `NEXT_PUBLIC_REVERB_APP_KEY`, `NEXT_PUBLIC_REVERB_HOST`, `NEXT_PUBLIC_REVERB_PORT`, and `NEXT_PUBLIC_REVERB_SCHEME`; no secret key is exposed to the browser.

- [ ] **Step 1: Write failing Node tests** for browser-safe missing-config behavior and the event-scoped channel naming helper.
- [ ] **Step 2: Run `node --test tests/echo-config.test.mjs`** and confirm the helper/config module is absent.
- [ ] **Step 3: Install `laravel-echo` and `pusher-js`** and implement a lazy Echo singleton with Reverb transport settings and Axios-based private-channel authorizer.
- [ ] **Step 4: Document placeholder public variables in `.env.example`** and add no real credentials.
- [ ] **Step 5: Re-run Node tests and lint `lib/echo.js`**; confirm importing the module during Next.js server rendering does not access `window`.
- [ ] **Step 6: Commit** as `feat: configure Echo client for Reverb`.

### Task 6: Add nomination creation and management to the admin voting page

**Files:**
- Create: `event-management/components/voting/nomination-form.js`
- Create: `event-management/lib/nomination-voting.mjs`
- Modify: `event-management/components/voting/voting-subject-card.js`
- Modify: `event-management/app/events/[id]/voting/page.js`
- Create: `event-management/tests/nomination-voting.test.mjs`

**Interfaces:**
- `validateNomination(input): errors` validates title, `top_n >= 2`, and 2–250 unique selected attendee IDs.
- `toNominationPayload(input)` returns `{title, top_n, attendee_ids}`.
- The page keeps **Create voting subject** untouched and adds **Create nomination** as a separate workflow.
- Candidate selection renders names only, supports fuzzy matches as selectable rows, and prevents selecting the same attendee twice.

- [ ] **Step 1: Write failing frontend helper tests** for required fields, duplicate IDs, 2/250 nominee bounds, and nomination payload shape.
- [ ] **Step 2: Run `node --test tests/nomination-voting.test.mjs`** and confirm the helpers are missing.
- [ ] **Step 3: Implement nomination form/helper** with title, top-N, debounced event-scoped search, selected-name list, remove action, loading/no-match states, and draft editing.
- [ ] **Step 4: Add distinct create/edit nomination actions and nomination status controls to the page/card**; leave the existing manual form route and payload unchanged. Closing refreshes the subject list so the generated regular draft appears immediately.
- [ ] **Step 5: Re-run helper tests and ESLint for the touched page/components**; manually verify the user can create, open, close, and edit a draft nomination without affecting manual voting subjects.
- [ ] **Step 6: Commit** as `feat: add nomination management UI`.

### Task 7: Replace nomination leaderboard polling with live broadcasts

**Files:**
- Modify: `event-management/app/events/[id]/voting/[subject]/leaderboard/page.js`
- Modify: `event-management/lib/voting-leaderboard.mjs`
- Modify: `event-management/tests/voting-leaderboard.test.mjs`
- Modify: `event-management/tests/echo-config.test.mjs`

**Interfaces:**
- The leaderboard reads `subject.mode`; active nomination subjects subscribe to `events.{eventId}.nominations.{subjectId}` and update the SWR cache from aggregate broadcast data.
- Manual voting subjects keep the current three-second polling behavior.
- On nomination-channel reconnect, the frontend re-fetches the owner-only results endpoint. It leaves the channel on close, subject change, or unmount.

- [ ] **Step 1: Add failing helper/subscription tests** proving active manual subjects poll at 3000 ms, active nominations do not poll, draft/closed/hidden subjects do not poll, a broadcast snapshot updates the leaderboard, reconnect re-fetches REST results, and close/unmount unsubscribes.
- [ ] **Step 2: Run `node --test tests/voting-leaderboard.test.mjs`** and confirm nomination mode is not handled.
- [ ] **Step 3: Update the leaderboard refresh helper and subscribe lifecycle**; use the existing REST result payload as initial data, apply validated broadcast snapshots to SWR, re-fetch after reconnect, and unsubscribe on every lifecycle exit.
- [ ] **Step 4: Show a clear connection status and manual refresh action when Reverb configuration is absent or the nomination channel is disconnected**; do not silently imply that live statistics are current.
- [ ] **Step 5: Re-run leaderboard/Echo Node tests and ESLint for the page**; verify ordinary leaderboard refresh remains unchanged and nomination stats update without interval polling.
- [ ] **Step 6: Commit** as `feat: stream nomination leaderboard updates`.

### Task 8: Run cross-feature regression and deployment checks

**Files:**
- Modify only if needed: tests and environment examples created above.
- Create: `event-api/docs/nomination-broadcasting-deployment.md`

- [ ] **Step 1: Run the complete Laravel suite:** `php artisan test`.
- [ ] **Step 2: Run the frontend tests:** `node --test` from `event-management`.
- [ ] **Step 3: Run frontend lint and production build:** `npm run lint` and `npm run build` from `event-management`.
- [ ] **Step 4: Run `git diff --check` in both repositories** and verify no `.env` credentials were staged.
- [ ] **Step 5: Document deployment setup:** provision/attach a Laravel Cloud Reverb WebSocket cluster, set backend and public frontend Reverb variables in their respective environments, and run an always-on queue worker for the default broadcast queue. Never include real credentials in the document.
- [ ] **Step 6: Commit the deployment guide** as `docs: document nomination broadcasting deployment`.

