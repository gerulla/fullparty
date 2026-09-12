# FullParty Agent Guidelines

This file defines the default expectations for AI agents working in this repository.
It is intentionally specific to FullParty. Prefer these instructions over generic
Laravel, Vue, or Inertia advice when they conflict.

FullParty is a Laravel 13 + Inertia + Vue 3 application for organizing structured
Final Fantasy XIV group activities. The product is coordination-heavy: verified
characters, groups, activity types, applications, roster slots, bench handling,
attendance, audit logging, notifications, and realtime dashboard updates all need
to stay consistent with each other.

## First Principles

1. Preserve behavior unless the user explicitly asks for product changes.
2. Prefer small, focused changes over broad rewrites.
3. Read the existing code before editing. This repo has useful established domain
   services, but also some legacy areas that should not be copied blindly.
4. Keep ownership boundaries clear: controllers route and validate, services own
   workflows, models expose relationships and small domain helpers, frontend pages
   orchestrate, components render focused UI, composables own reusable reactive
   state, utilities stay pure.
5. Treat permissions, audit logs, notifications, guest access tokens, and roster
   state as high-risk areas. Changes there need tests or a clear reason why tests
   were not run.
6. Do not hide uncertainty. If a change touches behavior that depends on queues,
   Reverb, FF Logs, Lodestone, XIVAuth, Discord, or mail delivery, call out what
   was and was not verified.
7. Keep generated reports, assessments, walkthroughs, and explanatory artifacts
   outside the repository. Do not create or track them in `docs/` or elsewhere in
   the repo unless the user explicitly requests repository documentation. Requests
   for explanations or reports alone are not permission to add documentation files
   to the repository.

## Common Commands

Install dependencies:

```bash
composer install
npm install
```

Run the standard local stack:

```bash
composer run dev
```

Run the Laravel Herd oriented stack on Windows:

```bash
composer run dev:herd
```

Build frontend assets:

```bash
npm run build
```

Run the default backend suite, which uses SQLite in memory:

```bash
php artisan test
```

Run the PostgreSQL-targeted suite:

```bash
php vendor/bin/pest --configuration=phpunit.pgsql.xml
```

Format PHP when PHP files change:

```bash
vendor/bin/pint
```

Useful Laravel commands:

```bash
php artisan migrate
php artisan config:clear
php artisan route:list
```

Notes:

- The repository currently has no configured npm lint, typecheck, or frontend test
  scripts. Use `npm run build` for frontend validation unless the user asks you to
  add a proper frontend quality tool.
- `composer run dev:herd` starts queue, scheduler, Reverb, and Vite for Herd users.
  It does not start a Laravel HTTP server because Herd handles that.
- Do not commit generated `public/build` output unless the user explicitly asks
  for production assets.

## Project Layout

Important backend locations:

```text
app/
  Console/Commands/
  DTOs/
  Events/
  Http/Controllers/
  Http/Middleware/
  Http/Requests/
  Jobs/
  Mail/
  Models/
  Policies/
  Services/
  Support/
database/
  factories/
  migrations/
  seeders/
routes/
  web.php
  channels.php
  console.php
tests/
  Feature/
  Unit/
```

Important frontend locations:

```text
resources/js/
  Pages/
  Layouts/
  components/
  composables/
  utils/
resources/css/
lang/
  en/
  de/
  fr/
  ja/
```

The current root frontend folders use mixed casing: `Pages` and `Layouts` are
PascalCase, while `components`, `composables`, and `utils` are lower-case. Do not
perform casing-only moves casually, especially on Windows. New subfolders inside
`components` should still use clear domain names such as `Groups`, `Admin`,
`Navigation`, or `Characters`.

## Backend Architecture

### Controllers

Controllers should be thin orchestration layers.

Use controllers for:

- authorization checks
- request validation
- route-level branching
- calling domain services
- returning redirects, JSON, streamed responses, or Inertia pages

Avoid adding more workflow logic to already-large controllers. In particular,
prefer extracting new domain behavior instead of growing:

