# FullParty on Laravel Forge

This guide documents the recommended Forge setup for running FullParty in production.

It is written for the app as it exists today:

- Laravel 13
- database-backed queues
- Laravel Reverb for live in-site notifications
- Laravel Pulse for admin-only operational visibility
- Vite-built frontend assets

It does **not** assume Horizon, Redis queues, Docker, or SSR.

## Overview

A working Forge deployment of FullParty should have:

1. the main Laravel site deployed
2. one or more queue workers
3. the Laravel scheduler running every minute
4. a Reverb process running
5. Pulse migrated and accessible to site admins

If any one of those is missing, parts of the app will still work, but important functionality will be degraded:

- no queue worker:
  queued emails and off-site notifications will not send
- no scheduler:
  run reminder notifications will never dispatch
- no Reverb:
  the site still works, but the notification bell will not update live
- no Pulse migrations:
  `/pulse` will not work correctly

## 1. Create the Forge Site

Create the site in Forge as a normal Laravel application and connect it to the repository.

Make sure the server has:

- PHP `8.3+`
- Composer
- Node.js / npm
- PostgreSQL

This project is intended for PostgreSQL in production.

## 2. Environment Variables

Before the first successful deploy, configure the site's `.env` with at least:

```env
APP_NAME=FullParty
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=fullparty
DB_USERNAME=your-user
DB_PASSWORD=your-password

QUEUE_CONNECTION=database
CACHE_STORE=redis
SESSION_DRIVER=database

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

PULSE_CACHE_DRIVER=array

BROADCAST_CONNECTION=reverb
```

Also configure:

- mail settings
- Google / Discord / XIVAuth credentials if those login providers are enabled
- Reverb credentials and host settings
- FF Logs API credentials if FF Logs integrations are enabled
- legal/controller details for the privacy and cookies pages

For the bundled legal pages, configure:

```env
FULLPARTY_LEGAL_CONTROLLER_NAME=Your Operator or Company Name
FULLPARTY_LEGAL_CONTACT_EMAIL=privacy@example.com
```

If you self-host or fork FullParty, do not leave the legal pages untouched. They should be reviewed and adapted so they accurately reflect your own service, operator, providers, and data practices.

### Postmark stream configuration

If you are using Postmark for production email delivery, configure both message streams explicitly:

```env
MAIL_MAILER=postmark
POSTMARK_API_KEY=your-postmark-server-token
POSTMARK_TRANSACTIONAL_MESSAGE_STREAM_ID=outbound
POSTMARK_BROADCAST_MESSAGE_STREAM_ID=broadcast
```

In this repo:

- transactional notifications use the transactional Postmark stream
- optional system announcement emails use the broadcast Postmark stream

That means important app mail such as:

- account notifications
- assignment notifications
- application notifications
- run reminders
- maintenance notices

stays on the transactional stream, while optional feature/news/update announcements use the broadcast stream.

### Redis cache recommendation

For Forge, FullParty should use Redis for the main application cache:

```env
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

Why:

- Redis is the better production cache backend for Laravel
- it avoids putting general cache traffic in the database
- it plays more nicely with operational features like locks and cache-heavy app flows

Important distinction:

- the app cache should use Redis
- Pulse should still keep:

```env
PULSE_CACHE_DRIVER=array
```

That Pulse-specific cache setting is intentional for this repo and avoids the dashboard cache hydration issue we already hit.

### Social authentication environment variables

If you are using social login providers on the Forge instance, configure:

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://your-domain.example/auth/google/callback

DISCORD_CLIENT_ID=
DISCORD_CLIENT_SECRET=
DISCORD_REDIRECT_URI=https://your-domain.example/auth/discord/callback

XIVAUTH_CLIENT_ID=
XIVAUTH_CLIENT_SECRET=
XIVAUTH_REDIRECT_URI=https://your-domain.example/auth/xivauth/callback
```

If a provider is not being used, its variables can be left unset.

### FF Logs environment variables

If FF Logs-backed features are enabled, configure:

```env
FFLOGS_CLIENT_ID=
FFLOGS_CLIENT_SECRET=
FFLOGS_TOKEN_URL=https://www.fflogs.com/oauth/token
FFLOGS_GRAPHQL_URL=https://www.fflogs.com/api/v2/client
FFLOGS_FORKED_TOWER_BLOOD_ZONE_ID=
```

