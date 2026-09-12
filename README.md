# CRM API

A JSON API for a small slice of a CRM. Managers and sales reps work leads, log activities against them and read a per-rep performance report.

Laravel 12 · PHP 8.2 · MySQL 8.0 · Sanctum tokens · database queue

## Contents

- [Setup](#setup)
- [Running tests](#running-tests)
- [API reference](#api-reference)
- [Design notes](#design-notes)
- [Assumptions](#assumptions)
- [Trade-off](#trade-off)
- [What I'd do with more time](#what-id-do-with-more-time)
- [Bonus features](#bonus-features)

## Setup

### Docker

Requires Docker with Compose v2.

```bash
docker compose up --build
```

The API is at `http://localhost:8000` once the `app` container is healthy. The first start installs Composer packages, so it takes a few minutes.

| Service | Role |
|---|---|
| `mysql` | MySQL 8.0 with the `crm_api` database. Not published to the host, so it doesn't clash with a local MySQL. |
| `app` | Installs packages, creates `.env` from `.env.example` if it's missing, generates `APP_KEY`, migrates, seeds on the first run, then serves on port 8000. |
| `queue` | Runs `php artisan queue:work` for the assignment notification job. |

To start again from an empty database, run `docker compose down -v`, then `docker compose up --build`.

### Local

Requires PHP 8.2+ with `pdo_mysql` (and `pdo_sqlite` for the tests), Composer 2 and MySQL 8.0.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

`.env.example` points at MySQL on `127.0.0.1:3306`, database `crm_api`, user `root` with an empty password. Change `DB_USERNAME` and `DB_PASSWORD` in `.env` if yours differ, then:

```bash
mysql -u root -p -e "CREATE DATABASE crm_api"
php artisan migrate --seed
php artisan serve
```

In a second terminal, start the queue worker. The assignment notification is queued on the `database` connection.

```bash
php artisan queue:work
```

The API is at `http://localhost:8000`. `php artisan migrate:fresh --seed` resets the data.

### Seeded accounts

| Role | Name | Email | Password |
|---|---|---|---|
| manager | Morgan Manager | manager@example.com | password |
| rep | Rep 1 | rep1@example.com | password |
| rep | Rep 2 | rep2@example.com | password |
| rep | Rep 3 | rep3@example.com | password |

The seeder also creates 36 leads split evenly across the reps (every status and source appears), 4 unassigned leads, and 0–5 activities per assigned lead. Every won or lost lead has at least one activity.

## Running tests

```bash
php artisan test
```

The tests use SQLite in memory (set in `phpunit.xml`), so they need no MySQL. There is one feature test file per area: data model, auth, JSON error handling, the policy matrix, each lead endpoint, assignment, activities, the report, the seeder and the queued job. They are built with factories and assert status codes, JSON and database state.

In Docker (still on SQLite, so the seeded MySQL data is untouched):

```bash
docker compose exec app php artisan test
```

## API reference

Base URL: `http://localhost:8000/api`. Send `Accept: application/json`, and `Content-Type: application/json` with a body. Every endpoint except login needs `Authorization: Bearer <token>`.

| Method | URI | Who | Success |
|---|---|---|---|
| POST | [`/api/login`](#post-apilogin) | anyone | 200 |
| GET | [`/api/leads`](#get-apileads) | any user; reps see only their own leads | 200 |
| POST | [`/api/leads`](#post-apileads) | any user | 201 |
| GET | [`/api/leads/{lead}`](#get-apileadslead) | manager or assigned rep | 200 |
| PATCH | [`/api/leads/{lead}`](#patch-apileadslead) | manager or assigned rep | 200 |
| POST | [`/api/leads/{lead}/assign`](#post-apileadsleadassign) | manager | 200 |
| POST | [`/api/leads/{lead}/activities`](#post-apileadsleadactivities) | manager or assigned rep | 201 |
| GET | [`/api/reports/rep-performance`](#get-apireportsrep-performance) | any user; reps see only their own row | 200 |

With curl (bash):

```bash
curl -s -X POST http://localhost:8000/api/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"manager@example.com","password":"password"}'

curl -s "http://localhost:8000/api/leads?status=qualified" \
  -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

Every lead response has `id`, `name`, `email`, `phone`, `company`, `source`, `status`, `expected_value` (a string with 2 decimals), `assigned_rep` (a user or `null`), `created_at` and `updated_at` (ISO 8601). Only the show endpoint adds `activities`.

### POST /api/login

Auth: none. Limited to 5 attempts per minute per email + IP address, then `429`.

| Body | Rules |
|---|---|
| `email` | required, email |
| `password` | required |

`200 OK`

```json
{
  "data": {
    "token": "1|QiOQbRBaeQcp3xvKeda7LFYl5QYyCjnu7Fk7ptUy60aa32bf",
    "token_type": "Bearer",
    "user": { "id": 1, "name": "Morgan Manager", "email": "manager@example.com", "role": "manager" }
  }
}
```

An unknown email and a wrong password both return `422` with `"The provided credentials are incorrect."`. Both also cost one password hash, so neither the response nor its timing reveals which emails exist.

### GET /api/leads

Auth: token. Managers get every lead, reps only the leads assigned to them. Filters narrow that set.

| Query | Values | Default |
|---|---|---|
| `status` | `new`, `contacted`, `qualified`, `won`, `lost` | |
| `source` | `web`, `referral`, `cold_call`, `event`, `other` | |
| `assigned_to` | a user id, or `unassigned` | |
| `search` | substring of name, email or company; max 100 chars | |
| `sort` | `created_at`, `expected_value` | `created_at` |
| `direction` | `asc`, `desc` | `desc` |
| `per_page` | 1–100 | 15 |
| `page` | page number | 1 |

`200 OK` for `?status=qualified&sort=expected_value&per_page=2` (second item and `meta.links` left out):

```json
{
  "data": [
    {
      "id": 11,
      "name": "Reynold Bode",
      "email": "dcollins@example.net",
      "phone": "+1 327-835-8837",
      "company": null,
      "source": "cold_call",
      "status": "qualified",
      "expected_value": "47500.00",
      "assigned_rep": { "id": 3, "name": "Rep 2", "email": "rep2@example.com", "role": "rep" },
      "created_at": "2026-06-23T08:22:12+00:00",
      "updated_at": "2026-09-11T19:41:49+00:00"
    }
  ],
  "links": {
    "first": "http://localhost:8000/api/leads?status=qualified&sort=expected_value&per_page=2&page=1",
    "last": "http://localhost:8000/api/leads?status=qualified&sort=expected_value&per_page=2&page=4",
    "prev": null,
    "next": "http://localhost:8000/api/leads?status=qualified&sort=expected_value&per_page=2&page=2"
  },
  "meta": { "current_page": 1, "from": 1, "last_page": 4, "path": "http://localhost:8000/api/leads", "per_page": 2, "to": 2, "total": 7 }
}
```

An invalid value for any parameter returns `422`, e.g. `"The sort field must be one of: created_at, expected_value."`.

### POST /api/leads

Auth: token.

| Body | Rules |
|---|---|
| `name` | required, max 255 |
| `email` | required, email, max 255 |
| `phone` | required, max 30 |
| `company` | optional, max 255 |
| `source` | required, one of the sources above |
| `status` | optional: `new`, `contacted` or `qualified` (default `new`). `won` and `lost` are rejected. |
| `expected_value` | required, number from 0 to 9999999999.99, at most 2 decimals |
| `assigned_to` | Managers only: optional id of a rep. Without it, a manager's lead is unassigned. A rep's lead is always assigned to that rep, and a rep who sends `assigned_to` gets `422 "Only managers can assign leads."` |

`201 Created`, as rep1:

```json
{
  "data": {
    "id": 41,
    "name": "Ada Lovelace",
    "email": "ada@example.com",
    "phone": "+44 20 7946 0000",
    "company": "Analytical Engines Ltd",
    "source": "referral",
    "status": "new",
    "expected_value": "12500.00",
    "assigned_rep": { "id": 2, "name": "Rep 1", "email": "rep1@example.com", "role": "rep" },
    "created_at": "2026-09-11T19:42:56+00:00",
    "updated_at": "2026-09-11T19:42:56+00:00"
  }
}
```

### GET /api/leads/{lead}

Auth: token; manager or the assigned rep. Returns the lead with its assigned rep and its activities, newest `occurred_at` first.

`200 OK`

```json
{
  "data": {
    "id": 41,
    "name": "Ada Lovelace",
    "email": "ada@example.com",
    "phone": "+44 20 7946 0000",
    "company": "Analytical Engines Ltd",
    "source": "referral",
    "status": "won",
    "expected_value": "15000.50",
    "assigned_rep": { "id": 2, "name": "Rep 1", "email": "rep1@example.com", "role": "rep" },
    "activities": [
      {
        "id": 111,
        "type": "call",
        "body": "Intro call, wants a proposal by Friday.",
        "occurred_at": "2026-09-10T14:30:00+00:00",
        "logged_by": { "id": 2, "name": "Rep 1", "email": "rep1@example.com", "role": "rep" },
        "created_at": "2026-09-11T19:43:27+00:00"
      }
    ],
    "created_at": "2026-09-11T19:42:56+00:00",
    "updated_at": "2026-09-11T19:43:27+00:00"
  }
}
```

### PATCH /api/leads/{lead}

Auth: token; manager or the assigned rep. Send any subset of `name`, `email`, `phone`, `company`, `source`, `status` and `expected_value`, with the same rules as create. Setting `status` to `won` or `lost` needs at least one logged activity. `assigned_to` is rejected with `422`; use the assign endpoint instead.

`200 OK` with the updated lead, in the same shape as create.

### POST /api/leads/{lead}/assign

Auth: token; managers only.

| Body | Rules |
|---|---|
| `assigned_to` | required, id of a user with role `rep` (otherwise `422 "The selected user is not a sales rep."`) |

`200 OK` with the lead and its new `assigned_rep`. When the assignee changes, a `NotifyRepOfLeadAssignment` job is queued; reassigning a lead to its current rep queues nothing.

### POST /api/leads/{lead}/activities

Auth: token; manager or the assigned rep. The author (`logged_by`) is always the authenticated user.

| Body | Rules |
|---|---|
| `type` | required: `call`, `email`, `meeting` or `note` |
| `body` | required, max 5000 chars |
| `occurred_at` | optional ISO 8601 date, not in the future; defaults to now. A value with an offset is stored in UTC. |

`201 Created`

```json
{
  "data": {
    "id": 111,
    "type": "call",
    "body": "Intro call, wants a proposal by Friday.",
    "occurred_at": "2026-09-10T14:30:00+00:00",
    "logged_by": { "id": 2, "name": "Rep 1", "email": "rep1@example.com", "role": "rep" },
    "created_at": "2026-09-11T19:43:27+00:00"
  }
}
```

### GET /api/reports/rep-performance

Auth: token. No parameters. A manager gets one row per rep, ordered by name. A rep gets a one-row array with their own figures.

`200 OK`

```json
{
  "data": [
    {
      "rep": { "id": 2, "name": "Rep 1" },
      "total_leads": 12,
      "status_counts": { "new": 2, "contacted": 2, "qualified": 3, "won": 4, "lost": 1 },
      "total_expected_value": "308000.00",
      "won_expected_value": "106500.00",
      "activity_count": 35
    }
  ]
}
```

A rep with no leads or activities appears with zeros. Unassigned leads are not counted.

### Errors

Every error is JSON with a `message`. A `422` also has `errors`, keyed by field.

| Status | When | Body |
|---|---|---|
| 401 | Missing or invalid token | `{"message": "Unauthenticated."}` |
| 403 | A rep views, updates or logs an activity on a lead that isn't assigned to them | `{"message": "This lead is not assigned to you."}` |
| 403 | A rep calls the assign endpoint | `{"message": "Only managers can assign leads."}` |
| 404 | Unknown lead id | `{"message": "Resource not found."}` |
| 422 | Validation failed | see below |
| 429 | Too many login attempts | `{"message": "Too Many Attempts."}` |

Marking a lead won or lost before any activity is logged:

```json
{
  "message": "A lead can only be marked won or lost after at least one activity has been logged.",
  "errors": {
    "status": ["A lead can only be marked won or lost after at least one activity has been logged."]
  }
}
```

Several invalid fields at once (`message` is the first error plus a count):

```json
{
  "message": "The name field is required. (and 4 more errors)",
  "errors": {
    "name": ["The name field is required."],
    "email": ["The email field must be a valid email address."],
    "phone": ["The phone field is required."],
    "source": ["The source must be one of: web, referral, cold_call, event, other."],
    "expected_value": ["The expected value must have at most 2 decimal places."]
  }
}
```

`.env.example` sets `APP_DEBUG=false`, so these bodies are all a client sees. With `APP_DEBUG=true`, 403 and 500 responses also include `exception`, `file`, `line` and `trace` keys. Server errors are logged in full to `storage/logs/laravel.log` either way.

## Design notes

### Project layout

```text
app/
  Enums/                  UserRole, LeadSource, LeadStatus, ActivityType
  Http/Controllers/Api/   AuthController, LeadController, LeadAssignmentController,
                          LeadActivityController, ReportController
  Http/Requests/          one Form Request per endpoint that takes input
  Http/Resources/         UserResource, LoginResource, LeadResource,
                          ActivityResource, RepPerformanceResource
  Jobs/                   NotifyRepOfLeadAssignment
  Models/                 User, Lead, Activity, including the visibleTo() scopes
  Policies/               LeadPolicy
  Queries/                RepPerformanceReport
database/                 migrations, factories, DatabaseSeeder
tests/Feature/            one test file per area
docker/entrypoint.sh      Docker start-up: install, migrate, seed, serve
```

Controllers stay thin: authorize, delegate, return an API Resource. Validation lives in Form Requests, role logic in `LeadPolicy` and the `visibleTo()` scopes. `Model::shouldBeStrict()` is on outside production, so a lazy load (an N+1) throws in development and tests.

### Data model

| Table | Columns | Notes |
|---|---|---|
| `users` | name, email (unique), password, role | `role` is `manager` or `rep`. |
| `leads` | name, email, phone, company, source, status, expected_value, assigned_to | `expected_value` is `decimal(12,2)`, never a float. `assigned_to` references users with `nullOnDelete`, so a deleted rep's leads become unassigned. |
| `activities` | lead_id, user_id, type, body, occurred_at | `lead_id` references leads with `cascadeOnDelete`. `user_id` references users with `restrictOnDelete`, so activity history is never lost. `occurred_at` is `datetime`, not `timestamp`, so MySQL never adds `ON UPDATE CURRENT_TIMESTAMP` to it. |

Role, source, status and activity type are string columns cast to PHP backed enums, not DB `ENUM`s. Adding a value needs no migration, and validation uses `Rule::enum`.

Indexes, and the query each one serves:

| Index | Serves |
|---|---|
| `leads (assigned_to, status, expected_value)` | The report groups leads by rep and counts/sums them by status and value, all from this index without reading rows. Its leading column also serves rep scoping (`WHERE assigned_to = ?`), the `unassigned` filter and the foreign key. |
| `leads (status)` | The list filtered by status. The paginator's `COUNT(*)` reads only this index; the page itself is read through `created_at`. It has only 5 values, so it helps most when the filtered status is a small share of the rows. |
| `leads (source)` | The list filtered by source, the same way as `status`. |
| `leads (created_at)` | The default list sort. |
| `leads (expected_value)` | The list sorted by value. |
| `activities (lead_id, occurred_at)` | The lead timeline on the show endpoint, the won/lost `exists()` check and the `lead_id` foreign key. |
| `activities (user_id)` | The report's activity count per rep, and the `user_id` foreign key. |
| `users (email)`, unique | Login lookup. |

`users.role` has no index. The report filters on it, but the table holds only one row per staff member.

### Authorization

Role logic lives in two places only:

- `LeadPolicy` decides what a user may do to one lead: `view`, `update` and `logActivity` (manager or the assigned rep), and `assign` (managers only). Each denial carries its message, which becomes the 403 body.
- `visibleTo()` query scopes decide which rows a user can see. `Lead::visibleTo()` serves the list: reps get `WHERE assigned_to = <their id>`. `User::visibleTo()` serves the report: managers get every rep, a rep gets only themselves. Filtering happens in SQL, never in PHP.

Form Requests call `Gate::inspect()` in `authorize()`, so authorization runs before validation; the show endpoint, which has no input, calls `Gate::authorize()`. Controllers contain no role checks. Setting `assigned_to` on create checks the same `assign` ability as the assign endpoint.

### Rep performance report

`App\Queries\RepPerformanceReport` builds a single SQL query. Leads and activities are aggregated in separate derived tables, then left-joined to users. Joining both tables directly would repeat each lead once per activity and inflate the sums. Simplified:

```sql
SELECT u.id, u.name,
       COALESCE(l.total_leads, 0), COALESCE(l.new_count, 0), ...,
       COALESCE(l.total_expected_value, 0), COALESCE(l.won_expected_value, 0),
       COALESCE(a.activity_count, 0)
FROM users u
LEFT JOIN (
    SELECT assigned_to, COUNT(*) AS total_leads,
           SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS new_count,  -- one per status
           SUM(expected_value) AS total_expected_value,
           SUM(CASE WHEN status = ? THEN expected_value ELSE 0 END) AS won_expected_value
    FROM leads
    WHERE assigned_to IN (<visible rep ids>)
    GROUP BY assigned_to
) l ON l.assigned_to = u.id
LEFT JOIN (
    SELECT user_id, COUNT(*) AS activity_count
    FROM activities
    WHERE user_id IN (<visible rep ids>)
    GROUP BY user_id
) a ON a.user_id = u.id
WHERE <User::visibleTo(viewer)>
ORDER BY u.name, u.id
```

Status values come from the `LeadStatus` enum as bound parameters, so adding a status adds a column without editing SQL.

**Measured** on MySQL 8.0.46 on a local Windows 11 machine, with 50 reps, 100,000 leads (10% unassigned) and 300,000 activities. Times are the median of 15 warm runs through `RepPerformanceReport`:

| Viewer | Rows returned | Time |
|---|---|---|
| Manager | 50 | 677 ms |
| Manager, same SQL with `IGNORE INDEX` on the covering index | 50 | 1,185 ms |
| Rep | 1 | 9 ms |

`EXPLAIN` for the manager's query: both derived tables are read from an index alone.

```text
select_type  table       type  key                                            Extra
DERIVED      leads       ref   leads_assigned_to_status_expected_value_index  Using index
DERIVED      activities  ref   activities_user_id_index                       Using index
```

`EXPLAIN ANALYZE` shows `Covering index lookup on leads` (1,801 entries per rep, 50 loops) and `Covering index lookup on activities` (6,000 per rep, 50 loops). Reading the entries is fast; most of the manager's time goes into aggregating them in temporary tables.

Why it scales:

- It is one query whether there are 3 reps or 300. `RepPerformanceReportTest` asserts this.
- Each table is aggregated once, from an index alone, with no table rows read.
- The cost follows the rows in view. A rep's request reads only their part of each index (9 ms above). A manager's request reads every assigned lead and every activity, so it grows linearly with the data; at 100,000 leads that is under a second, and caching (see below) is the next step past that.

Known limitation: SQLite, which the tests use, has no real DECIMAL type, so its sums are floats. MySQL returns exact decimals. The money columns are cast with `decimal:2` either way.

### The won/lost rule

`UpdateLeadRequest::after()` calls `Lead::hasActivities()`, which runs an `exists()` query on the `(lead_id, occurred_at)` index. The check runs only when `status` passed its own rules and is `won` or `lost`, and a failure is a `422` on the `status` field. Create never reaches the rule: `StoreLeadRequest` rejects `won` and `lost` outright, because a new lead has no activities.

## Assumptions

- **Reps are auto-assigned on create.** A lead a rep creates is assigned to them. Only managers may set `assigned_to`; a manager who leaves it out creates an unassigned lead.
- **No won/lost on create.** A new lead has no activities, so it could never pass the won/lost rule.
- **`activity_count` counts the activities a rep logged**, on any lead, including leads that have since been reassigned. It is not the number of activities on the rep's current leads.
- **403, not 404, for another rep's lead.** This reveals that the lead id exists, but gives the client a clear reason. Ids that don't exist return 404.
- **Lead email is not unique.** The same person can arrive from several campaigns.
- **Money is returned as a string** (`"12500.00"`) so no precision is lost in JSON. Input accepts a number or a numeric string.
- **Search is `LIKE '%term%'`** on name, email and company. It is case-insensitive, and `%` and `_` in the term are escaped. A leading wildcard can't use an index, so search scans the leads the user can see.
- **Assignment has its own endpoint.** `PATCH` rejects `assigned_to`, so every assignment goes through the manager-only check and the notification job.
- **Unassigned leads are visible to managers only** and are left out of the report.
- **Login answers an unknown email and a wrong password the same way**: the same message, and the same one password hash so the timing matches too. It is throttled per email + IP.
- **Timestamps are ISO 8601 in UTC.**

## Trade-off

**I compute the report live instead of keeping per-rep counters.**

The alternative was to store each rep's totals and update them on every write: a lead created, reassigned, or changing status or value, and every activity logged. Reading the report would then be almost free. But each of those write paths would have to adjust the counters correctly (reassignment moves totals between two reps), and one missed path would leave the report silently wrong, with no error to notice.

Computing the report live means the numbers always come straight from the leads and activities, and there is no extra write logic to get wrong. The price is read time. With 100,000 leads and 300,000 activities, a rep's report takes 9 ms and a manager's about 0.7 s, and the manager's grows with the data. For a report that is read occasionally rather than on every page load, I think that is the right cost to pay. If it stops being acceptable, I would cache the result and clear it on writes (see below) before reaching for counters.

## What I'd do with more time

- **Report caching with invalidation.** Cache the report per viewer and clear it from model events when a lead's status, value or assignee changes, or when an activity is logged.
- **Full-text search** (a MySQL `FULLTEXT` index, or Laravel Scout) instead of `LIKE '%term%'`, which can't use an index.
- **Status-change history.** Dispatch a `LeadStatusChanged` event on update, and have a listener record the old status, new status, user and time in a `lead_status_changes` table.
- **API versioning** under `/api/v1`, so breaking changes can ship as v2.
- **Soft deletes** on leads and activities, and deactivating users instead of deleting them.
- **Multi-tenancy**, sketched:
  - add `tenant_id` to `users`, `leads` and `activities`;
  - a global scope filters every query on the authenticated user's tenant and fills in `tenant_id` on create;
  - policies also check `$user->tenant_id === $lead->tenant_id`, and `exists` rules (such as "`assigned_to` must be a rep") are limited to the tenant;
  - composite indexes are prefixed with `tenant_id`, e.g. `(tenant_id, assigned_to, status, expected_value)`;
  - the report adds `tenant_id` to both subqueries and to the users filter, so it groups reps within one tenant.

## Bonus features

1. **Queued job on assignment** (`NotifyRepOfLeadAssignment`). It is dispatched only when the assignee changes, and only after the transaction commits. It has 3 tries with backoff and a `failed()` handler, and it is dropped if the lead or rep has been deleted before it runs. `Queue::fake()` makes it quick to test.
2. **One-command Docker setup.** A reviewer can run the API, MySQL and the queue worker with `docker compose up --build`, without installing PHP or MySQL.

I chose these two because each is small and builds on the core instead of adding new domain features. Together they cover background processing and a reproducible setup. Multi-tenancy, report caching and the event listener stay in the list above.