- `app/Http/Controllers/ActivityTypeController.php`
- `app/Http/Controllers/GroupActivityApplicationController.php`
- `app/Http/Controllers/CharacterController.php`
- `app/Http/Controllers/GroupActivityController.php`

If validation grows beyond simple route-level rules, introduce or update a
`FormRequest`. If a controller method needs to mutate multiple models or emit
audit, notification, or realtime side effects, put that workflow in a service.

### Services

Services own multi-step business behavior. Reuse the domain services that already
exist before adding new ones.

Important examples:

- `app/Services/Groups/ActivitySlotAssignmentService.php` owns assignment,
  reassignment, bench movement side effects, active assignment snapshots, audit,
  and placement notifications.
- `app/Services/Groups/ActivitySlotAttendanceService.php` owns check-in, late,
  missing, and attendance snapshot behavior.
- `app/Services/Groups/ActivitySlotDesignationService.php` owns host and raid
  leader designation rules.
- `app/Services/Groups/ActivityCompletionService.php` and
  `ActivityCancellationService.php` own completion and cancellation outcomes.
- `app/Services/Groups/ApplicantQueue/*` builds and presents applicant queue
  payloads.
- `app/Services/Notifications/*` owns notification events, inbox records,
  delivery records, rendering, dispatch, and realtime notification updates.
- `app/Services/Lodestone/*` and `app/Services/FFLogs/*` own third-party parsing
  and progress fetching.

Rules:

- Use `DB::transaction()` around workflows that must update several related rows.
- Prefer explicit service methods such as `assignFromApplication()`,
  `markMissing()`, or `broadcastPatch()` over ad hoc model mutation in controllers.
- Throw `ValidationException::withMessages()` for domain validation failures that
  should return field errors to the UI.
- Keep service dependencies injected through constructors.
- Keep services cohesive. Do not create a giant generic "manager" service.

### Models

Models should expose relationships, casts, fillable fields, constants, scopes, and
small domain helpers.

Existing conventions to keep:

- Activity statuses are constants on `Activity`.
- Application statuses are constants on `ActivityApplication`.
- Relationships should be typed with Laravel relation return types where practical.
- Route model binding for groups uses the slug via `Group::getRouteKeyName()`.
- JSON-like columns are cast to arrays where the UI expects structured payloads.

Avoid placing large workflows in models. If behavior touches several model types,
it belongs in a service.

### DTOs And Support Classes

Use `final readonly` DTOs for immutable parsed or transported data when appropriate,
as seen in `app/DTOs`.

Use `app/Support` for small enum-like constants and support objects such as audit
and notification categories. Keep these classes stateless.

### Authorization

Authorization is not optional.

Current patterns:

- Group dashboard access is enforced by `group.dashboard.access` middleware and
  model helpers such as `Group::hasModeratorAccess()`.
- Activity management endpoints should use `GroupActivityPolicy::manageDashboard`
  when they operate on a specific activity and group.
- Admin controllers currently use private `authorizeAdminAccess()` helpers that
  check `auth()->user()?->is_admin`.
- Pulse access is controlled by the `viewPulse` gate in `AppServiceProvider`.
- Mismatched group/activity pairs should generally deny as not found rather than
  leak object existence.

When adding a route:

- Put it in the narrowest existing middleware group that fits.
- Preserve `{group:slug}` route model binding for group-facing URLs.
- Use the existing route name style, for example
  `groups.dashboard.activities.slot-assignments.store`.
- Add explicit route parameter constraints for secret tokens and access tokens
  when the surrounding routes use them.
- Do not add admin routes without an admin authorization check.

### Database And Migrations

Production intent is PostgreSQL. The default local test suite uses SQLite in
memory, and CI runs both SQLite and PostgreSQL.

Rules:

- Write migrations that are safe for PostgreSQL and do not break SQLite tests
  unless the PostgreSQL-specific behavior is intentionally isolated and covered by
  `phpunit.pgsql.xml`.
