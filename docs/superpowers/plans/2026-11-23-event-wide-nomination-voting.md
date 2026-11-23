# Event-Wide Nomination and Voting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an event admin run ordered topics with Female and Male nomination/voting rounds from one event QR, and inspect all standings from one tabbed leaderboard.

**Architecture:** Add event-scoped nomination topics and link each topic to two categorized `VotingSubject` nomination rows and their generated voting rows. Reuse existing registration-code validation, checked-in candidate search, vote uniqueness, result endpoints, and Reverb subject channels; add a public event poll route and an event leaderboard page. Preserve manual voting subjects and legacy subject links.

**Tech Stack:** Laravel 12, Eloquent, MySQL migrations, Next.js App Router, React, SWR, Laravel Echo/Reverb, existing `qrcode` package.

**Spec:** `event-api/docs/superpowers/specs/2026-11-23-event-wide-nomination-voting-design.md`

## Global Constraints

- “Gender is not restored to registration or stored on attendees.”
- “For each topic, one nomination submission requires both a Female nominee and a Male nominee.”
- “For each topic's voting stage, one vote submission requires one Female finalist and one Male finalist.”
- “Reject an incomplete Female/Male pair before writing any nomination or vote; persist both category records atomically under the same registration.”
- “One event QR/link is shared across the event's nomination and voting subjects; it does not change when a topic changes phase.”
- “Closing a topic's nominations creates and activates the corresponding Female and Male finalist ballots.”
- “Preserve the existing manual Create voting subject workflow.”
- “After both voting rounds for a topic are closed, the next topic can be opened by the admin.”
- “Existing subject-specific API routes and already-shared links compatible for existing subjects.”

## Review Focus

- One category has fewer than two nominated finalists: closing must fail without closing either category or creating only one ballot.
- An attendee submits one category choice but omits the other: reject the pair and write neither side.
- One vote insert fails after the other would have succeeded: transaction rollback leaves neither category vote recorded.
- Two concurrent topic transitions: at most one topic may be in an open nomination/voting phase.
- A duplicate/retried topic close: each category must generate exactly one voting subject.
- The same attendee is nominated in either or both categories: category is only an award label, and every search still includes all checked-in attendees.
- Manual and pre-existing legacy subjects: they remain manageable and accessible through old URLs and the new event poll/leaderboard.

---

### Task 1: Topic storage and transactional round lifecycle

**Files:**
- Create: `event-api/database/migrations/2026_11_23_000000_create_nomination_topics_table.php`
- Create: `event-api/app/Models/NominationTopic.php`
- Create: `event-api/app/Http/Requests/StoreNominationTopicsRequest.php`
- Create: `event-api/app/Http/Controllers/NominationTopicController.php`
- Create: `event-api/app/Http/Controllers/PublicTopicSubmissionController.php`
- Create: `event-api/app/Http/Requests/SubmitTopicNominationsRequest.php`
- Create: `event-api/app/Http/Requests/SubmitTopicVotesRequest.php`
- Modify: `event-api/app/Models/Event.php`
- Modify: `event-api/app/Models/VotingSubject.php`
- Modify: `event-api/app/Services/NominationService.php`
- Modify: `event-api/app/Http/Controllers/VotingSubjectController.php`
- Modify: `event-api/app/Http/Resources/VotingSubjectResource.php`
- Modify: `event-api/routes/api.php`

