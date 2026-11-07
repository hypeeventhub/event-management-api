# Edit Registration Answers Design

## Intent

Let an event owner correct an attendee's saved registration answers from the attendees page. First name, last name, and email stay read-only because they identify the shared attendee record. Other saved form answers can be edited.

## Goals

- Add an Edit/Cancel/Save mode to the existing Answered registration form dialog.
- Render editors from the field metadata saved with the registration, including original field type and allowed choice values.
- Persist edits through an authenticated, event-owner-only API endpoint.
- Validate submitted values on the server against the registration's original form fields.
- Refresh the attendee list and dialog values after a successful save.

## Non-goals

- Changing attendee name or email, registration status, check-in status, registration code, or the event's current registration form.
- Adding audit history or changing who can view attendee answers.

## Approaches

1. **Edit the existing dialog (recommended).** Add explicit Edit, Cancel, and Save states to the current read-only modal. This keeps the admin in the attendee row context and matches the established review flow.
2. **Create a dedicated edit page.** This allows more space but adds navigation for a single registration form and duplicates existing dialog context.
3. **Inline-edit every answer in read mode.** This reduces clicks but makes accidental changes easier and complicates saving individual choices.

Use approach 1. The existing dialog is already the attendee answer review surface.

## Data and API

- Add an authenticated `PATCH` endpoint scoped to an event and registration.
- Confirm the registration belongs to the event and the current user owns that event.
- Accept edits keyed by form-field ID, but only for fields attached to the registration's saved form and not the first name, last name, or email identity fields.
- Validate required values, strings, dates, checkbox arrays, and choice values against the saved field metadata; do not trust client-supplied labels or options.
- Update answer rows transactionally and return the refreshed registration with its answers and field metadata.
- A request for a registration from another event must return not found.

## UI behavior

- Read mode remains the default and shows Edit.
- Edit mode renders input, paragraph, date, single-choice, and checkbox controls using the stored field type and options.
- First name, last name, and email remain visible but read-only.
- Cancel discards local edits. Save disables the form while pending, displays validation errors, and returns to read mode after success.
- Successful saves update the selected registration and revalidate the attendee list.

## Verification

- API feature tests cover valid edits, required/type/choice validation, event ownership, and cross-event registration protection.
- Frontend utility tests cover editable field selection and value projection from stored answer metadata.
- Run the focused tests and the available project checks after implementation.