- Keep data-preserving migrations data-preserving. Do not drop or rewrite user
  data casually.
- Use clear indexes for lookup-heavy paths such as tokens, status filters,
  memberships, notification delivery, and activity relations.
- Keep factories updated when adding required columns.
- Update seeders only when sample/dev data genuinely needs the new field.

### Audit, Notifications, And Realtime

Several product workflows have side effects beyond database writes.

Before changing a workflow, check whether it should also:

- create an audit log through `AuditLogger` or `GroupActivityAuditService`
- create a notification event
- create in-app notifications
- create off-site notification deliveries
- dispatch a queue job
- broadcast a Reverb event
- update the management dashboard via `ActivityManagementRealtimeService`

Do not duplicate notification rendering or preference logic in controllers or
frontend components. Use the notification services and translation keys.

### External Services

The app integrates with Lodestone, FF Logs, Google, Discord, XIVAuth, Postmark,
Reverb, and Pulse.

Rules:

- Do not call external services from tests without faking HTTP or isolating the
  integration.
- Keep credentials in environment variables and `config/services.php`; never commit
  secrets.
- For scrapers and parsers, keep parsed data separate from persistence. The
  Lodestone DTO/service pattern already models this boundary.
- When external data is optional or can fail, preserve graceful degradation paths.

## Frontend Architecture

### Pages

Pages are route entry and orchestration layers.

Pages should:

- receive Inertia props
- initialize page-level composables
- connect child components together
- own route-level loading and navigation concerns
- pass normalized data down to focused components

Pages should not:

- hold large business workflows
- duplicate backend payload normalization in multiple places
- define shared domain types inline
- become the long-term home for modal, queue, roster, or realtime workflow logic

`resources/js/Pages/Dashboard/Groups/Activities/Show.vue` is already a major
hotspot. Do not add more non-trivial workflow logic there unless the requested
change is tiny. Prefer extracting to composables, domain type files, or focused
activity components.

### Components

Components should have one primary responsibility.

Good component responsibilities:

- render a panel
- render a list
- render a row or card
- manage a focused modal
- manage a focused picker or menu
- present one area of a workflow

Avoid components that simultaneously fetch data, mutate domain state, decide
business rules, and render a large UI surface.

Use domain folders under `resources/js/components`:

```text
components/
  Admin/
  Audit/
  Characters/
  Groups/
    Activities/
  Navigation/
  Settings/
```

Do not create new `components/Pages/...` areas. That folder exists as legacy
structure. If page-specific UI needs extraction, put it in the nearest domain
folder, not a pseudo-page bucket.

Name files by responsibility, not position:

- Good: `RosterSummaryPanel.vue`, `ApplicantQueueItem.vue`,
  `ManualAssignCharacterToSlotModal.vue`, `GroupMemberRow.vue`
- Bad: `TopLeftBox.vue`, `Wrapper.vue`, `ThingPanel.vue`

Useful suffixes:

- `Panel`
- `Card`
- `Row`
- `Item`
- `List`
- `Modal`
- `Form`
- `Section`
- `Menu`
- `Picker`

When a feature appears in several domains or page surfaces, prefer extracting a
shared UI component into a neutral folder such as `components/Shared/...`
instead of leaving it under the first domain where it happened to be built.

For repeated detail workflows in lists and tables:

- prefer one focused detail surface per page or page-level area, not one modal
  instance per row
- keep row payloads lightweight and fetch full detail payloads on demand when
  the focused surface opens
- let child rows or tables render simple trigger components, while the page owns
  the singleton modal, drawer, or overlay
- keep focused modal components mostly presentational and event-oriented; do not
  let them become the long-term home for workflow state and CRUD behavior

For repeated confirmation workflows:

- prefer a shared confirmation component over many bespoke confirmation modals
- when the app already supports it, prefer programmatic overlays for
  create/open/confirm/close flows instead of leaving dormant modal instances in
  large page templates
