# Implementation plan

## 1.1 Goal

A Laravel 12 JSON API for a small CRM. Users are managers or reps. Reps work Leads. Leads have Activities. Managers see a per-rep performance report. Auth uses Sanctum tokens. The reviewers weight data modelling, Eloquent, authorization, validation, API design, tests and code quality above feature count.

## 1.2 Tech decisions

| Area | Choice | Why |
|---|---|---|
| Framework | Laravel 12, PHP 8.2 | Required by the brief; the installed PHP is 8.2 |
| Database | MySQL 8.0 for dev; SQLite in-memory for tests | The brief allows MySQL; SQLite keeps tests fast, so raw SQL must work on both |
| Auth | Sanctum personal access tokens | Required by the brief |
| Tests | PHPUnit feature tests + factories | Laravel default |
| Queue | `database` driver | No extra infrastructure |
| Formatting | Laravel Pint | Consistent style |
| Eloquent strict mode | `Model::shouldBeStrict()` outside production | Lazy loading (N+1) and silently discarded attributes throw in dev and tests |

## 1.3 Folder layout

```text
app/
  Enums/                  UserRole, LeadSource, LeadStatus, ActivityType
  Models/                 User, Lead, Activity
  Policies/               LeadPolicy
  Queries/                RepPerformanceReport
  Jobs/                   NotifyRepOfLeadAssignment
  Http/Controllers/Api/   AuthController, LeadController,
                          LeadAssignmentController, LeadActivityController, ReportController
  Http/Requests/          LoginRequest, ListLeadsRequest, StoreLeadRequest,
                          UpdateLeadRequest, AssignLeadRequest, StoreActivityRequest
  Http/Resources/         UserResource, LoginResource, LeadResource,
                          ActivityResource, RepPerformanceResource
database/
  migrations/  factories/  seeders/
tests/Feature/            one test file per area (see 1.13)
```

## 1.4 Data model

### `users`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name, email, password | string | email unique |
| role | string, default `rep` | cast to `UserRole` enum |
| timestamps | | |

### `leads`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string | |
| email | string | not unique (the same person can come in from several campaigns) |
| phone | string(30) | |
| company | string, nullable | |
| source | string(20) | `LeadSource` enum |
| status | string(20), default `new` | `LeadStatus` enum |
| expected_value | **decimal(12,2)**, default 0 | money is never float |
| assigned_to | FK → users, nullable, `nullOnDelete` | a deleted rep's leads become unassigned |
| timestamps | | |

Indexes on `leads`:

| Index | Query it serves |
|---|---|
| `(assigned_to, status, expected_value)` | Report: GROUP BY rep, count/sum by status. It covers the query, so rows aren't read. It also serves rep scoping and the FK. |
| `status` | Manager filter by status |
| `source` | Manager filter by source |
| `created_at` | Default sort |
| `expected_value` | Sort by value |

The single-column `status` and `source` indexes are low-cardinality (5 values each). Keep or drop them based on the real EXPLAIN output in Step 8/9.

### `activities`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| lead_id | FK → leads, `cascadeOnDelete` | a lead's activities go with it |
| user_id | FK → users, `restrictOnDelete` | who logged it; history is kept, so users are deactivated rather than deleted |
| type | string(20) | `ActivityType` enum |
| body | text | |
| occurred_at | datetime | not `timestamp`: MariaDB/older MySQL add `ON UPDATE CURRENT_TIMESTAMP` to the first NOT NULL timestamp column, which would rewrite it on every update |
| timestamps | | |

Indexes on `activities`:

| Index | Query it serves |
|---|---|
| `(lead_id, occurred_at)` | Lead timeline (show endpoint), the won/lost `exists()` check, the FK |
| `user_id` | Report activity count per rep, the FK |

Enums are stored as **string columns plus PHP backed enums**, not DB `ENUM`. Adding a value then needs no schema migration, and validation uses `Rule::enum`.

## 1.5 Enums

- `UserRole`: manager, rep
- `LeadSource`: web, referral, cold_call, event, other
- `LeadStatus`: new, contacted, qualified, won, lost. `isClosed()` returns true for won/lost.
- `ActivityType`: call, email, meeting, note

## 1.6 Relationships

- `User` hasMany `assignedLeads` (via `assigned_to`), hasMany `activities`
- `Lead` belongsTo `assignedRep` (User, via `assigned_to`), hasMany `activities`
- `Activity` belongsTo `lead`, belongsTo `user`

## 1.7 Authorization matrix

All role logic lives in `LeadPolicy` and the `visibleTo()` query scopes. Controllers contain no role checks.

| Ability | Manager | Rep, own lead | Rep, other or unassigned lead |
|---|---|---|---|
| viewAny (list) | all leads | only own (scoped query) | not in results |
| view | ✅ | ✅ | 403 |
| create | ✅ (may set assigned_to) | ✅ (auto-assigned to self) | — |
| update | ✅ | ✅ | 403 |
| assign | ✅ | 403 | 403 |
| logActivity | ✅ | ✅ | 403 |
| report | all reps | own row only | — |