The URL values can usually stay at their defaults unless FF Logs changes its API endpoints or you have a specific override reason.

## 3. Reverb Environment

FullParty uses Reverb for live notification updates.

Use a real production configuration for these variables:

```env
REVERB_APP_ID=fullparty
REVERB_APP_KEY=your-reverb-key
REVERB_APP_SECRET=your-reverb-secret

REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

REVERB_HOST=your-domain.example
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Notes:

- `REVERB_SERVER_*` is where the Reverb process listens
- `REVERB_*` is what Laravel and the browser client use to connect
- if the site runs behind SSL, `REVERB_SCHEME=https` and `REVERB_PORT=443` should be set accordingly

After changing env values, FullParty should refresh Laravel config during deploy:

```bash
php artisan config:clear
```

## 4. Recommended Forge Deploy Script

Use a deploy script that installs dependencies, builds assets, runs migrations, refreshes config, and restarts queue workers.

A good starting point is:

```bash
cd $FORGE_SITE_PATH

git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-interaction --prefer-dist --optimize-autoloader

npm ci
npm run build

$FORGE_PHP artisan migrate --force

$FORGE_PHP artisan config:clear
$FORGE_PHP artisan cache:clear
$FORGE_PHP artisan route:clear
$FORGE_PHP artisan view:clear

$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache

$FORGE_PHP artisan queue:restart
```

Why `queue:restart` matters:

- queue workers are long-lived
- they do not automatically pick up new code after deployment
- restarting them ensures they boot the new app version

## 5. Database and Core Migrations

Make sure the site has run:

```bash
php artisan migrate --force
```

That covers:

- core app tables
- queue tables
- Pulse tables
- notification tables
- group follow tables
- and everything else the app now depends on

## 6. Queue Worker Setup in Forge

FullParty currently uses **database-backed queues**.

Create a Forge queue worker for the site with the equivalent of:

```bash
php artisan queue:work --tries=3
```

Recommended starting values:

- connection: `database`
- queue: `default`
- tries: `3`
- timeout: `90` or `120`

What this worker handles today:

- notification email delivery
- future queued off-site delivery work
- queued character refresh jobs

Without this worker:

- emails will stop sending
- queued off-site notifications will stop sending

## 7. Scheduler Setup in Forge

FullParty uses Laravel's scheduler for recurring background work.

In Forge, enable the Laravel scheduler or create a scheduled job that runs:

```bash
php artisan schedule:run
```

Frequency:

- every minute

What this powers today:

- run reminder notifications such as:
  - run starting soon
  - run starting now

Without the scheduler:

- reminders will never dispatch

### Scheduler heartbeat monitoring

If you want Forge to alert you when the every-minute scheduler stops running, add heartbeat monitoring to the Forge scheduled job itself.

Recommended approach:

1. In Forge, edit the scheduled job that runs:

```bash
php artisan schedule:run
```

2. Enable **Monitor with heartbeats**
3. Set an alert threshold that gives a little breathing room above one minute

A good starting threshold is:

- `3` to `5` minutes

This monitors the cron job that kicks Laravel's scheduler, which is the most important failure point for FullParty's reminder flow.

#### Command shape with heartbeat ping

Forge will provide a unique heartbeat URL. The scheduled job should ping that URL only after `schedule:run` succeeds.

A safe command shape is:

```bash
php artisan schedule:run && curl -fsS "https://forge-heartbeat-url.example" > /dev/null
```

That way:

- if `schedule:run` succeeds, Forge gets the heartbeat
- if `schedule:run` fails, Forge does **not** get the heartbeat
- after the threshold is exceeded, Forge can notify you that the scheduler has stopped or is failing

#### Why monitor the minute cron job instead of the internal reminder command

In this project, reminder dispatch lives inside Laravel's scheduler:

```php
Schedule::command('notifications:dispatch-run-reminders')->everyMinute();
```

So the right first heartbeat is the outer Forge cron job:

- `php artisan schedule:run`

If that job stops, none of the internal scheduled tasks will fire anyway.

#### When to add more granular heartbeats

You only need per-task heartbeat logic later if you want alerts for a specific long-running scheduled command rather than for the scheduler as a whole.

For the current FullParty setup, monitoring the minute scheduler job is enough.

## 8. Reverb Process in Forge

Reverb must run as its own long-lived process.

On Forge, this does **not** happen automatically just because the package is installed.

You should explicitly enable Reverb from the site's Laravel application panel:

1. Open the site in Forge
2. Go to the site's **Overview**
3. Find the **Laravel** application panel
4. Enable the **Laravel Reverb** toggle
5. Provide the requested hostname / port / connection-limit details

Forge will then create and manage the Reverb daemon for you.

If you are not using Forge's Laravel Reverb integration, you must create and manage a daemon yourself with:

```bash
php artisan reverb:start
```

Reverb is responsible for:

- live notification bell updates
- user-private broadcast events

Without Reverb:

- the app still functions
- but realtime notification updates stop working

### SSL note for Reverb

If the site is served over HTTPS and Reverb should be available over secure WebSockets, make sure:

- the Reverb hostname has a valid SSL certificate
- the production env uses:

```env
REVERB_PORT=443
REVERB_SCHEME=https
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