**Interfaces:**
- `NominationTopic` stores `event_id`, `title`, `position`, `female_top_n`, `male_top_n`, and stage `draft|nomination_active|voting_active|closed`.
- `VotingSubject` gains nullable `nomination_topic_id` and nullable `category` (`female|male`); legacy/manual records remain null.
- `GET /api/events/{event}/nomination-topics` returns ordered topics with both category rounds and their generated ballots.
- `POST /api/events/{event}/nomination-topics` accepts `{ topics: [{ title, female_top_n, male_top_n }] }`, validates 1–30 unique titles and finalist counts >= 2, and atomically creates both draft nomination rounds per topic.
- `POST /api/events/{event}/nomination-topics/{topic}/open` opens both nomination rounds only when no other topic is active.
- `POST /api/events/{event}/nomination-topics/{topic}/close-nominations` atomically closes both rounds, ranks each category with cutoff ties, creates one active voting round per category, and marks the topic `voting_active`.
- `POST /api/public/events/{event:slug}/topics/{topic}/nominations` accepts `{ registration_code, female_attendee_id, male_attendee_id }`; it validates both checked-in nominees and atomically records both nomination votes.
- `POST /api/public/events/{event:slug}/topics/{topic}/votes` accepts `{ registration_code, female_contestant_id, male_contestant_id }`; it validates both active finalists and atomically records both votes.
- A confirmed registration may submit one complete nomination pair and one complete voting pair per topic. Per-round database uniqueness remains the final duplicate guard.
- Closing a generated voting subject through the existing endpoint marks the topic `closed` only after both category ballots are closed.
- Existing single-subject nomination endpoints and the manual voting endpoints keep their current request/response contracts.

- [ ] Add the forward migration and model relations; keep new voting-subject columns nullable for old records.
- [ ] Implement authenticated topic listing and batch creation with server validation and event-owner authorization.
- [ ] Implement locked topic open/close transitions; reject an empty category or fewer than two distinct nominees without partial updates.
- [ ] Implement paired nomination and voting requests; validate all fields, registration/check-in state, topic stage, category round, and candidate membership before inserting either record.
- [ ] Save both sides in one transaction, dispatch both existing subject-stat broadcasts after commit, and return a conflict if either per-subject uniqueness check fails.
- [ ] Generate/activate both category ballots in the same transaction as nomination close; retain one generated ballot per nomination round on retries.
- [ ] Complete the topic only after both generated ballots are closed; leave manual and legacy subject behavior unchanged.
- [ ] Check PHP syntax and inspect migration/route diffs.

### Task 2: Shared event poll endpoint and attendee page

**Files:**
- Create: `event-api/app/Http/Controllers/PublicEventPollController.php`
- Modify: `event-api/routes/api.php`
- Create: `event-management/app/event-poll/[slug]/page.js`
- Create: `event-management/components/voting/public-event-poll.js`
- Create: `event-management/components/voting/public-topic-nomination-form.js`
- Create: `event-management/components/voting/public-topic-vote-form.js`
- Create: `event-management/lib/event-poll.mjs`

**Interfaces:**
- `GET /api/public/events/{event:slug}/poll` returns only the event title, current topic/stage, active Female/Male subject slugs, active manual ballots, and active legacy standalone nominations. It returns no attendee lists or results.
- Grouped nominations/votes are submitted as a pair to the topic endpoints from Task 1; existing subject-level forms remain for manual ballots and legacy standalone nominations.
- `loadPublicEventPoll(eventSlug)` returns the validated public poll shape; the Next.js route is `/event-poll/[slug]`.
- The public page shows two required fields for each grouped topic stage: nominee search for Female and Male during nomination, or finalist selection for Female and Male during voting. Both are submitted together with one registration code; active manual ballots and legacy nominations remain selectable through their existing forms.
- Nominee candidate search remains the existing subject-scoped endpoint and searches all checked-in event attendees, without gender filtering.

- [ ] Add a throttled, unauthenticated event poll lookup with only active-round metadata and public-safe event information.
- [ ] Add a shared `/event-poll/[slug]` page that shows the active topic, both required Female/Male fields, nomination/voting state, active manual voting ballots, and active standalone legacy nominations.
- [ ] Implement paired topic nomination and voting forms with one registration-code field, two required category choices, retry-safe validation errors, and success only after the atomic pair endpoint succeeds.
- [ ] Add clear waiting/closed/empty states when no topic or manual ballot is currently active.
- [ ] Run ESLint on changed public-page files and check PHP syntax and whitespace.

