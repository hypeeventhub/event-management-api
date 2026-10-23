# Nomination-to-Voting Design

## Goal

Add a second voting creation workflow that lets event admins run a nomination round from registered attendees, see private live results, and automatically prepare a regular voting subject draft from the top N nominees when the nomination round closes. Preserve the existing manual voting-subject creation workflow.

## Confirmed behavior

- The event voting page keeps **Create voting subject** and adds a distinct **Create nomination** workflow.
- A nomination round has only a subject title and configurable top-N count in the admin create/edit form. Creating it does not select or prepopulate nominees.
- Once created, the nomination has a public QR/link. Attendees open it, fuzzy-search registered attendees for the same event (any registration status; duplicate attendee records are shown once), select a full-name result, and submit their own registration code for verification. Public results contain only attendee ID and full name; fuzzy matches appear as selectable names without a "You mean" prefix.
- A nomination submission requires a confirmed registration code for the same event. Each confirmed registration can submit once per nomination subject. The selected attendee becomes a nominee the first time they are selected; later submissions for that attendee reuse the same nominee.
- Nominees are collected dynamically; there is no fixed nominee cap. The compact realtime payload omits names and is verified against a realistic 2,000-nominee / 2,000-registration event.
- Admins manually open and close nomination rounds.
- Live nomination statistics are admin-only and update through Laravel event broadcasting whenever a valid nomination vote is recorded while the round is open. They include total votes and each nominee's count, rank, and share of votes. On close, an after-commit private close notice reaches already-subscribed owners; the leaderboard re-fetches authoritative REST results, then leaves the channel when it confirms the round is closed. New subscriptions to closed subjects remain unauthorized. Public voters do not see live totals.
- On close, the system calculates the ranking and creates a regular voting subject **as a draft**, using the same title. Every nominee tied at the Nth-place cutoff is included, so the generated subject may contain more than N nominees.
- The generated draft is linked to its source nomination and is created only once. An admin can review/edit it and explicitly activate it using the existing voting-subject workflow.

## Recommended architecture

Extend the existing `VotingSubject` domain with an explicit subject mode (`manual` or `nomination`) rather than introducing a separate vote system. Admins create nominations with only title and `top_n`. Public submissions resolve the selected registered attendee into one linked `VotingContestant`, then persist the nominator's choice as a `VotingVote`. Keep manual ballots on the existing public voting API; nomination lookup/search/submission use separate public routes. Reuse the existing one-vote-per-registration constraint and results query. Nomination contestants retain a nullable attendee reference; ordinary manually entered contestants remain supported without one. A unique `(voting_subject_id, attendee_id)` index prevents duplicate nominee rows while allowing null attendee references on manual contestants.

Store the requested finalist count and a unique link from the nomination round to the generated voting subject. Closing a nomination round must lock and update the round, calculate the tally, and create the linked draft in one database transaction. The unique source link prevents duplicate final drafts if a close request is retried. The generated subject uses the existing `draft` status and remains editable until activated.

Attendee search is a public, nomination-slug-scoped API operation available only while the nomination is active. It joins registrations to attendees, considers every registration status, deduplicates attendee IDs, applies exact/partial/fuzzy name matching server-side, and returns only IDs and full names. It must not expose email, registration code, or other attendee details. Search must not return attendees from other events. Public subject lookup returns only the nomination title and lifecycle status so the QR destination can explain draft/closed state without exposing its roster or statistics.

