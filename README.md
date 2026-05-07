
<p align="center">
  <img src="public/logos/full.png" width="420" alt="FullParty logo">
</p>

<p align="center">
  <a href="https://github.com/gerulla/fullparty/actions/workflows/backend-tests.yml">
    <img src="https://github.com/gerulla/fullparty/actions/workflows/backend-tests.yml/badge.svg" alt="Backend Tests">
  </a>
  <a href="https://laravel.com">
    <img src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white" alt="Laravel 13">
  </a>
  <a href="https://vuejs.org">
    <img src="https://img.shields.io/badge/Vue-3-4FC08D?logo=vue.js&logoColor=white" alt="Vue 3">
  </a>
  <a href="https://inertiajs.com">
    <img src="https://img.shields.io/badge/Inertia.js-2-9553E9" alt="Inertia.js 2">
  </a>
  <a href="https://ui.nuxt.com">
    <img src="https://img.shields.io/badge/Nuxt_UI-4-00DC82" alt="Nuxt UI 4">
  </a>
  <a href="https://www.postgresql.org">
    <img src="https://img.shields.io/badge/PostgreSQL-16-4169E1?logo=postgresql&logoColor=white" alt="PostgreSQL">
  </a>
  <a href="LICENSE">
    <img src="https://img.shields.io/badge/License-GPLv3-blue.svg" alt="GPL v3">
  </a>
</p>

FullParty is a web app for organizing structured Final Fantasy XIV group runs.

It is built to replace spreadsheet-heavy and Discord-only coordination with a proper roster system: verified characters, scheduled runs, explicit player slots, bench handling, application review, roster assignment, and attendance tracking in a dashboard-first workflow.

## What It Does

FullParty is centered around a few core concepts:

- `Group`: the main community or organizational unit
- `Run`: one scheduled event
- `Run Type`: a predefined content format with its own slot structure and metadata
- `Slot`: one player seat in the roster
- `Bench`: reserve slots for replacements
- `Character`: a linked FFXIV character
- `Application`: a player's submitted signup for a run

The product goal is to give FFXIV communities a serious coordination tool for static groups, learning parties, prog groups, and larger community-run events.

## Current Features

- Multi-auth sign-in:
  email/password, Google, Discord, and XIVAuth
- Character linking and verification
- Guest applications and signed-in applications
- Automatic claiming of guest applications when a character is later verified
- Group membership and moderator permissions
- Run creation and editing
- Slot-based roster planning
- Bench assignment and return-to-queue flows
- Application review, decline, and run-cancellation outcomes
- Guest application status links with read-only revisit support
- Attendance tools:
  check-in, late, missing, and undo missing
- FF Logs lookups in moderation flows
- User-facing application history
- Group audit log coverage for key moderation actions
- Localization support for multiple languages

## Roadmap

- Automated notifications for applications, assignments, declines, and cancellations
- Recurring run templates and duplicate-run workflows
- Calendar-first scheduling views
- Direct self-assignment flow for runs that do not use applications
- Richer group public profile and community-facing pages
- Reporting for attendance, fill rate, bench usage, and participation history
- Better Discord/share flows for public and private run links

## Stack

- Backend:
  PHP 8.3+, Laravel 13, Inertia, Socialite, Ziggy
- Frontend:
  Vue 3, Inertia, Nuxt UI 4, Tailwind CSS 4, Vue I18n
- Data:
  PostgreSQL in production intent
- Testing:
  Pest + Laravel testing tools

## Getting Started

### Requirements

- PHP `8.3+`
- Composer
- Node.js `20+` and npm
- PostgreSQL

SQLite is also used for the default local test suite, but the application is designed with PostgreSQL in mind.

### Installation

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

- set the app URL
- configure your PostgreSQL database
- configure mail if needed
- configure Google / Discord / XIVAuth credentials if you want those auth providers locally
- configure Reverb if you want live notifications and other broadcast features

5. Generate the app key and run migrations

```bash
php artisan key:generate
php artisan migrate
```

6. Start the local development stack

```bash
composer run dev
```

That starts:

- the Laravel app server
- the queue listener
- the Vite dev server

### Reverb Setup

FullParty uses Laravel Reverb for live notification updates.

Reverb is required if you want the notification bell to update without a page reload.

#### 1. Make sure the backend and frontend dependencies are installed

The repo already expects:

- `laravel/reverb` on the PHP side
- `laravel-echo` and `pusher-js` on the frontend

Those are already declared in the project dependencies, so a normal `composer install` and `npm install` should bring them in.

#### 2. Enable broadcasting in `.env`

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

#### 3. Clear config after changing Reverb environment values

```bash
php artisan config:clear
```

#### 4. Start the Reverb server

Run Reverb as its own long-lived process:

```bash
php artisan reverb:start
```

Important: `composer run dev` does **not** currently start Reverb for you. You need Reverb running alongside the normal dev stack.

So a full local realtime setup is:

```bash
composer run dev
php artisan reverb:start
```

Or, if you prefer separate processes:

```bash
php artisan serve
php artisan queue:listen --tries=1
npm run dev
php artisan reverb:start
```

#### 5. Sanity check

If Reverb is configured correctly:

- the app boots with `BROADCAST_CONNECTION=reverb`
- the notification bell updates when new on-site notifications are created
- clicking the bell still refreshes the latest notification list as a fallback

If package installs or Composer scripts fail with broadcaster errors, check:

- `BROADCAST_CONNECTION` is not being overridden by another environment variable
- `php artisan config:clear` has been run after env changes
- the Reverb PHP package installed successfully
- the Reverb server process is actually running

### Production Reverb Notes

For a lightweight deployment, Reverb can run on the same server as the Laravel app, but it is still a separate long-running process and should be supervised accordingly.

At minimum, production should ensure:

- Reverb runs under a process manager
- the queue worker is running
- websocket traffic is reachable on the configured host/port
- TLS / reverse proxy setup is handled appropriately if the app is served over HTTPS

## Production Build

To build frontend assets:

```bash
npm run build
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

## Contributing

Contributions are welcome.

If you want to work on FullParty, please:

- open an issue or discussion first for larger changes
- keep pull requests focused
- add or update backend tests for behavior changes
- follow existing Laravel, Vue, and UI patterns already used in the codebase

## Code of Conduct

Please be respectful, constructive, and kind.

This project is meant to support communities, and that should be reflected in how we collaborate here too. Harassment, hostility, and bad-faith participation are not welcome.

## Security Vulnerabilities

If you discover a security issue, please do not open a public issue with exploit details.

Instead, report it privately to the maintainers so it can be reviewed and fixed responsibly. If a dedicated disclosure process is added later, this section should point to it directly.

## License

FullParty is open-source software licensed under the [GNU General Public License v3.0](LICENSE).
