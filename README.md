# Train Ticket Finder Bot

This project is a **Telegram bot** designed to manage train ticket notifications. It allows users to input origin, destination, and departure date, then fetches available train tickets using the Railway API.

## Features

- **Admin Notifications**: Notify admins when new train tickets are available.
- **User-Friendly Interface**: Users can easily input search parameters via Telegram.
- **SQLite Integration**: Stores user data and search queries.
- **Dynamic Headers**: Makes API requests with dynamic headers for improved compatibility.

## Technologies Used

- **PHP**: Core language for bot logic.
- **SQLite**: Lightweight database to store user data and search queries.
- **Telegram Bot API**: Direct interaction with Telegram's API without additional libraries.
- **Railway API**: Fetches train ticket data.

## Installation

1. **Clone the repository**:
   ```bash
   git clone https://github.com/sma-abyar/train-ticket-finder.git
   cd train-ticket-finder
   ```

2. **Set up your environment file**:
   Create a `.env` file in the root directory:
   ```env
   BOT_TOKEN=your_telegram_bot_token
   ADMIN_CHAT_ID=your_admin_chat_id
   URL=https://ghasedak24.com/train/search_ajax
   DB_PATH=path_to_your_database_here
   HEADERS=your_json_header
   ```

3. **Install dependencies**:
   Use PHP 8.0 or later with the `curl` and `sqlite3` extensions. No package installation is required.

4. **Run the bot**:
   ```bash
   php bot.php
   ```

   `bot.php` handles incoming Telegram webhook updates. For scheduled ticket
   checks, run the worker described below.

## Scheduled ticket checks (Telegram)

The Telegram version is maintained on `main`; the Bale version is on `bale`.
Run the Telegram worker from cron at your existing checking interval. For example,
to check once a minute (adjust the PHP executable and project path for your server):

```cron
* * * * * /usr/bin/php /path/to/Train-Ticket-Finder/check_tickets.php >> /path/to/ticket-check.log 2>&1
```

Use an absolute `DB_PATH` in `.env`. The worker must use the same database as the
webhook and have write access to the project directory for `ticket-check.lock`.
Overlapping invocations of this worker on the same host are skipped. If your
existing cron already calls `fetchTicketsForAllUsers()`, that function also uses
the shared searches and expiration cleanup; replace the old invocation rather
than adding a second schedule.

- During each check, identical broad searches are fetched once. The upstream
  passenger count is always 1, so subscribers requesting different seat counts
  reuse the same raw result. Route, departure date, quota (ordinary/women/men),
  coupe and the legacy API filter remain part of the upstream search identity.
  For example, 100 subscribers with different counts, prices and time preferences
  on the same inventory produce one upstream request per cycle.
- Messages, reservation buttons and notification flags remain specific to each
  user and trip. Only approved users are included in the scheduled ticket checks.
- Results are kept only for the current check. The next check fetches fresh data,
  including after an upstream error. Separate webhook checks do not share a cache.
- Before searching, dates earlier than today in `Asia/Tehran` are removed for all
  users. Today's trips are retained. Jalali dates (including Persian/Arabic digits
  and `/` separators) and modern Gregorian dates are supported; malformed dates
  are preserved. A per-user check also cleans that user's expired trips.
- Each deletion and its notification are saved atomically in the existing user
  database. Failed notifications are retried on subsequent checks. Concurrent
  workers claim pending messages before sending; abandoned claims recover after
  five minutes. As with other network notifications, a process crash after
  Telegram accepts a message but before SQLite records success can cause a retry.

The `expired_trip_notifications` table is created automatically in `DB_PATH`;
existing trips do not need to be migrated. Both deletion and notification happen
on the first scheduled check after the date passes, even without new user messages.

## Per-user capacity and search filters

After entering the number of seats (1–12), the user chooses whether to receive
results with fewer available seats. Infants without seats do not count toward this
number. New and existing trips default to sufficient capacity only unless the
user opts in. Lower-capacity messages are labelled, purchase links use at most the
available seat count, and reservation lists needing too many seats are hidden.
The original passenger list is never silently shortened.