The existing admin results endpoint remains the source for the initial statistics snapshot and for resynchronizing after a WebSocket reconnect or a close notice. Use Laravel Broadcasting with an event-scoped private channel authorized only for the event-owning admin. After a valid nomination vote is committed, broadcast the updated aggregate statistics (total votes and each nominee's count, rank, and share). Broadcast after the database transaction commits so clients never receive counts for an uncommitted vote. On successful close, queue a separate `NominationClosed` private notice after commit; rescue delivery failures so they cannot fail the close transaction. Existing owner subscriptions receive the notice, while channel authorization continues to reject new subscriptions once closed. Keep the public voting response free of result counts. The frontend subscribes only while an admin is viewing an open nomination round, recovers through REST after a close notice or reconnect, and unsubscribes after REST confirms closure or the page is left. Configure a running queue worker for queued broadcasts; broadcast delivery failure must not undo or fail a saved vote or close.

The Reverb transport event is intentionally smaller than the REST results response: it contains `event_id`, `subject_id`, active `status`, aggregate vote and confirmed-registration totals, and a positional `contestant_votes` array ordered by `display_order` then contestant ID. It does not contain names or other roster fields. The admin client merges this vector with the stable roster from the REST response and derives the canonical ordering, ranks, and percentages locally. If the roster is missing or does not align with the vector/totals, the client revalidates through REST. A realistic 2,000-nominee/2,000-registration vector is tested below Reverb's default 10,000-byte message limit without imposing a mid-round nominee cap or changing the owner-only REST results contract.

## User flows

### Create and run nominations

1. Event admin chooses **Create nomination** without changing the existing **Create voting subject** flow.
2. Admin enters only a nomination title and top-N count. The system saves an empty draft and shows its QR/link.
3. Admin manually opens the nomination when ready.
4. An attendee scans the QR, searches for an event registrant by name, selects the intended person, and enters their own registration code. The API verifies the code belongs to a confirmed registration for this event and records one nomination for that registration.
5. Admin sees private live statistics update through broadcasting while the nomination is open and manually closes the round.
6. Closing ranks votes, includes all nominees tied at the Nth-place cutoff, and creates one same-title regular voting-subject draft.
7. Admin reviews or edits the generated draft and activates it when ready. Attendees may then vote in this separate subject under the existing one-vote-per-subject rule.

### Search for a nominee

1. An attendee enters a name, including a misspelling such as "Jeremaya Quintano."
2. Search returns exact, partial, and fuzzy matching registered attendees for only the current event.
3. A fuzzy result is presented as a selectable full-name row such as "Jeremiah Quintano"; no explanatory prefix or other attendee data is shown.
4. The attendee selects the intended person and submits their own registration code. The code must be confirmed and belong to this event; the attendee can submit only once.
5. The selected person is added once as a nominee and receives the submitted nomination vote.

## Ranking and closure rules

- Sort nominees by vote count descending.
- If multiple nominees share the Nth-place vote total, include all tied nominees.
- If N exceeds the nominee count, all nominees advance.
- If the round has no votes, closing is rejected and the round stays open; no final draft is created.
- Closing and draft generation are atomic. If generation cannot complete, the round must not be left closed without its linked draft.
- A closed nomination cannot generate another draft.
- The final voting subject uses the same title and normal `draft` behavior. Existing activation validation remains authoritative.

## Security and privacy

- Only the owning admin may create, search, view results for, open, or close an event's nomination round, following the existing event-owner checks.
- Only the owning admin may subscribe to the event-scoped private statistics channel. Broadcast payloads contain aggregate vote statistics only and must never be sent on a public channel.
- Nominee search results reveal only attendee ID internally for selection and full name for display; no email or registration code is returned.
- Public vote lookup continues exposing only the active subject title and ordered nominee identifiers/names; live tally data stays admin-only.
- Voters must have a confirmed registration for the same event. Existing database uniqueness and subject locks continue preventing duplicate votes and race-condition votes after closure.

## Validation and failure behavior

- Require a non-empty title and a top-N value of at least two. Opening an empty nomination is allowed because nominees are gathered after the QR is published.
- Do not impose a nominee cap; the compact broadcast is tested for the expected 2,000-attendee event size.
- Admins can edit only title and top-N while the nomination is draft; nominees are attendee-submitted after opening.
- A valid nominee must have a registration for the same event. The submitting registration code must be confirmed and same-event; reject unknown, pending, cancelled, or other-event codes.
- If a nomination has no votes when the admin tries to close it, keep it open and return a clear conflict; do not create an empty-result final draft.
- Return a clear conflict if a nomination is already closed or otherwise cannot be closed.
- Search may return no results; the UI should show a concise no-matches state and allow another query.

## Testing expectations

- Preserve existing manual subject creation, edit, activate, close, and public voting tests.
- Verify admin creation/edit accepts only title and top-N and never preselects nominees; a QR/link is available for the created subject.
- Verify public lookup exposes only title/status; nominee search is active-only, event-scoped, all-status, deduplicated, fuzzy, and name-only.
- Verify public nomination submission validates a confirmed same-event registration code, accepts only attendees registered to that event, allows one submission per registration, and reuses one finalist row per attendee.
- Verify nomination creation and manual open/close transitions, including opening with no nominees.
- Verify live stats include counts/rank/share, are broadcast only after a committed vote, and are unavailable from public APIs.
- Verify private-channel authorization denies non-owner and unauthenticated subscribers, the frontend receives updates for an open round, unsubscribes when it closes, and reloads the authoritative snapshot after reconnecting.
- Verify a broadcast/queue failure does not fail or roll back an otherwise successful nomination vote.
- Verify top-N ordering, cutoff ties, N greater than the nominee count, zero-vote close behavior, and exactly-one generated same-title draft.
- Verify close plus generated-draft creation is transactional under a failed creation and safe against concurrent/retried close requests.
- Verify generated drafts can be reviewed/edited and activated through the existing workflow.

## Assumptions to review

- N is at least two because the follow-on public ballot requires at least two contestants.
- A confirmed registration may vote in both the nomination round and the generated regular voting subject, since one vote is enforced per subject.
- Fuzzy matching is a server-side name-search feature, not AI-generated matching and not an automatic merge; an admin explicitly selects the result.
- Laravel Broadcasting is used for near-real-time admin statistics instead of a fixed three-second polling interval. Laravel Reverb is the intended WebSocket broadcaster; use a managed Laravel Cloud Reverb cluster in hosted environments when available. The admin results endpoint remains the authoritative snapshot for initial load and reconnect recovery.
