# Event-Wide Nomination and Voting Design

## Goal

Run multiple nomination topics for one event, with separate Female and Male rounds under each topic, through one stable event QR/link. After nominations close for a topic, its Female and Male finalist ballots appear under that same link. Show all topic results on one leaderboard page with tabs.

## Confirmed behavior

- One event QR/link is shared across the event's nomination and voting subjects; it does not change when a topic changes phase.
- Admin creates a nomination set by entering multiple topic titles, such as “Best Outfit” and “Star of the Night.” Each topic has a Female and a Male round.
- Admin sets a finalist count independently for each gender round of each topic.
- For each topic, one nomination submission requires both a Female nominee and a Male nominee. The attendee enters their registration code once and fuzzy-searches all checked-in attendees for both choices. Gender is not restored to registration or stored on attendees, so the category does not verify the nominee's gender.
- For each topic's voting stage, one vote submission requires one Female finalist and one Male finalist. The attendee enters their registration code once; both votes are saved together or neither is saved.
- Admin opens and closes nomination rounds by topic. Closing nominations creates and activates the corresponding Female and Male finalist ballots; the shared event QR then presents those voting rounds.
- Each grouped submission is verified once against a confirmed, checked-in event registration; the backend records one nomination/vote for each category subject under that registration.
- After both voting rounds for a topic are closed, the next topic can be opened by the admin and follows the same nomination-to-voting sequence.
- Preserve the existing manual **Create voting subject** workflow. Active manual voting subjects are also available from the shared event QR.
- Active pre-existing nominations not linked to a topic are also available from the shared event QR as standalone rounds.
- Replace subject-specific leaderboard navigation for the new grouped workflow with one event leaderboard page. Main tabs represent topics; each topic has Female and Male result tabs. Manual voting subjects appear as their own main tabs.
- Keep current subject-specific API routes and already-shared links compatible for existing subjects. The new grouped workflow and admin UI use only the shared event QR.

## Approaches considered

1. **Event campaign with grouped topics and gender rounds (selected):** Store topics explicitly and link each Female/Male nomination and generated voting round to its topic. This represents the admin workflow and leaderboard directly, while leaving attendee gender data out.
2. **Independent subjects with title conventions:** Create separate subjects named “Best Outfit — Female” and “Best Outfit — Male” and infer grouping from title text. This avoids a schema change but makes lifecycle transitions, QR discovery, and leaderboard grouping fragile.
3. **Attendee gender filtering:** Restore gender in registration and attendee storage, then search only matching attendees. This was rejected because the user explicitly wants searches across all checked-in attendees and does not want gender restored.

## Architecture

Add event-scoped ordered nomination topics. Each topic owns exactly two nomination rounds, categorized Female and Male. Reuse `VotingSubject`, `VotingContestant`, and `VotingVote` for the round data, nominations, generated finalists, manual voting, and vote uniqueness; add nullable topic/category links for grouped rounds. Add a public event-level lookup endpoint and Next.js page that shows the current topic and both categories for its active phase, plus active manual ballots and active standalone legacy nominations. The QR code encodes this event-level URL. Add grouped submission endpoints that validate both selected IDs and insert both per-category records inside one database transaction.

Closing a topic's nominations must lock and transition both category rounds atomically, calculate each category's top N including cutoff ties, and create/activate the two corresponding voting rounds. A topic is complete after both voting rounds close. The admin page controls topic progression. The leaderboard page queries all topic/category result data through authenticated APIs and reuses existing result broadcasting for the selected round.

## User flows

### Admin

1. Select **Create nomination set** and add one or more topic titles.
2. For each topic, enter the Female finalist count and Male finalist count.
3. Display one event QR/link for the entire event.
4. Open nominations for the first topic. Close them when ready; the paired Female and Male voting rounds are generated and become active.
5. Close both voting rounds, then open nominations for the next topic.
6. Use the event leaderboard page and switch topic and gender tabs.

### Attendee

1. Scan the event QR/link and see the current topic.
2. During nominations, enter their registration code, search among all checked-in event attendees in two required fields, select one Female nominee and one Male nominee, and submit both choices together.
3. During voting, enter their registration code, select one Female finalist and one Male finalist in two required fields, and submit both votes together.
4. Use the same QR/link when the event advances to the next topic.

## Constraints and validation

- Require at least one topic, unique non-empty topic titles within the set, and a finalist count of at least two for each category.
- Limit the set to a reasonable number of topics to keep public and admin pages responsive; choose a concrete limit during implementation and validate it server-side.
- Nominee search remains event-scoped, fuzzy, and restricted to checked-in attendees. It does not filter on attendee gender.
- A nomination category is an award category label only. Search includes all checked-in attendees, and nominee gender is not verified.
- Reject an incomplete Female/Male pair before writing any nomination or vote; persist both category records atomically under the same registration.
- Topic transition and creation of both voting ballots must be transactional and safe against retries or concurrent close requests.
- Public lookup must not expose attendee lists, vote totals, or admin-only nomination statistics.
- All public nominations and votes retain existing registration-code verification, check-in requirements, per-round uniqueness, throttling, and broadcast privacy.
- Existing manual subjects and data created before this change remain usable from both their current links and the shared event poll where active.

## Out of scope

- Adding gender to attendee profiles or filtering search by gender.
- Automatically selecting or validating nominee gender.
- Automatically starting the next topic; an admin opens each topic's nominations.
- Removing the existing manual voting-subject workflow.

## Review decisions

- A single event-level QR/link remains stable across every topic and phase.
- Each nomination/voting submission includes one required Female choice and one required Male choice; neither is inferred from attendee data.
- Finalist counts are entered separately for Female and Male rounds.
- Closing nominations activates both generated voting rounds without a separate activation step.
- The next topic is opened manually after both voting rounds for the current topic are closed.
