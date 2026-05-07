# FullParty Local Development

This guide covers how to run FullParty locally, including the background processes the app needs for a complete development environment.

It is especially useful if you are using:

- Laravel Herd on Windows
- Reverb for live notifications
- Pulse for local monitoring

## Requirements

- PHP `8.3+`
- Composer
- Node.js `20+` and npm
- PostgreSQL

SQLite is also used for the default local test suite, but the application is designed with PostgreSQL in mind.

## Installation

1. Clone the repository

```bash
git clone https://github.com/gerulla/fullparty.git
cd fullparty
```

2. Install backend and frontend dependencies

```bash
composer install
npm install
```

3. Create your environment file

```bash
cp .env.example .env
```

On Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

4. Configure your `.env`

At minimum:

- set the app URL
- configure your PostgreSQL database
- configure mail if needed
- configure Google / Discord / XIVAuth credentials if you want those auth providers locally
- configure Reverb if you want live notifications
- configure `FULLPARTY_LEGAL_CONTROLLER_NAME` and `FULLPARTY_LEGAL_CONTACT_EMAIL` if you want the built-in legal pages to reflect your local or self-hosted instance accurately

5. Generate the app key and run migrations

```bash
php artisan key:generate
php artisan migrate
```

## Background Processes

FullParty relies on a few long-running Laravel processes in addition to the web app itself.

- `queue worker`:
  required for queued emails, off-site notifications, and other async jobs
- `scheduler`:
  required for run reminder notifications and any future scheduled automation
- `reverb`:
  required for live in-site notification updates
- `pulse`:
  optional, but useful for in-site operational monitoring and queue / slow-job visibility
- `vite`:
  required for local frontend hot reload during development

If the queue worker is not running:

- emails will not be sent
- queued off-site notifications will not be delivered

If the scheduler is not running:

- run reminder notifications will never trigger

If Reverb is not running:

- the app still works
- but the notification bell will not update live

If Pulse is not installed or its tables are not migrated:

- the main app still works
- but the Pulse dashboard will not be available

## Starting the Dev Stack

### Standard local setup

```bash
composer run dev
```

That starts:

- the Laravel app server
- the queue listener
- the Vite dev server

That command does **not** start:

- the Laravel scheduler
- the Reverb server

So for a full local setup, also run:

```bash
php artisan schedule:work
php artisan reverb:start
```

### Laravel Herd on Windows

If you use Laravel Herd, you usually do **not** need `php artisan serve`, since Herd already handles the local web server.

For that setup, use:

```bash
composer run dev:herd
```

That starts:

- the queue listener
- the Laravel scheduler worker
- the Reverb server
- the Vite dev server

So for a Herd-based local environment, `composer run dev:herd` is the preferred one-command dev stack.

## Reverb Setup

FullParty uses Laravel Reverb for live notification updates.

Reverb is required if you want the notification bell to update without a page reload.

### 1. Confirm dependencies are installed

The repo already expects:

- `laravel/reverb` on the PHP side
- `laravel-echo` and `pusher-js` on the frontend

Those are already declared in project dependencies, so a normal `composer install` and `npm install` should bring them in.

### 2. Enable broadcasting in `.env`

Set these values in your local environment:

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret

REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080

REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

For local development:

- `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` are where the Reverb server process listens
- `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` are what the Laravel app and browser client use to connect

If you use a custom local domain like `fullparty.test`, set `REVERB_HOST` to that hostname when needed.

### 3. Clear config after changing Reverb settings

```bash
php artisan config:clear
```

### 4. Start Reverb

Run Reverb as its own long-lived process:

```bash
php artisan reverb:start
```

If you use Laravel Herd on Windows, `composer run dev:herd` already includes this.

### 5. Sanity check

If Reverb is configured correctly:

- the app boots with `BROADCAST_CONNECTION=reverb`
- the notification bell updates when new on-site notifications are created
- clicking the bell still refreshes the latest notification list as a fallback

If package installs or Composer scripts fail with broadcaster errors, check:

- `BROADCAST_CONNECTION` is not being overridden by another environment variable
- `php artisan config:clear` has been run after env changes
- the Reverb PHP package installed successfully
- the Reverb server process is actually running

## Pulse Setup

FullParty includes Laravel Pulse for in-site operational visibility.

Pulse is useful for monitoring:

- queue throughput
- slow jobs
- slow requests
- exceptions
- server usage

### 1. Install dependencies and run migrations

Pulse uses its own database tables, so make sure migrations have been run:

```bash
php artisan migrate
```

### 2. Dashboard path

By default, Pulse is available at:

```text
/pulse
```

### 3. Cache driver

Pulse caches dashboard query results separately from the rest of the application.

This project defaults Pulse to the `array` cache store so it works cleanly alongside Laravel's stricter cache unserialization settings.

If you want to override it explicitly, set:

```env
PULSE_CACHE_DRIVER=array
```

### 4. Authorization

Pulse access is restricted to site admin users only.

In this project, that means:

- authenticated user
- `is_admin = true`

### 5. Config refresh

After changing Pulse-related environment values, clear config:

```bash
php artisan config:clear
```

## Local Scheduling Notes

For local development, you do **not** need to set up a cron job.

Use:

```bash
php artisan schedule:work
```

or, on Laravel Herd:

```bash
composer run dev:herd
```

## Running Tests

Run the default backend suite:

```bash
php artisan test
```

Run the PostgreSQL-targeted test configuration:

```bash
php vendor/bin/pest --configuration=phpunit.pgsql.xml
```