- keep extra confirmation inputs prop-driven when that makes the overlay API
  cleaner than slot-heavy page wiring

### Composables

Use composables for shared reactive or stateful logic.

Good composable use cases:

- Inertia page state that is reused across pages
- realtime subscriptions
- polling or refresh loops
- modal orchestration
- reusable form workflows
- derived interaction state

Do not use composables as dumping grounds. A composable should expose a small,
clear API.

When a reusable workflow spans several child components, the composable should
usually own:

- async loading state
- form state
- mutation actions
- transient UI workflow state
- reload behavior after mutations

Prefer explicit, command-driven lifecycle methods such as `open...()`,
`close...()`, `reload...()`, or `confirm...()` when a modal or overlay flow can
be expressed directly. Avoid watcher-heavy synchronization when the same flow can
be modeled with clear commands.

When passing workflow behavior into child tables, lists, or cards, prefer a
narrow controller object such as `notes`, `moderation`, or `selection` over a
long list of individual props. Child components should depend on a coherent
feature controller, not a large exploded prop surface.

### Utilities

Utilities in `resources/js/utils` must be pure and stateless.

Utilities:

- take input
- return output
- do not know about Vue refs
- do not mutate page-local state
- do not call Inertia router, axios, Reverb, or browser APIs unless the file is
  explicitly a browser utility

If logic is reactive or stateful, use a composable instead.

### Types

The current codebase has many inline `.vue` type declarations and several
near-feature type files inside `components/Groups/Activities`. Treat that as
legacy debt, not a pattern to expand.

Rules for new work:

- Put shared frontend domain types in dedicated type files.
- Prefer creating `resources/js/Types/<Domain>.ts` for cross-page or cross-component
  payloads.
- For shared UI/workflow contracts, prefer dedicated shared type files such as
  `resources/js/Types/Shared.ts` or a well-named domain type file instead of
  declaring those contracts inside a composable or `.vue` file.
- Near-feature type files are acceptable only when the type is genuinely local to
  that feature folder.
- Do not duplicate the same payload type across several `.vue` files.
- Avoid declaring large object payload types inside `.vue` files. Move them before
  adding more fields.

### Inertia, Routing, And Requests

Existing frontend conventions:

- Inertia pages are resolved from `resources/js/Pages`.
- Most pages use `DefaultLayout` automatically through `resources/js/app.js`.
- Use `router` from `@inertiajs/vue3` for Inertia navigation and form posts.
- Use `axios` for JSON endpoints and polling.
- Use `route()` from `ziggy-js` for named Laravel routes.
- Use `preserveScroll` and `preserveState` intentionally for dashboard actions.

Avoid hard-coded URLs when a named route exists.

### UI And Styling

The UI stack is Nuxt UI 4, Tailwind CSS 4, Vue I18n, and Lucide icon names via
Nuxt UI icon strings such as `i-lucide-bell`.

Rules:

- Prefer Nuxt UI primitives (`UButton`, `UModal`, `UCard`, `UTable`, `UBadge`,
  `UDropdownMenu`, `UPopover`, `UForm`, etc.) over custom controls.
- Use existing brand, neutral, success, info, warning, and error color semantics.
- The Vite Nuxt UI theme config intentionally sets many controls to
  `rounded-none`; do not introduce a competing rounded visual language casually.
- Use icons for common icon-button actions.
- Do not add decorative UI that makes dashboards harder to scan.
- Keep operational screens dense, predictable, and easy to repeat-use.
- Do not introduce a marketing landing-page pattern into dashboard workflows.

### Localization

User-visible strings should be localized.

Rules:

- Add keys under every supported locale: `lang/en`, `lang/de`, `lang/fr`, and
  `lang/ja`.
- Use `useI18n()` and `t()` in Vue components.
- Use existing translation namespaces such as `groups.activities`,
  `notifications`, `admin.*`, `settings`, and `auth`.
- Flash messages should use stable keys, not long English sentences.
- When touching an area with hard-coded English labels, prefer moving those labels
  to locale files as part of the same focused change.

