# Edit Registration Answers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let event owners edit custom registration answers in the attendees dialog while keeping attendee identity fields read-only.

**Architecture:** Add an owner-scoped PATCH endpoint that validates answer values against the registration's saved form and updates answers transactionally. Extend the existing answered-form dialog with edit, cancel, and save states, then refresh the selected registration and attendee list after saving.

**Tech Stack:** Laravel 12, PHPUnit/Pest feature tests, Next.js App Router, React, node:test.

**Spec:** `event-api/docs/superpowers/specs/2026-11-08-edit-registration-answers-design.md`

## Global Constraints

- First name, last name, email, and other system fields remain read-only; only custom (`system_key = null`) fields are editable.
- Only the event owner can edit registrations belonging to their event.
- Validate using the registration's saved form field type, required flag, and saved option values.
- Do not change attendee identity, registration status, check-in data, registration code, or the active event form.

## Review Focus

- A registration ID from another event must not be editable; feature test asserts 404.
- System-field IDs submitted as custom answers must be rejected; feature test asserts 422 and unchanged identity/answer rows.
- Required fields, malformed dates, unsupported choice values, and invalid checkbox values must be rejected; feature tests assert 422.
- An empty or optional checkbox answer must remain representable; feature test covers an empty optional selection.
- Failed save must keep the dialog in edit mode and display validation errors; frontend behavior is implemented with pending/error state and checked through build/lint if available.

---

### Task 1: Owner-scoped answer update API

**Files:**
- Modify: `event-api/routes/api.php`
- Modify: `event-api/app/Http/Controllers/EventController.php`
- Test: `event-api/tests/Feature/EventManagementTest.php`

**Interfaces:**
- Consumes: `EventController::ensureOwner(Request, Event)` and `Registration::answers()`.
- Produces: `PATCH /api/events/{event:slug}/registrations/{registrationId}/answers`, accepting `{ "answers": [{ "form_field_id": 12, "answer": "value" }] }` and returning `{ "data": <registration with attendee and answers.field.options>, "message": "Registration answers updated successfully." }`.

- [x] **Step 1: Write API feature tests**
  - Test owner can update custom text and choice answers and receives refreshed form metadata.
  - Test required empty answer, invalid date, unknown choice, invalid checkbox option, duplicate field IDs, omitted custom field, and submitted system field return 422.
  - Test another user's event and registration ID belonging to another event both return 404.
  - Test invalid requests do not change saved answers or attendee identity.
- [x] **Step 2: Run focused tests and confirm expected failures**
  - The local PHPUnit bootstrap check initially failed because PHP reports `is_readable()` false for workspace files. A temporary vendor guard adjustment allowed tests to run and was restored immediately afterward. A new eager-loading assertion then failed until the registration query included `answers.field.options`.
- [x] **Step 3: Implement route and `EventController::updateRegistrationAnswers(Request $request, Event $event, int $registrationId): JsonResponse`**
  - Call `ensureOwner`; load the registration through `$event->registrations()` with saved form fields, existing answers, and option metadata; return 404 when not found.
  - Validate the request shape, require exactly the registration's existing custom answer field IDs, reject system/foreign IDs, and build per-answer rules from saved metadata: text limits 1000/5000, `date_format:Y-m-d`, choice values from `field.options.value`, and checkbox arrays with allowed values. Required answers use `required`; optional answers use `nullable`.
  - Persist only answer rows inside `DB::transaction`; reload and return the registration with `attendee` and `answers.field.options`.
- [x] **Step 4: Run focused API tests and confirm they pass**
  - Run: `php artisan test tests/Feature/EventManagementTest.php --filter="registration answer|optional checkbox"` from `event-api` using the temporary local PHPUnit bootstrap workaround described above.
  - Expected: all focused update, validation, and ownership cases pass.

### Task 2: Editable answer form helpers

