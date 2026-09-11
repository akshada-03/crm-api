# CRM API

Take-home task: a Laravel 12 JSON API (PHP 8.2, Sanctum tokens) for a small slice of a CRM.
The full plan is in **docs/implementation-plan.md**. Read the relevant section before starting a phase.

## Domain

- `User` has a role: `manager` or `rep`.
- `Lead`: name, email, phone, company (nullable), source (web | referral | cold_call | event | other),
  status (new | contacted | qualified | won | lost), expected_value decimal(12,2), assigned_to (rep, nullable).
- `Activity`: belongs to a lead and to the user who logged it; type (call | email | meeting | note), body, occurred_at.
- Reps see and act only on leads assigned to them; managers see and act on all leads.
- A lead can be marked won or lost only if it has at least one activity; otherwise 422 with a clear message.

## Endpoints

All routes except login are behind `auth:sanctum`.

| Method | URI | Purpose |
|---|---|---|
| POST | /api/login | Issue a token |
| GET | /api/leads | Filter (status, source, assigned_to), search (name/email/company), sort (created_at, expected_value), paginate |
| POST | /api/leads | Create a lead |
| GET | /api/leads/{lead} | Show with activities and assigned rep |
| PATCH | /api/leads/{lead} | Update fields and status (won/lost rule) |
| POST | /api/leads/{lead}/assign | Assign or reassign (manager only) |
| POST | /api/leads/{lead}/activities | Log an activity |
| GET | /api/reports/rep-performance | Per-rep summary in one query; managers see all reps, a rep sees only their own row |

## Environment

- Windows. Dev DB: MySQL 8.0 (`crm_api`). Tests: SQLite in-memory (phpunit.xml), so any raw SQL must run on both.
- Run tests with `php artisan test`. Format with `vendor/bin/pint` (in PowerShell: `php vendor/bin/pint`).

## Ground rules

1. Only do the phase I ask for. Never jump ahead.
2. Validation goes in Form Request classes, with clear error messages. No inline `$request->validate()`.
3. Every response goes through an API Resource.
4. Role logic lives only in `LeadPolicy` and the `visibleTo()` scopes. No role checks in controllers and no collection filtering in PHP.
5. Keep controllers thin: authorize, delegate, return a resource.
6. No dead code, unused imports, or leftover scaffolding comments.
7. Every phase ends with:
   - feature tests for that phase, built with factories, with meaningful assertions on status code, JSON and DB state;
   - `php artisan test` passing;
   - `vendor/bin/pint` run.
8. Never run any git command, read-only ones included. I commit myself.
9. End every phase with:
   - (a) the files changed (new / modified / deleted);
   - (b) 3–5 "decisions to defend in the review";
   - (c) "Ready to commit" and the suggested message from plan section 1.15.