### Task 3: Admin topic workflow and single event QR

**Files:**
- Create: `event-management/components/voting/nomination-topics-form.js`
- Create: `event-management/components/voting/nomination-topic-card.js`
- Create: `event-management/components/voting/event-poll-qr-dialog.js`
- Modify: `event-management/app/events/[id]/voting/page.js`
- Modify: `event-management/components/voting/voting-subject-card.js`
- Modify: `event-management/lib/nomination-voting.mjs`
- Modify: `event-management/lib/voting-subjects.mjs`

**Interfaces:**
- The admin can create one batch containing ordered topic titles and separate Female/Male finalist counts.
- Topic cards show nomination/voting status and grouped stats, with **Open nominations**, **Close nominations**, and per-category **Close voting** controls appropriate to the current stage.
- A single QR dialog encodes `/{event-poll}/{eventSlug}` and appears once for the event. Per-subject QR actions are hidden for the grouped workflow; legacy API URLs continue working.
- The manual **Create voting subject** form and its lifecycle remain available.

- [ ] Add a dynamic batch form for topic title and Male/Female finalist counts, with client-side validation matching the API.
- [ ] Render ordered topic cards with category status/counts and stage actions that call the topic lifecycle endpoints.
- [ ] Replace per-subject QR controls in the admin voting view with one event QR/link dialog.
- [ ] Keep the existing manual subject creation, edit, activation, close, and display flows intact.
- [ ] Run ESLint on changed voting-management components and inspect the rendered responsive states.

### Task 4: Event-wide tabbed leaderboard

**Files:**
- Create: `event-management/app/events/[id]/voting/leaderboard/page.js`
- Create: `event-management/components/voting/event-leaderboard.js`
- Modify: `event-management/app/events/[id]/voting/[subject]/leaderboard/page.js`
- Reuse: `event-management/components/voting/leaderboard-row.js`
- Reuse: `event-management/lib/voting-leaderboard.mjs`
- Reuse: existing `/results` and subject-level private Reverb channels.

**Interfaces:**
- The event leaderboard fetches topic metadata, then loads the selected subject's authoritative result snapshot from the existing `/voting-subjects/{subject}/results` endpoint.
- Main tabs represent nomination topics; each topic has Female/Male tabs. Each selection displays its current/latest nomination or voting standings. Manual subjects appear as their own main tabs.
- The selected active round subscribes to its existing private Reverb channel; switching tabs unsubscribes from the old round and subscribes to the new one. REST remains the initial/reconnect source.
- Existing subject-specific leaderboard URLs continue to render.

- [ ] Build the event-level tab UI from topic and subject metadata, preserving the existing raffle palette and stage-readable layout.
- [ ] Load standings through existing owner-only result endpoints and render with `LeaderboardRow`.
- [ ] Reuse the existing broadcast projection/reconnect utilities for the selected nomination or voting subject and clean up subscriptions on tab change.
- [ ] Link the admin voting page to the event leaderboard while retaining subject-specific links.
- [ ] Run ESLint on changed leaderboard files and review tab keyboard/accessibility and narrow-screen layout.

### Task 5: Integrated behavior review

- [ ] Apply the new migration against the existing schema and verify old manual/nomination records remain readable.
- [ ] Manually review a multi-topic flow: open Best Outfit nominations, nominate in both categories, close nominations, vote in both categories, close both votes, then open Star of the Night.
- [ ] Verify the same event QR URL remains unchanged throughout, and the shared page presents only active rounds plus active manual ballots.
- [ ] Verify both nomination searches include all checked-in attendees and the topic submission rejects an unchecked-in nominee or invalid registration code without saving either side.
- [ ] Verify voting requires both finalists and an invalid or duplicate side leaves neither vote recorded.
- [ ] Verify one leaderboard page switches topic/category tabs and receives live updates for the selected active subject.
- [ ] Verify previous `/nomination/{slug}` and `/vote/{slug}` links and the manual Create voting subject workflow remain functional.