The optional filter menu supports multiple railway company names, four/six-seat
compartments or seated trains, a departure time interval (including overnight
ranges), and a per-person price range in **toman**. These filters are applied to
the shared response inside the bot. They do not cause additional upstream searches.
The API's `cost` field is in toman; the formatted `cost_title` displayed in ticket
messages is in rial. Time intervals include the start and exclude the end.

Use the "فیلترهای سفر" button in the trip list to edit existing preferences.
Preferences are added automatically through two SQLite columns:
`allow_partial_capacity` (default 0) and `search_filters` (default empty). No saved
trips are removed during this upgrade. Wheelchair remains a passenger option and
is forwarded at reservation, never used to split shared searches.

The current Ghasedak search contract was checked against public frontend code and
live read-only searches on 2026-11-10; see [provider notes](docs/ghasedak-search.md).
The bot still monitors one-way passenger trips; this change does not add return
journey or car-transport booking flows.

## Tests

Run the local regression suite without credentials or external API calls:

```bash
php tests/run.php
php tests/integration.php
node tests/alerts_frontend.test.js
```

The integration test starts local mock HTTP services and runs a disposable copy
of the real Telegram worker. It checks request counts, per-user reservation
buttons, expiration notifications, fresh subsequent checks and cron locking.
It also covers partial capacity, independent local filters, setup/edit callbacks,
trip ownership and wheelchair data in the mock reservation request.

## Following specific trains

Each trip has a "قطارهای هشدار" button. The picker lists every service in the
shared provider response, including sold-out trains, with train number, time,
wagon, company and current availability. Users can edit the choices later or
choose all trains / pause this trip. The existing capacity and local search
filters still apply.

New trips created through the setup flow default to **all trains** and start
checking immediately. Existing subscriptions keep their saved settings on upgrade.
Clearing the selected list pauses alerts; it never falls back to all trains.
The "all trains" and "all except muted" modes retain the existing per-poll notification behavior;
transition-only notifications apply to the selected-trains mode.

Every ticket message includes "دیگه این قطار رو نمی‌خوام". In all-trains mode,
this switches the trip to all-except-muted: other trains, including future newly
listed services, remain enabled. In selected-only mode, it removes only that
service from the selection. The confirmation offers an undo button, and the
same setting can be edited in the Mini App or native picker. Exclusions are
stored in the automatically added `excluded_trains` column. Repeated presses
are idempotent, and callbacks verify the trip owner.

After a batch actually sends ticket messages, one trip-specific footer explains
how to select other services, including currently unavailable trains. It links
to that trip's train picker and filters. No footer is sent for a quiet poll.
The main keyboard also exposes "مدیریت هشدارها" (`/alerts`), while trip lists,
ticket messages, filter settings, picker confirmations and empty-result messages
provide contextual navigation. Saving one trip's settings checks that trip only.

For **selected trains**, notifications are sent when a matching service becomes
available or its capacity becomes sufficient. If partial capacity is allowed,
the first partial opening and later reaching the requested seat count are
separate events. Steady availability does not repeat notifications. A successful
unavailable observation re-arms a later reopening; provider failures do not.
Failed sends retry, concurrent sends use delivery leases, and abandoned claims
recover after five minutes. A crash after Telegram accepts a message but before
SQLite records success can still result in a retry.

Selections are scoped to a saved trip (route, day, quota and coupe). A service is
identified by train number, departure time, owner, wagon type and source rather
than the provider's mutable inventory ID, price or capacity. Temporarily missing
services remain selectable and their saved choices survive catalog refreshes.
Changes to the actual service identity, such as a new departure time or wagon
type, require selecting the new service.

The full catalog is stored once per broad search, independently of each user's
display filters. Opening a populated picker reuses this catalog. A cold picker
or explicit refresh can fetch it; refreshes reuse snapshots younger than 30
seconds. These snapshots are for selection only: scheduled monitoring always
uses fresh API responses and never alerts from the catalog cache. Catalogs not
updated for seven days are removed. Paused trips do not generate ticket searches.