**Files:**
- Modify: `event-management/lib/registration-answers.mjs`
- Test: `event-management/tests/registration-answers.test.mjs`

**Interfaces:**
- Produces: `buildEditableAnswerValues(registration)` returning a map of custom field IDs to raw saved values; `buildRegistrationAnswerPayload(values)` returning `{ answers: [{ form_field_id, answer }] }` in numeric field order; `formatRegistrationAnswer(answer)` preserving the existing display fallback for structured values.

- [x] **Step 1: Write failing helper tests**
  - Verify editable values include custom fields only, preserve strings, dates, choice values, and checkbox arrays, and omit system fields.
  - Verify payload generation uses numeric IDs in stable order and retains empty optional values.
- [x] **Step 2: Run helper tests and confirm the new exports are missing**
  - Run: `node --test tests/registration-answers.test.mjs` from `event-management`.
  - Expected: tests fail because the helper functions are not exported.
- [x] **Step 3: Implement the two helpers**
  - Preserve existing `buildAnsweredFormRows` behavior; normalize only absent answers to null for edit payloads and keep original values for controls.
- [x] **Step 4: Run helper tests and confirm they pass**
  - Run: `node --test tests/registration-answers.test.mjs` from `event-management`.
  - Expected: all existing and new helper tests pass.

### Task 3: Answered-form dialog edit flow

**Files:**
- Modify: `event-management/components/dashboard/answered-forms-dialog.js`
- Modify: `event-management/app/events/[id]/attendees/page.js`

**Interfaces:**
- Consumes: the helper exports from Task 2 and API endpoint from Task 1.
- Produces: dialog `onSave(payload)` callback; page PATCHes the selected event registration, replaces selected registration with the response, and calls `mutateRegistrations()`.

- [x] **Step 1: Read the installed Next.js UI and client-component guidance**
  - Read the installed `use-client.md` and forms guide, plus existing `Input`, `Button`, `Badge`, and dialog components. No shadcn `Textarea` or `Select` component exists in this checkout, so native controls use matching styles.
- [x] **Step 2: Implement dialog states and controls**
  - Read mode shows existing values and an Edit action when custom answers exist.
  - Edit mode keeps system fields visible and read-only; custom `short`, `paragraph`, `date`, `multiple`, `dropdown`, and `checkboxes` fields use saved metadata and values.
  - Cancel resets the draft. Save is disabled while pending; API errors remain visible without leaving edit mode. Successful save replaces dialog data and exits edit mode.
- [x] **Step 3: Connect dialog save to the page API and refresh**
  - Add the `onSave` handler in `page.js`, PATCH the current `id` and registration ID, update `selectedRegistration`, and revalidate the attendee SWR key.
- [x] **Step 4: Run available frontend checks**
  - Run: `node --test tests/registration-answers.test.mjs` and the repository's existing lint/build command if configured.
  - Expected: helper tests pass and the frontend reports no syntax or compilation errors.

### Task 4: Final verification

**Files:**
- Review all files modified above.

- [x] Run API focused tests: `php artisan test tests/Feature/EventManagementTest.php --filter="registration answer|optional checkbox"` from `event-api` (4 passed, 34 assertions; used the temporary local PHPUnit bootstrap workaround).
- [x] Run frontend helper tests: `node --test tests/registration-answers.test.mjs` from `event-management` (5 passed).
- [x] Run frontend lint checks. Targeted ESLint passed; full lint still reports existing warnings in generated `.worktrees/nomination-voting/.next` output and unrelated existing effect rules. `npm run build` started but produced no completion output and had no active Node process, so it was stopped.
- [x] Run full Laravel suite. It reported 175 passing and 2 existing failures: event update test expects 6 form fields but receives 5, and invitation test expects a mail attachment.
- [x] Review the final diff for authorization, field-ID scoping, answer preservation, and identity immutability. A read-only reviewer found no critical or important issues; a minor structured-answer display fallback was fixed and covered with a test.