- Denials carry a clear message: "This lead is not assigned to you." (view / update / logActivity) and "Only managers can assign leads." (assign).
- A rep gets 403, not 404, for another rep's lead. This is a deliberate assumption (it reveals the lead exists) and is documented in the README.

- `Lead::visibleTo(User $viewer)`: managers get everything; reps get `where assigned_to = viewer.id`.
- `User::visibleTo(User $viewer)` (for the report): managers see all reps; a rep sees only themselves.

## 1.8 Endpoints

| Method & URI | Form Request | Controller | Response | Status codes |
|---|---|---|---|---|
| POST `/api/login` | LoginRequest | AuthController@login | LoginResource | 200, 422, 429 |
| GET `/api/leads` | ListLeadsRequest | LeadController@index | LeadResource collection (paginated) | 200, 401, 422 |
| POST `/api/leads` | StoreLeadRequest | LeadController@store | LeadResource | 201, 401, 422 |
| GET `/api/leads/{lead}` | — (policy `view`) | LeadController@show | LeadResource + activities + assigned_rep | 200, 401, 403, 404 |
| PATCH `/api/leads/{lead}` | UpdateLeadRequest | LeadController@update | LeadResource | 200, 401, 403, 404, 422 |
| POST `/api/leads/{lead}/assign` | AssignLeadRequest | LeadAssignmentController (invokable) | LeadResource | 200, 401, 403, 404, 422 |
| POST `/api/leads/{lead}/activities` | StoreActivityRequest | LeadActivityController (invokable) | ActivityResource | 201, 401, 403, 404, 422 |
| GET `/api/reports/rep-performance` | — | ReportController (invokable) | RepPerformanceResource collection | 200, 401 |

Every route except login sits inside `auth:sanctum`.

## 1.9 Validation rules

| Request | Rules |
|---|---|
| LoginRequest | email: required, email. password: required, string. Rate limited by a named limiter keyed on email + IP |
| ListLeadsRequest | status: enum. source: enum. assigned_to: a user id (exists users) or the literal `unassigned`. search: string, max 100; `%` and `_` are escaped before the LIKE. sort: in created_at, expected_value (default created_at). direction: in asc, desc (default desc). per_page: integer 1–100 (default 15) |
| StoreLeadRequest | name: required, max 255. email: required, email. phone: required, max 30. company: nullable, max 255. source: required, enum. status: optional, enum, **not won/lost**. expected_value: required, numeric, 0–9999999999.99, max 2 decimals. assigned_to: **managers only**, must be a user with role rep |
| UpdateLeadRequest | Same fields as `sometimes`. No assigned_to (assignment has its own endpoint). **Won/lost rule** in `after()`, skipped when `status` already failed its own rules |
| AssignLeadRequest | assigned_to: required, integer, exists users where role = rep (same field name as the filter and create) |
| StoreActivityRequest | type: required, enum. body: required, string, max 5000. occurred_at: nullable, date, not in the future (defaults to now) |

## 1.10 JSON shape

```jsonc
// Single resource
{ "data": { "id": 1, "name": "..." } }

// Paginated list
{ "data": [ ... ], "links": { "first": "...", "next": "..." }, "meta": { "current_page": 1, "total": 40 } }

// Login
{ "data": { "token": "1|abc...", "token_type": "Bearer", "user": { "id": 1, "name": "...", "email": "...", "role": "manager" } } }

// 422
{
  "message": "A lead can only be marked won or lost after at least one activity has been logged.",
  "errors": { "status": ["A lead can only be marked won or lost after at least one activity has been logged."] }
}

// 401
{ "message": "Unauthenticated." }

// 403
{ "message": "This lead is not assigned to you." }

// 404
{ "message": "Resource not found." }
```

Money is returned as a string (`"12500.00"`) so no precision is lost. Timestamps use ISO 8601.

## 1.11 Where each business rule lives

| Rule | Location |
|---|---|
| Rep vs manager visibility | `LeadPolicy` + `Lead::visibleTo()` |
| Won/lost needs an activity | `UpdateLeadRequest::after()` calling `Lead::hasActivities()` (uses `exists()`) |
| No won/lost on create | `StoreLeadRequest` status rule |
| Only managers set assigned_to on create; reps auto-assigned | `StoreLeadRequest` (rule + prepared data) |
| Only managers assign | `LeadPolicy::assign` via `AssignLeadRequest::authorize()` |
| Notify rep on assignment | `NotifyRepOfLeadAssignment` job, dispatched only when the assignee changes |

## 1.12 Report query design

One SQL query. Leads and activities are aggregated in **separate** subqueries: joining them directly would repeat each lead once per activity and inflate the sums.