### Telegram Mini App deployment

Upload `telegram-alerts.php`, `telegram-alerts.html`, `telegram-alerts.css`,
`telegram-alerts.js` and the PHP helper files alongside `bot.php` on the existing
PHP server. Configure the HTTPS entry point in `.env`:

```env
ALERTS_WEB_APP_URL=https://your-bot-server.example/telegram-alerts.php
```

This page needs the same `.env` and `DB_PATH` as the bot. It cannot run solely on
the static object-storage host used by the route picker. Its assets and API use
the same origin. The private inline Web App button passes signed Telegram
`initData`, which the backend verifies with HMAC, a one-hour session lifetime and
an ownership check on every read and write. The bot token is never sent to the
browser. Conflicting concurrent edits return a conflict rather than silently
overwriting a newer choice.

When `ALERTS_WEB_APP_URL` is unset, the same selection is available immediately
inside the Telegram conversation through a paginated checkbox-style keyboard.
With a configured Mini App, "انتخاب داخل گفتگو" remains available as an alternative.

The `follow_mode`, `followed_trains` and `follow_revision` columns, catalog table
and delivery-state table are added automatically. No existing trip is removed
by the upgrade. Deleting an expired or manually removed trip also clears its
followed-alert delivery state.

## Usage

If the Mini App is unstyled or shows oversized black icons, see
[the hosting asset fix](MINI_APP_ASSETS_FIX.md). Upload bundles must be built
with `python tools/build_upload.py output.zip` to preserve public file permissions.

For notification delivery failures, see [delivery diagnostics and fixes](NOTIFICATION_DELIVERY.md).

Broadcasts snapshot approved recipients atomically, resume from the existing queue
after interruptions, and expose admin-only delivery progress through
`/broadcast_status`. Run `send_queue.php` once per minute; keep ticket polling on
its existing three-minute schedule. Both workers support local shared-host CGI
execution and reject HTTP requests. Delivery failures and revoked approvals stay
visible instead of being counted as successful sends.

The redesigned train list uses independent on/off switches with immediate saving,
idempotent retries and server reconciliation after interrupted responses. See
[the redesign and installation notes](MINI_APP_REDESIGN.md).

The Telegram unified Mini App now handles complete trip creation (route, Jalali
date, passengers, quota, coupe and optional local filters), owned trip preferences
and train alert selection in one interface, including confirmed trip deletion.
Trip-list and alert-list bot commands return one compact Mini App entry regardless
of trip count. See [deployment instructions](MINI_APP_DEPLOY.md)
and the [design decisions](design/miniapp/README.md).

Set `MINI_APP_URL` to the HTTPS URL of `telegram-app.php`, or keep the existing
`ALERTS_WEB_APP_URL` ending in `telegram-alerts.php` to derive the sibling URL.
Existing alert links and the old alert POST API remain compatible. Inline Web App
launchers supply signed authentication; the old reply-keyboard route page remains
available only as a legacy fallback when no Mini App URL is configured.

Validation: `php tests/run.php`, `php tests/integration.php`,
`node tests/calendar.test.js`, `node tests/miniapp_frontend.test.js`, and
`node tests/alerts_frontend.test.js`.

1. **Start the bot**:
   Users can start interacting with the bot via Telegram by sending a message to the bot.

2. **Admin notifications**:
   The admin will be notified when tickets matching the users' queries become available.

3. **Search for train tickets**:
   Users can enter their search parameters to get real-time train ticket availability.

## Project Structure

```
train-ticket-finder/
├── bot.php            # Main bot logic
├── .env               # Environment variables
├── users.db           # SQLite database
└── README.md          # Project documentation
```

## Contributing

Feel free to submit issues or pull requests if you'd like to contribute to this project.

## License

This project is licensed under the MIT License. See the `LICENSE` file for details.

--- 

Let me know if you'd like to add or adjust anything!
