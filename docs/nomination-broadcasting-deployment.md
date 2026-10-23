# Nomination broadcasting deployment

Nomination leaderboards receive private statistics updates through Laravel Reverb. A successful nomination vote dispatches `NominationStatisticsUpdated` after the database transaction commits. A successful close separately dispatches the compact `NominationClosed` notice after commit. Both broadcasts are rescued so a delivery/queue failure cannot roll back a saved vote or close. Delivery needs both a working Reverb connection and a running queue worker. The application uses the configured queue connection and its default queue; it does not define a separate broadcast queue.

The WebSocket message is a compact aggregate (`event_id`, `subject_id`, status, totals, and a vote-count vector), not the REST response: it omits contestant names and roster fields. Tests verify the payload remains below Reverb's default 10,000-byte message size with 2,000 nominees and 2,000 total registrations. The admin client combines it with the authorized REST roster; malformed or mismatched vectors cause a REST revalidation. The REST response shape remains unchanged. There is no fixed nominee-count cap; substantially larger events should be load-tested against the configured Reverb message-size limit.

## Provision and attach the WebSocket application

In Laravel Cloud, create a Reverb WebSocket cluster from the organization's Resources > WebSockets page. Select the appropriate region and connection capacity. On the API environment's canvas, use Add resource > WebSockets to attach a WebSocket application from that cluster, then redeploy. Use a separate WebSocket application for each environment to keep its credentials and channels separate. See [Laravel Cloud WebSockets](https://laravel.com/cloud/docs/resources/websockets).

Allow the deployed frontend origin in the WebSocket application's Allowed origins settings, especially when the frontend is hosted separately. Laravel Cloud manages the Reverb server; a second `php artisan reverb:start` process in the API environment is unnecessary.

## API environment variables

Configure these in the **event-api Laravel Cloud environment**. Cloud injects the attached application's Reverb settings; verify they match that attachment and remove stale overrides. The angle-bracket values below are placeholders, not deployable credentials:

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<attached-websocket-app-id>
REVERB_APP_KEY=<attached-websocket-public-app-key>
REVERB_APP_SECRET=<attached-websocket-server-secret>
REVERB_HOST=<managed-reverb-hostname-without-scheme>
REVERB_PORT=<managed-reverb-tls-port>
REVERB_SCHEME=<managed-reverb-https-scheme>
QUEUE_CONNECTION=database
```

Keep `REVERB_APP_SECRET` in backend environment configuration only. Keep credentials out of commits, screenshots, and logs.

`config/broadcasting.php` defaults to the `null` broadcaster when `BROADCAST_CONNECTION` is absent, so explicitly select `reverb`. Its Reverb defaults are port `443` and scheme `https`. The repository's `.env.example` instead supplies local host/port/scheme defaults; replace those with the managed endpoint. `REVERB_SERVER_HOST` and `REVERB_SERVER_PORT` in that example configure a self-hosted listening process, not the destination used by the API to publish to Cloud.

## Frontend environment variables

Configure these separately in the **event-management build environment**, using the public settings for the same attached WebSocket application:

```dotenv
NEXT_PUBLIC_REVERB_APP_KEY=<same-public-app-key-as-backend>
NEXT_PUBLIC_REVERB_HOST=<browser-reachable-managed-reverb-hostname-without-scheme>
NEXT_PUBLIC_REVERB_PORT=<managed-reverb-tls-port>
NEXT_PUBLIC_REVERB_SCHEME=<managed-reverb-https-scheme>
```

All `NEXT_PUBLIC_*` variables are public browser configuration embedded by Next.js during the production build. Never put the Reverb app secret in them. Backend variables, including Cloud's automatically supplied `VITE_*` settings, do not configure this separate Next.js application. Rebuild and redeploy the frontend after changing its public settings. For the secure Cloud endpoint, use its HTTPS scheme and TLS port; the client selects WSS when the scheme is `https`. If any of these four settings is empty, the client disables its realtime connection.

## Always-on default queue worker

The repository defaults to the database queue connection and queue name `default`. Apply the normal production migrations so the queue tables exist, and ensure the worker uses the same environment, database, and Reverb configuration as the API. If `DB_QUEUE` is set, its value must match the worker's queue selection.

Add a dedicated worker cluster to the Laravel Cloud API environment. Configure a background queue worker with at least one process and keep the cluster awake instead of allowing it to follow the app cluster's sleep state. Process the `database` connection and `default` queue. The equivalent command is:

```sh
php artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=60
```

Save and deploy the worker settings. Cloud restarts workers on deployment. See [Laravel Cloud workers](https://laravel.com/cloud/docs/workers). If choosing another durable queue connection, configure its infrastructure and update both API and worker settings together. The database connection's configured retry interval defaults to 90 seconds, above the example worker timeout.

The event's after-commit interface defers dispatch even though `config/queue.php` sets the connection's `after_commit` option to `false`. A WebSocket cluster alone cannot drain queued broadcasts. Monitor worker logs, backlog, and failed jobs when votes save successfully but statistics do not update.

## Deployment smoke check

1. Open an active nomination leaderboard as its owning admin. Confirm a secure WebSocket connection and successful private-channel authorization through `/api/broadcasting/auth`.
2. Submit a valid nomination in a separate attendee session. Confirm the saved vote and an updated leaderboard snapshot without reloading.
3. Confirm another admin and an attendee cannot authorize the owner's private statistics channel. After closing, confirm a new channel authorization is rejected while the owner's already-connected leaderboard receives the close notice, refreshes from the results endpoint, and leaves the channel.
4. Disconnect and reconnect the owner's browser; confirm the leaderboard refreshes from the results endpoint on recovery. Confirm further votes are rejected after the close.

These checks require a deployed API, built frontend, attached cluster, and active worker; unit tests and a successful build do not establish production connectivity.