After changing these values, redeploy or clear cached config so the app and frontend use the updated settings.

## 9. Pulse Setup in Forge

Pulse is used for operational visibility at:

```text
/pulse
```

Important project-specific notes:

- Pulse access is restricted to admin users only
- this project uses:

```env
PULSE_CACHE_DRIVER=array
```

That setting is important because this repo uses stricter cache unserialization rules, and the `array` driver avoids Pulse cache hydration issues for dashboard card data.

Pulse does **not** replace:

- queue workers
- scheduler
- Reverb

It is a monitoring surface, not a process manager.

## 10. Forge Health / Monitoring Recommendations

Recommended operational checks:

- enable queue worker monitoring in Forge
- enable the Laravel scheduler in Forge
- enable a Forge heartbeat on the every-minute `php artisan schedule:run` job
- keep Pulse enabled for site admins

This gives you:

- monitored queue workers
- monitored scheduled tasks
- in-app visibility for slow jobs and request behavior

## 11. What You Do Not Need

For the current app setup, you do **not** need Horizon.

Why:

- FullParty is currently using `QUEUE_CONNECTION=database`
- Horizon is designed for Redis queues
- Forge already handles queue workers well for this project's current needs

If the app later moves to Redis-backed queues, that decision can be revisited.

## 12. Post-Deploy Checklist

After deployment, confirm:

1. the site loads normally
2. `php artisan migrate --force` succeeded
3. queue workers are running
4. the scheduler is enabled every minute
5. Reverb is running
6. `/pulse` works for an admin account
7. creating an in-site notification updates the bell live
8. triggering an email notification results in a queued job being processed

## 13. Current Process Map

For clarity, this is the current production process model:

- web app:
  Laravel / PHP site
- queue worker:
  `php artisan queue:work --tries=3`
- scheduler:
  `php artisan schedule:run` every minute
- Reverb:
  `php artisan reverb:start`
- Pulse:
  dashboard only, no dedicated worker required

## 14. Troubleshooting

### Queue jobs are not being processed

Check:

- `QUEUE_CONNECTION=database`
- Forge queue worker exists and is running
- the worker is pointed at the correct site and PHP version

### Reminder notifications never fire

Check:

- Forge scheduler exists
- it runs every minute
- `php artisan schedule:run` is the configured command
- if heartbeat monitoring is enabled, verify the heartbeat ping is only sent after a successful `schedule:run`

### Live notifications do not update

Check:

- `BROADCAST_CONNECTION=reverb`
- Reverb daemon is running
- Reverb env values match the public host and scheme
- frontend assets were rebuilt after env changes if needed

### `/pulse` errors or behaves strangely

Check:

- migrations were run
- `PULSE_CACHE_DRIVER=array`
- config was cleared after env changes

```bash
php artisan config:clear
```

### New deploy is live, but jobs still use old code

Check that the deploy script includes:

```bash
php artisan queue:restart
```

## 15. Suggested Next Infrastructure Improvements

Not required for the current setup, but sensible later:

- split named queues such as:
  - `default`
  - `emails`
  - `characters`
- add Redis for cache and eventually for queues if operational needs grow
- revisit Horizon only if the app moves to Redis-backed queues