```sql
SELECT u.id, u.name,
       COALESCE(l.total_leads, 0)          AS total_leads,
       COALESCE(l.new_count, 0)            AS new_count,       -- same for contacted/qualified/won/lost
       COALESCE(l.total_expected_value, 0) AS total_expected_value,
       COALESCE(l.won_expected_value, 0)   AS won_expected_value,
       COALESCE(a.activity_count, 0)       AS activity_count
FROM users u
LEFT JOIN (
    SELECT assigned_to,
           COUNT(*)                                                     AS total_leads,
           SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END)              AS new_count,
           -- ... contacted / qualified / won / lost
           SUM(expected_value)                                          AS total_expected_value,
           SUM(CASE WHEN status = 'won' THEN expected_value ELSE 0 END) AS won_expected_value
    FROM leads
    WHERE assigned_to IS NOT NULL            -- plus: AND assigned_to = :rep  when a rep asks
    GROUP BY assigned_to
) l ON l.assigned_to = u.id
LEFT JOIN (
    SELECT user_id, COUNT(*) AS activity_count
    FROM activities                          -- plus: WHERE user_id = :rep  when a rep asks
    GROUP BY user_id
) a ON a.user_id = u.id
WHERE u.role = 'rep'                         -- plus: AND u.id = :rep  when a rep asks
ORDER BY u.name;
```

- Status values are bound from the enum (no string concatenation).
- `(assigned_to, status, expected_value)` covers the leads subquery; `activities.user_id` serves the activity count.
- The query count is constant: 1 query whether there are 3 reps or 300.
- Money totals are formatted with `number_format($value, 2, '.', '')`. Known limitation: SQLite (tests) has no real DECIMAL, so its sums are floats; MySQL correctness is checked in Step 8 and the limitation is noted in the README.

Output per rep:

```json
{
  "rep": { "id": 2, "name": "Rep One" },
  "total_leads": 12,
  "status_counts": { "new": 3, "contacted": 2, "qualified": 3, "won": 2, "lost": 2 },
  "total_expected_value": "184500.00",
  "won_expected_value": "42000.00",
  "activity_count": 31
}
```

## 1.13 Testing plan

| Test file | What it proves |
|---|---|
| DataModelTest | relationships, enum casts, exact decimals, delete behaviour of each FK |
| AuthTest | login success / wrong password / unknown email / missing fields / throttling per email + IP |
| LeadPolicyTest | the full authorization matrix |
| LeadIndexTest | rep scoping, each filter (incl. `assigned_to=unassigned`), search on 3 fields, LIKE wildcards escaped, both sorts, pagination, 422 on bad params, no N+1, 401 |
| LeadShowTest | 200 with activities and rep, 403, 404 |
| LeadStoreTest | create rules, rep auto-assign, won/lost blocked on create, managers-only assigned_to |
| LeadUpdateTest | field updates, 403, won/lost rule (422 + DB unchanged), allowed with an activity |
| LeadAssignmentTest | assign/reassign, 403 for reps, 422 for non-reps, access moves to the new rep |
| LeadActivityTest | 201, user_id from auth, 403, bad type, future date, default occurred_at, end-to-end won flow |
| RepPerformanceReportTest | exact numbers, zero-lead rep, unassigned ignored, scoping, constant query count |
| DatabaseSeederTest | seeded data obeys the domain rules |
| NotifyRepOfLeadAssignmentTest | job dispatched / not dispatched cases, handler logs |

## 1.14 Bonus choices (the brief says "at most one or two")

1. **Queued job on assignment.** It shows queues (tries, backoff, failure handling, afterCommit) and is quick to test with `Queue::fake()`.
2. **One-command Docker.** Reviewers can run the project with `docker compose up --build`.

Multi-tenancy is only a short sketch in the README under "What I'd do with more time". Report caching and the event listener are also listed there and not built.

## 1.15 Phases, time and commits

To do in Step 13: trim this section down to the commit list (drop the estimates and the schedule).

| Step | Work | Est. | Commit message |
|---|---|---|---|
| 0 | Laravel + Sanctum setup, GitHub repo | 20 min | `chore: bootstrap Laravel 12 with Sanctum` |
| 1 | Plan doc + CLAUDE.md | 15 min | `docs: add implementation plan` |
| 2 | Enums, migrations, models, factories | 45 min | `feat: add enums, migrations, models and factories` |
| 3 | Login + JSON errors | 30 min | `feat(auth): add Sanctum token login and JSON error handling` |
| 4 | Policy + visibility scope | 20 min | `feat(authz): add lead policy and visibility scope` |
| 5 | List + show | 60 min | `feat(leads): list with filters, search, sort, pagination; show lead` |
| 6 | Create + update + won/lost | 45 min | `feat(leads): create and update leads, enforce won/lost rule` |
| 7 | Assign + activities | 45 min | `feat(leads): assign leads to reps and log activities` |
| 8 | Report | 60 min | `feat(reports): add single-query rep performance report` |
| 9 | Seeder | 20 min | `feat(db): seed manager, reps, leads and activities` |
| 10 | Bonus: queued job | 30 min | `feat(queue): notify rep via queued job on assignment` |
| 11 | Bonus: Docker | 60 min | `build: add one-command Docker setup` |
| 12 | README | 40 min | `docs: write README` |
| 13 | Final review | 30 min | `chore: final review cleanup` |

**Friday:** steps 0–6. **Saturday morning:** steps 7–10. **Saturday midday:** steps 11–13 (skip 11 if you're behind). **Saturday afternoon:** submit, aiming for about 5 pm.
