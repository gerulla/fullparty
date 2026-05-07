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

It is built to replace spreadsheet-heavy and Discord-only coordination with a proper roster system: verified characters, scheduled runs, explicit player slots, bench handling, application review, roster assignment, attendance tracking, and notifications in a dashboard-first workflow.

## What It Does

FullParty is centered around a few core concepts:

- `Group`: the main community or organizational unit
- `Run`: one scheduled event
- `Run Type`: a predefined content format with its own slot structure and metadata
- `Slot`: one player seat in the roster
- `Bench`: reserve slots for replacements
- `Character`: a linked FFXIV character
- `Application`: a player's submitted signup for a run

The product goal is to give FFXIV communities a serious coordination tool for statics, learning parties, prog groups, and larger community-run events.

## Current Features

- Multi-auth sign-in:
  email/password, Google, Discord, and XIVAuth
- Character linking and verification
- Guest applications and signed-in applications
- Automatic claiming of guest applications when a character is later verified
- Group membership, moderation, and audit logging
- Run creation, editing, publishing, cancellation, and completion
- Slot-based roster planning with bench handling and return-to-queue flows
- Application review, decline, and run-cancellation outcomes
- Guest application status links with read-only revisit support
- Attendance tools:
  check-in, late, missing, and undo missing
- Real-time in-site notifications with Reverb
- Queue-backed off-site notification delivery
- Pulse integration for admin-only operational visibility
- Localization support for multiple languages

## Roadmap

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
- Realtime:
  Laravel Reverb
- Monitoring:
  Laravel Pulse
- Testing:
  Pest + Laravel testing tools

## Quick Start

### Requirements

- PHP `8.3+`
- Composer
- Node.js `20+` and npm
- PostgreSQL

### Installation

```bash
git clone https://github.com/gerulla/fullparty.git
cd fullparty
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

On Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

After that, configure your `.env` for:

- app URL
- PostgreSQL
- mail
- auth providers if needed
- Reverb if you want live notifications

## Documentation

- [LocalDevelopment.md](LocalDevelopment.md):
  local setup, Laravel Herd workflow, queues, scheduler, Reverb, Pulse, and tests
- [ForgeSetup.md](ForgeSetup.md):
  production-style deployment on Laravel Forge, including queue workers, scheduler, Reverb, Pulse, and heartbeat monitoring

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