### Realtime Frontend

Realtime updates use Laravel Echo and Reverb.

Rules:

- Check that the user is authenticated before subscribing to private channels.
- Store subscribed channel names and leave channels on component unmount.
- Keep polling fallbacks where they already exist, especially notification summary
  refresh behavior.
- Do not assume Reverb is always running locally. UI should still work after a
  page refresh or manual fetch.

## Product Workflow Rules

### Groups And Membership

Groups are identified publicly by slug. Ownership, moderator access, membership,
bans, invites, follows, and member notes all have access rules.

When changing group behavior:

- check owner versus moderator versus member permissions
- preserve ban behavior
- preserve audit logging for moderation actions
- preserve notification behavior for group updates where applicable

### Activities, Applications, Rosters, And Bench

This is the highest-risk domain in the app.

Important concepts:

- An `Activity` belongs to a `Group` and an `ActivityTypeVersion`.
- Activity types define slot schema, application schema, layout groups, progress
  schema, bench size, FF Logs zone IDs, and roster summary presets.
- `ActivitySlot` records current roster position state.
- `ActivitySlotAssignment` records assignment history and attendance state.
- `ActivityApplication` records application review state.
- Guest applications use access tokens and optional secret keys.

Rules:

- Do not directly update slot assignment state from a controller or component if
  a service already owns that workflow.
- Preserve expected slot state token checks in roster mutation endpoints.
- Preserve bench versus main-slot differences.
- Preserve displaced applicant behavior when swapping or replacing assignments.
- Preserve audit logs and assignment notifications for roster changes.
- Keep public, guest, and dashboard views consistent after status changes.

### Notifications

Notification categories, channels, preferences, in-app records, delivery records,
emails, Discord delivery placeholders, and realtime inbox updates are connected.

Rules:

- Use `NotificationCategory` and `NotificationChannel` support classes for valid
  values.
- Use `NotificationMessageRenderer` and locale keys for notification text.
- Use queue fakes in tests when asserting queued delivery.
- Use event fakes when asserting Reverb/inbox update events.
- Do not bypass notification preferences unless the event is mandatory.

### Legal Pages

The legal pages depend on environment-configured controller/contact values.
Do not make bundled legal text more generic or definitive without checking the
self-hosting notes in `README.md` and `LocalDevelopment.md`.

## Testing Expectations

Backend behavior changes should usually have Pest coverage.

Existing test conventions:

- Feature tests use `uses(RefreshDatabase::class);` in each file.
- Factories live in `database/factories`.
- Tests use Laravel helpers such as `$this->actingAs()`, `route()`,
  `assertDatabaseHas()`, `Queue::fake()`, and `Event::fake()`.
- Shared Pest helpers live in `tests/Pest.php`.
- Default test config uses SQLite, sync queues, array mail, array session, and
  disabled broadcasting.
- CI runs both SQLite and PostgreSQL suites.

What to run:

- For backend-only logic, run the specific Pest test file or filtered test first.
- For migrations, database constraints, query behavior, or JSON/index behavior,
  run the PostgreSQL config as well.
- For frontend changes, run `npm run build`.
- For cross-stack Inertia changes, run relevant backend tests plus `npm run build`.
- If no relevant test exists and the change is risky, add one.

Do not claim tests passed unless you actually ran them.

## Current Architecture Debt And Cleanup Warnings

These are known project issues. They are not permission to perform a broad cleanup
in an unrelated task, but agents should avoid making them worse.

- `resources/js/Pages/Dashboard/Groups/Activities/Show.vue` is about 1,500 lines.
  Extract new roster, queue, modal, realtime, and type logic instead of growing it.
- Several Vue components are over 500 lines, including character definitions,
  roster slot cards, applicant queue details, and activity application forms.
  Keep new component responsibilities smaller.
- `ActivityTypeController`, `GroupActivityApplicationController`,
  `CharacterController`, and `GroupActivityController` are large. New substantial
  backend workflows should move into services or form requests.
- `routes/web.php` is large and has inconsistent indentation. Add routes cleanly
  in the right group, but do not restructure the full route file unless asked.
- Some PHP files use tabs despite `.editorconfig` requiring 4 spaces. New or
  touched code should use spaces.
- Many `.vue` files declare local payload types inline. Do not add new large
  inline type blocks; move shared payloads to type files when touched.
- `components/Pages/...` is legacy structure. Do not add to it.
- Some components still contain `console.log()` debugging calls. Do not add new
  console debugging, and remove existing logs when touching the same focused area.
- Some request/controller methods still have TODO stubs. Do not build new code on
  top of stubbed behavior without completing the relevant path.
- Some UI strings are still hard-coded English. New user-visible strings should
  use Vue I18n and locale files.
- There is no frontend lint/typecheck/test setup. Avoid pretending one exists;
  recommend or add it only when the task calls for it.

## Refactor Policy

Refactors are welcome when they directly support the requested change.

Good refactors:

- extracting a service from a controller method you are modifying
- moving repeated payload presentation into a serializer or builder
- moving repeated reactive state into a composable
- moving shared frontend payload types into a type file
- replacing duplicated template logic with computed values
- tightening authorization around a touched endpoint

Bad refactors:

- renaming folders for casing or aesthetics during an unrelated fix
- moving many routes without changing behavior
- redesigning UI while fixing backend behavior
- converting established service APIs to a new abstraction without clear benefit
- adding generalized helpers that hide domain intent
- touching locale files broadly without checking all languages

When extracting from a large file, keep the extraction narrow and preserve the
observable behavior first. Leave the codebase more consistent than you found it,
not merely different.

## Code Style

PHP:

- Follow Laravel conventions and run Pint after PHP edits when practical.
- Use typed method signatures and return types where possible.
- Use constructor property promotion for service dependencies when it matches
  surrounding code.
- Prefer named arguments for long service or audit calls.
- Keep comments focused on why something exists, not what obvious syntax does.
- Do not leave `dd()`, `dump()`, `ray()`, temporary logs, or TODO placeholders in
  completed work.

JavaScript and Vue:

- Use `<script setup lang="ts">` for Vue SFCs.
- Use explicit props and emits.
- Keep computed display logic readable and move business policy out of templates.
- Prefer `const` and small pure helpers.
- Avoid mutating props.
- Clean up event listeners, intervals, and Echo subscriptions on unmount.
- Do not duplicate backend constants in many components. If frontend status helpers
  are needed, keep them centralized in `resources/js/utils` or type files.

Markdown and docs:

- Keep docs accurate to the current commands and stack.
- Do not document commands that do not exist.
- Prefer concise, actionable sections with exact paths and commands.

## Import Boundaries

Backend:

- Controllers may depend on requests, models, services, support classes, and
  framework facades.
- Services may depend on models, other focused services, jobs, events, support
  classes, and framework facilities.
- Support classes and DTOs should not depend on controllers.
- Avoid circular service dependencies.

Frontend:

- Pages may import components, composables, utils, and types.
- Components may import smaller components, composables, utils, and types.
- Composables may import utils and types.
- Utils should not import Vue runtime state, Inertia router, or components.
- Type files must not import Vue components.
- Cross-domain imports should be intentional. Shared UI belongs in a neutral
  location rather than under an unrelated product domain.

## Final Check Before Handing Back

Before finishing a task, check the relevant items:

- `git diff --stat` is scoped to the request.
- No unrelated user changes were reverted.
- No temporary debug output remains.
- Backend behavior changes have targeted Pest coverage or a clear explanation.
- Frontend changes build with `npm run build`, unless not run for a stated reason.
- Migrations were considered against both SQLite tests and PostgreSQL intent.
- Permissions, audit logs, notifications, queues, and realtime updates were
  considered for touched workflows.
- Locale keys were added for user-visible text.
- The final response says exactly what changed and what was verified.
