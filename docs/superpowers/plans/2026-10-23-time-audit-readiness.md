# Time Audit Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop issued months drifting, record who held which JDW contract position and when, and turn the October 2026 audit pack into a one-click export.

**Architecture:** A `month_locks` row per client and month records the report-issued date and snapshots hours by project and task. A `TimeEntry` observer refuses any write that touches a locked client-month, unless the actor is an admin with an open, time-boxed override; every overridden change is logged with before and after values. Contract positions are a dated history table resolved by one in-memory timeline, which the detailed export, the new audit export and the team reports all use.

**Tech Stack:** PHP 8.2, Laravel 11, Livewire 4, Eloquent observers, maatwebsite/excel (already installed), Pest 3, SQLite in tests, MySQL 8 in production.

**Spec:** [Internal Tools/Time tracking - audit readiness improvements](https://app.asana.com/1/155579732034488/task/1219786558272938). Background: `~/Desktop/Time Audit/JDW Time Audit Findings Oct 2026.md` and the Export sheet in `~/Desktop/Time Audit/JDW Time Audit Export Oct 2026 (for JDW).xlsx`.

## Ticket review: decisions and open questions

Decisions this plan makes. Change them before execution if they're wrong.

1. **Override model.** Admin opens an override on a locked client-month with a reason (minimum 10 characters). It lasts 60 minutes and covers only that admin. Edits then go through the normal timesheet, bulk move, API and MCP paths, and each one is logged with before and after values. The alternative, a reason prompt on every edit, would mean new modal steps in DayView, WeekView, BulkMove and MCP. Managers count as standard users.
2. **What is locked.** `user_id, project_id, task_id, spent_on, hours, notes, is_billable, billable_rate_snapshot, billable_amount`. Asana sync columns and `invoiced_at` stay writable. Creating, deleting, and moving entries into or out of a locked month are all blocked.
3. **Issued figures are snapshotted when the lock is set.** For Jul to Sep 2026 that's fine because the audit found they match the reporting exactly. Earlier months are Harvest-only. Importing the original issued figures is out of scope.
4. **No unlock or re-issue in v1.** A wrong lock is fixed in tinker. Re-issuing a corrected report is a follow-up.
5. **Person type reuses `users.is_contractor`.** The matrix only has "Employee" and "Contractor". No new column.
6. **Labels follow the October Export sheet**, not the ticket's shorter list: "CEO and CTO (50% each)" and "Outside contracted team". That way the export reproduces the sheet without mapping.
7. **Filter role** in the audit export is the user's current `role_title`, as in the October sheet (one role per person).
8. **"Include archived by default" applies to every report.** `showArchived` now defaults to true, so on-screen totals and exports agree. When archived projects are hidden, the export filename ends `-excluding-archived`. Side effect: the Projects report lists archived projects until someone unticks the box.
9. **Audit export scope.** A small page with a date range and client checkboxes, defaulting to active JDW-profile clients.

Open questions (the plan works without answers, but the data steps need them):

- **Notes check.** QA step 3 ("PersonalizeWP standup" on a JDW project) has no matching requirement or expected behaviour. Task 12 proposes rejecting the save. It needs a yes, plus the term list.
- **Contract position schedule.** It comes from Olly (see "For Olly to provide" in the findings). Positions are entered by an admin; no importer is built.
- **Leave dates.** The matrix only marks a leave *month* (`p`). The importer uses month end and lists every approximated date so they can be corrected.
- **Org chart titles.** Code trims titles and suggests existing ones. Aligning them with the org chart is data entry and needs the Figma org chart.
- **JDW Support & Maintenance.** Confirm it has the JDW billability profile in production. Locally only JDW Projects and JDW Management do.
- **Occupancy and gaps report.** Not built. This plan provides the data (start, leave and position dates). A report is a follow-up.

## Global Constraints

- Contract position labels, exactly: `CEO and CTO (50% each)`, `Head of Delivery`, `Head of Development`, `Head of Support`, `Planner / Product Owner`, `Technical Architect`, `Senior Developer`, `UX and Design`, `Developer`, `Project Manager`, `Tester`, `Support`; no position = `Outside contracted team`.
- Audit export header, exactly: `Name,Filter role,Contract position,Month,Client,Project,Task,Hours`. Month is `YYYY-MM`. Hours use `HoursFormatter::asReportDecimal()`. Rows are sorted by Name, Month, Client, Project, Task. No notes, rates or amounts.
- The detailed CSV keeps its 21 existing columns in order. New columns are appended: `Contract Position`, `Time Entry Id`.
- Locks are per client per calendar month. Only `Role::Admin` can override.
- Lock errors are thrown as `ValidationException` under the key `month_lock`. Livewire, the JSON API (422) and MCP already surface that.
- Queries must run on SQLite (tests) and MySQL. Don't use `DATE_FORMAT` or other MySQL-only SQL.
- No new Composer or npm dependencies.
- Query-builder mass updates and `TimeEntry::withoutEvents()` bypass the observer. Today only `SyncAsanaTaskHoursJob` (Asana columns) and the one-off `OneOffHistoricalHarvestTime` command use them. Leave both as they are.
- Work on a branch in the main checkout (repo memory: no worktrees here). Re-check `git branch --show-current` before each commit. No AI attribution trailers in commits or PRs.
- Ship as three PRs: Phase A (Tasks 1–5), Phase B (Tasks 6–8), Phase C (Tasks 9–11). Phase C depends on Task 7.

## Review Focus

1. **Running timer across a lock.** If a timer started on 31 July is still running when July is locked, the user could never stop it. `lock()` refuses while a running entry exists in that client-month (test in Task 1).
2. **Project client change.** Re-pointing a project to another client moves locked hours without touching an entry. That is refused while the project has time in a locked month of either client (test in Task 2).
3. **WeekView partial save.** One locked cell must not leave the other cells half-saved. `save()` runs in a transaction (test in Task 4).
4. **Move between two locked months.** An admin with an override only on the source month is still refused for the destination (test in Task 2).
5. **MCP actor.** MCP tools authenticate on the `api` guard, so the guard must look there too, or admins with an override are refused over MCP (test in Task 2).

## File Structure

- `app/Models/MonthLock.php`, `MonthLockSnapshot.php`, `MonthLockOverride.php`, `MonthLockOverrideChange.php`: lock records.
- `app/Domain/TimeTracking/MonthLockService.php`: set locks and open or close overrides.
- `app/Domain/TimeTracking/MonthLockGuard.php`: the one place that decides whether a write is allowed, and logs overridden changes.
- `app/Observers/TimeEntryMonthLockObserver.php`, `app/Observers/ProjectMonthLockObserver.php`: wire the guard to model events.
- `app/Domain/Reporting/ClientMonthTotals.php`: hours by project and task for a client and month (snapshot and reconciliation share it).
- `app/Domain/Reporting/MonthReconciliation.php`, `ReconciliationRow.php`: issued against current.
- `app/Enums/ContractPosition.php`, `app/Models/UserContractPosition.php`, `app/Domain/Reporting/ContractPositionTimeline.php`.
- `app/Domain/Reporting/AuditTimeCsvExport.php`.
- `app/Console/Commands/ImportEmployeeMatrix.php`.
- Livewire: `Admin/MonthLocks/Index`, `Reports/ReconciliationReport`, `Reports/AuditExport`, plus edits to DayView, WeekView, BulkMove, Admin Users, TeamOverviewReport and TeamReport.
- Tests: `tests/Feature/MonthLocks/*`, `tests/Feature/Admin/ContractPositionTest.php`, `tests/Feature/Admin/RoleTitleTidyTest.php`, `tests/Feature/Console/ImportEmployeeMatrixTest.php`, `tests/Feature/Reporting/AuditTimeCsvExportTest.php`, `tests/Feature/Reports/TeamContractPositionTest.php`.

---

## Phase A: Month locking and reconciliation

### Task 1: Lock records, snapshot and override service

**Files:**
- Create: `database/migrations/2026_10_23_000001_create_month_locks_tables.php`
- Create: `app/Models/MonthLock.php`, `app/Models/MonthLockSnapshot.php`, `app/Models/MonthLockOverride.php`, `app/Models/MonthLockOverrideChange.php`
- Create: `app/Domain/Reporting/ClientMonthTotals.php`, `app/Domain/TimeTracking/MonthLockService.php`
- Test: `tests/Feature/MonthLocks/MonthLockServiceTest.php`

**Interfaces:**
- Produces: tables `month_locks` (`client_id` FK restrict, `month` date = first of month, `issued_on` date, `locked_by_user_id`, unique `client_id+month`); `month_lock_snapshots` (`month_lock_id` cascade, `project_id`/`task_id` FK **restrictOnDelete**, `hours` decimal 10,2, unique `month_lock_id+project_id+task_id`); `month_lock_overrides` (`month_lock_id`, `user_id`, `reason` text, `expires_at`, `closed_at` nullable, timestamps); `month_lock_override_changes` (`month_lock_override_id`, `time_entry_id` unsignedBigInteger indexed **without FK** so deletes survive, `action` string, `before`/`after` json nullable, `created_at`).
- Produces: `ClientMonthTotals::byProjectTask(int $clientId, CarbonImmutable $month): array<string, float>`, keyed `"{projectId}|{taskId}"` and including archived projects.
- Produces: `MonthLockService::lock(Client $client, CarbonImmutable $month, CarbonImmutable $issuedOn, User $admin): MonthLock`, `openOverride(MonthLock $lock, User $admin, string $reason): MonthLockOverride`, `closeOverride(MonthLockOverride $override): void`.
- Produces: `MonthLock::activeOverrideFor(User $user): ?MonthLockOverride`, `MonthLock::label(): string` (e.g. `July 2026`), `MonthLockOverride::isActive(): bool`, `MonthLockOverride::scopeActive()`.

- [ ] **Step 1: Write the failing tests**
  - `it('snapshots hours by project and task when a month is locked')`: client A in July 2026, project P with task T1 entries of 2.0 and 1.5 and task T2 with 3.0. An August entry of 4.0 and another client's July entry of 5.0 are ignored. Expect 2 snapshot rows: P|T1 = 3.5, P|T2 = 3.0.
  - `it('rejects locking a month that has not ended')`: the current month throws `ValidationException`.
  - `it('rejects a second lock for the same client and month')`.
  - `it('rejects locking while a timer is running in that client-month')`: the message names the user.
  - `it('opens an override that expires after 60 minutes')`: with `Carbon::setTestNow`, `expires_at` is now + 60 min and `isActive()` is true; after `travel(61)->minutes()` it is false.
  - `it('requires an admin and a reason of at least 10 characters to open an override')`.
- [ ] **Step 2: Run and see them fail.** `php artisan test tests/Feature/MonthLocks/MonthLockServiceTest.php`. Expected: FAIL (missing classes).
- [ ] **Step 3: Implement the migration, models, `ClientMonthTotals` and `MonthLockService`.** `lock()` creates the lock and its snapshot rows in one `DB::transaction`. Opening an override closes the same admin's other active override on that lock.
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: record month locks with issued-hours snapshots`

### Task 2: Enforce locks on every time entry write

**Files:**
- Create: `app/Domain/TimeTracking/MonthLockGuard.php`, `app/Observers/TimeEntryMonthLockObserver.php`, `app/Observers/ProjectMonthLockObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`: `scoped()` binding for the guard; register both observers, the time entry one before the Asana and schedule observers
- Test: `tests/Feature/MonthLocks/MonthLockGuardTest.php`

**Interfaces:**
- Consumes: `MonthLock::activeOverrideFor()`, the Task 1 models.
- Produces: `MonthLockGuard::LOCKED_FIELDS` (the list in decision 2); `assertWritable(TimeEntry $entry, string $action): void` and `record(TimeEntry $entry, string $action): void`, where `$action` is `created|updated|deleted`; `assertProjectClientChangeAllowed(Project $project): void`; `lockFor(int $clientId, CarbonInterface $date): ?MonthLock`; `lockedClientMonths(CarbonImmutable $from, CarbonImmutable $to): array<string, MonthLock>`, keyed `"{clientId}|Y-m"`.
- The actor is `auth()->user() ?? auth('api')->user()`. No actor (CLI, queue) counts as a standard user.
- The observer calls `assertWritable` from `creating`, `updating` and `deleting`, and `record` from `created`, `updated` and `deleted`. In `updated`, `getOriginal()` still holds the old values. An update with no dirty `LOCKED_FIELDS` is ignored. An update checks both the original and the new client-month.
- Error copy:
  - Standard user: `July 2026 is locked for JDW Projects: the report was issued on 5 August 2026. Ask an admin to make this change.`
  - Admin without an override: `July 2026 is locked for JDW Projects. Open an override in Admin > Month locks first.`

- [ ] **Step 1: Write the failing tests.** Each one locks July 2026 for client A, then:
  - `it('rejects a standard user editing an entry in a locked month')`: `TimeEntryService::update($entry, ['hours' => 3.0])` throws with key `month_lock` and a message containing `July 2026 is locked`. Hours stay at 2.0.
  - `it('rejects creating an entry in a locked month')`.
  - `it('rejects a manager the same way as a standard user')`.
  - `it('rejects moving an entry out of a locked month')`: `spent_on` from 2026-07-10 to 2026-08-03.
  - `it('rejects moving an entry into a locked month from an unlocked client')`.
  - `it('rejects an admin without an open override')`.
  - `it('allows an admin with an open override and logs who, when and why')`: changing hours from 2.0 to 3.0 succeeds. One `MonthLockOverrideChange` row: action `updated`, `before.hours` 2.0, `after.hours` 3.0, and its override's `user_id` and `reason` match.
  - `it('logs a deleted entry with its before values')`: `after` is null and the row survives the delete.
  - `it('rejects an admin whose override covers only the source month')`: both July and August are locked; the override is on July only.
  - `it('allows an admin over MCP when the override is open')`: act on the `api` guard and call `InternalMcpActions::updateTimeEntry`.
  - `it('ignores Asana sync metadata updates on locked entries')`: `$entry->update(['asana_synced_at' => now()])` with no auth user succeeds.
  - `it('rejects changing a project client when the project has time in a locked month')`: key `client_id`.
- [ ] **Step 2: Run and see them fail.** `php artisan test tests/Feature/MonthLocks/MonthLockGuardTest.php`
- [ ] **Step 3: Implement the guard and observers.** Memoise project-to-client lookups inside the guard; the scoped binding resets them per request or job.
- [ ] **Step 4: Run the new tests and the full suite.** `php artisan test`. Existing tests create no locks, so they must stay green.
- [ ] **Step 5: Commit** `feat: block changes to entries in locked months`

### Task 3: Admin month locks page

**Files:**
- Create: `app/Livewire/Admin/MonthLocks/Index.php`, `resources/views/livewire/admin/month-locks/index.blade.php`
- Modify: `routes/web.php` (in the `can:access-admin` group: `/month-locks`, name `admin.month-locks`), `resources/views/layouts/app.blade.php` (admin menu link "Month locks" next to "Bulk Assign")
- Test: `tests/Feature/MonthLocks/AdminMonthLocksPageTest.php`

**Interfaces:**
- Consumes: `MonthLockService`.
- Component state: `string $month` (`Y-m`, defaults to last month), `string $issuedOn` (defaults to today), `array $clientIds` (defaults to active clients whose `usesJdwTaskDefaults()` is true), `?int $overrideLockId`, `string $overrideReason`.
- Actions: `lock()` (one lock per ticked client; validation errors stay on the form), `startOverride(int $lockId)`, `openOverride()`, `closeOverride(int $overrideId)`.
- List columns: Client, Month, Issued on, Locked by, Issued hours (the snapshot sum), Override (who, expires at, or none), and a link to `reports.reconciliation` for that month.

- [ ] **Step 1: Write the failing tests**
  - A standard user and a manager get 403.
  - Admin locks `2026-07` for two clients: 2 `MonthLock` rows; the page shows `July 2026` twice.
  - Opening an override with the reason `Correcting misfiled support hours` creates an active override; closing it sets `closed_at`.
  - A short reason gives an `overrideReason` error.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement the component, view, route and nav link.** Match the layout of `admin/time-entries/bulk-move.blade.php`.
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: add admin month locks page`

### Task 4: Locked-month feedback in timesheets, bulk move and the API

**Files:**
- Modify: `app/Livewire/Timesheet/DayView.php`, `resources/views/livewire/timesheet/day-view.blade.php`, `app/Livewire/Timesheet/WeekView.php`, `resources/views/livewire/timesheet/week-view.blade.php`, `resources/views/livewire/admin/time-entries/bulk-move.blade.php`, `resources/views/livewire/integrations/asana-log-time.blade.php`
- Test: `tests/Feature/MonthLocks/TimesheetMonthLockTest.php`

**Interfaces:**
- Consumes: `MonthLockGuard::lockedClientMonths()`, `lockFor()`.
- DayView and WeekView pass `lockedClientMonths` for the visible range to the view. Entries and cells in a locked client-month show "Locked" and have edit, delete and input disabled, unless the viewer is an admin with an active override. This is a convenience only; the observer still enforces the rule.
- Wrap the body of `WeekView::save()` in `DB::transaction`.
- Render `@error('month_lock')` in the DayView modal, next to the WeekView Save button, in BulkMove and in AsanaLogTime. Use the existing `text-red-600 text-xs` style.
- Keep any new modal state client-side. No new `wire:model.live` in the DayView modal (repo memory: mid-modal morphs orphan the pickers).

- [ ] **Step 1: Write the failing tests**
  - DayView: a standard user saving an edit to a July entry gets `assertHasErrors('month_lock')`, and the entry is unchanged.
  - WeekView: save with one locked July cell changed and one unlocked August cell changed (a week spanning both months, e.g. week of 2026-07-27). Expect `assertHasErrors('month_lock')` and neither cell persisted.
  - BulkMove: moving a July entry gives `assertHasErrors('month_lock')` and the entry keeps its project.
  - API: `POST /api/time-entries` dated 2026-07-15 on a locked client returns 422 with `errors.month_lock`.
  - DayView renders `Locked` for a locked entry, and doesn't for an admin with an open override.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the tests plus `tests/Feature/Timesheet`.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: show locked months in timesheets and bulk move`

### Task 5: Reconciliation report

**Files:**
- Create: `app/Domain/Reporting/MonthReconciliation.php`, `app/Domain/Reporting/ReconciliationRow.php`, `app/Livewire/Reports/ReconciliationReport.php`, `resources/views/livewire/reports/reconciliation-report.blade.php`
- Modify: `routes/web.php` (in the reports group: `/reconciliation`, name `reports.reconciliation`), `resources/views/layouts/app.blade.php` (Reports menu link "Reconciliation")
- Test: `tests/Feature/MonthLocks/MonthReconciliationTest.php`

**Interfaces:**
- Consumes: `ClientMonthTotals::byProjectTask()`, `MonthLockSnapshot`, `MonthLockOverrideChange`.
- Produces: `final readonly class ReconciliationRow { string $clientName; string $projectName; string $taskName; float $issuedHours; float $currentHours; float $difference; }`, and `MonthReconciliation::rows(CarbonImmutable $month): Collection<int, ReconciliationRow>`. The rows are the union of snapshot keys and current keys, sorted by client, project, task.
- Produces: `MonthReconciliation::changes(CarbonImmutable $month): Collection<int, MonthLockOverrideChange>`, with override and user eager-loaded.
- Component: `#[Url] string $month`, a select limited to months that have locks (newest first). The table has client and project subtotals, and differences of 0.01h or more are highlighted. Below it, an override log shows when, who, reason, entry id, action and hours before → after.

- [ ] **Step 1: Write the failing tests**
  - `it('shows issued and current hours with the difference after an override edit')`: P|T1 is issued at 3.5; an admin edit under override adds 1.0. Expect issued 3.5, current 4.5, difference 1.0, and the client subtotal difference is 1.0.
  - `it('lists combinations added after the report was issued with zero issued hours')`.
  - `it('lists override changes with who, when and why')`: `assertSee` the admin's name and the reason.
  - A standard user gets 403; a manager gets 200.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: add issued-month reconciliation report`

---

## Phase B: People data

### Task 6: Role title tidy-up

**Files:**
- Modify: `app/Models/User.php` (a `roleTitle()` Attribute mutator), `app/Livewire/Admin/Users/Index.php` and `resources/views/livewire/admin/users/index.blade.php` (a `<datalist id="role-titles">` of distinct existing titles on the role title input)
- Create: `database/migrations/2026_10_23_000010_trim_user_role_titles.php` (data only; `down()` is a no-op)
- Test: `tests/Feature/Admin/RoleTitleTidyTest.php`

**Interfaces:**
- The setter trims, collapses internal whitespace to single spaces, and stores an empty result as null. `HarvestImport` goes through the mutator, so it needs no change.

- [ ] **Step 1: Write the failing tests**
  - `'WordPress Developer '` is stored as `'WordPress Developer'`; `'Senior  Business Analyst '` as `'Senior Business Analyst'`; `'   '` as null.
  - The migration's `up()`, run on rows inserted with `DB::table()` (bypassing the mutator), trims them.
  - The Users admin page renders an `<option value="Senior Developer">` in `role-titles`.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.** Do the trimming in PHP inside the migration so it is portable.
- [ ] **Step 4: Run the tests and `tests/Feature/Admin/UsersTest.php`.** Expected: PASS.
- [ ] **Step 5: Commit** `fix: trim user role titles`

### Task 7: Contract positions with effective-from dates

**Files:**
- Create: `app/Enums/ContractPosition.php`, `database/migrations/2026_10_23_000020_create_user_contract_positions_table.php`, `app/Models/UserContractPosition.php`, `app/Domain/Reporting/ContractPositionTimeline.php`
- Modify: `app/Models/User.php` (`contractPositions(): HasMany`, ordered by `effective_from`), `app/Livewire/Admin/Users/Index.php` and its view (a "Contract position" section in the edit panel)
- Test: `tests/Feature/Admin/ContractPositionTest.php`

**Interfaces:**
- Produces: `enum ContractPosition: string` with cases `CeoCto='ceo_cto'`, `HeadOfDelivery`, `HeadOfDevelopment`, `HeadOfSupport`, `PlannerProductOwner`, `TechnicalArchitect`, `SeniorDeveloper`, `UxDesign`, `Developer`, `ProjectManager`, `Tester` and `Support` (snake_case values). It has `label(): string` returning the exact Global Constraints labels, and `public const OUTSIDE_LABEL = 'Outside contracted team'`.
- Produces: table `user_contract_positions` (`user_id` FK cascade, `position` string nullable, where null means outside the contracted team from that date; `effective_from` date; timestamps; unique `user_id+effective_from`).
- Produces: `ContractPositionTimeline::load(): self` (one query over the whole table, which is small), `on(int $userId, CarbonInterface|string $date): ?ContractPosition` and `labelOn(int $userId, CarbonInterface|string $date): string`.
- Admin actions: `addContractPosition()` (state: `string $newPosition`, where `''` means outside; `string $newPositionFrom`) and `removeContractPosition(int $id)`.

- [ ] **Step 1: Write the failing tests**
  - Timeline: rows Developer from 2024-03-01, null from 2026-03-01 and SeniorDeveloper from 2026-08-01. `on('2024-02-29')` is null, `on('2025-01-15')` is Developer, `on('2026-04-01')` is null, `on('2026-08-01')` is SeniorDeveloper, and `labelOn('2026-04-01')` is `Outside contracted team`.
  - `ContractPosition::CeoCto->label()` is `'CEO and CTO (50% each)'`, and `count(ContractPosition::cases())` is 12.
  - An admin adds Tester from 2025-02-01 through the Livewire component and a row is created. The same date again gives an error on `newPositionFrom`.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: record contract positions per user with effective dates`

### Task 8: Start and leave dates, plus employee matrix import

**Files:**
- Create: `database/migrations/2026_10_23_000030_add_employment_dates_to_users_table.php` (`started_on` and `left_on`, nullable dates), `app/Console/Commands/ImportEmployeeMatrix.php`
- Modify: `app/Models/User.php` (fillable, `date` casts, `@property`), Admin Users component and view ("Start date" and "Leave date" fields; `left_on` must be on or after `started_on`)
- Test: `tests/Feature/Console/ImportEmployeeMatrixTest.php`

**Interfaces:**
- Command: `users:import-employee-matrix {path} {--dry-run}`. It reads the first sheet with `Maatwebsite\Excel\Facades\Excel::toArray()`. The header row is the first row whose column 0 is `First name`. Columns: 0 First name, 1 Surname, 2 Join date (an Excel serial number; convert with `PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject`), 3 Status, 4 Person type. Columns 5 onwards are consecutive months starting February 2024.
- Each row matches a user by case-insensitive trimmed `"First Surname"` against `users.name`, then sets `started_on` = join date and `is_contractor` = (Person type is `Contractor`). If Status is `Ex-employee`, it also sets `left_on` = the end of the last month marked `p`.
- Output: counts of updated and unmatched users, the unmatched names, and every approximated leave date. `--dry-run` writes nothing.

- [ ] **Step 1: Write the failing tests.** The test builds a temporary xlsx with PhpSpreadsheet in the same shape (no binary fixture committed).
  - `it('sets start date, leave date and person type for matched users')`: a Jane Doe row with join date 2025-03-10, Ex-employee, Contractor and `p` in the Jun 2026 column gives `started_on` 2025-03-10, `left_on` 2026-06-30 and `is_contractor` true.
  - `it('lists unmatched names and writes nothing on dry run')`.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement the migration, command and admin fields.**
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: add employment dates and employee matrix import`

---

## Phase C: Exports and reports

### Task 9: Detailed CSV export fixes

**Files:**
- Modify: `app/Livewire/Reports/Concerns/HasReportPeriod.php` (`$showArchived = true`; `buildExportFilename()` appends `-excluding-archived` when false), `app/Domain/Reporting/DetailedTimeCsvExport.php` (two appended columns, using `ContractPositionTimeline::load()` once per export), `tests/Fixtures/csv-export-snapshot/headers.txt`
- Test: `tests/Feature/Reporting/DetailedTimeCsvExportTest.php`, `tests/Feature/Reports/ScopedCsvExportTest.php` (update anything that assumed archived projects were excluded by default, or that there were 21 columns)

**Interfaces:**
- Consumes: `ContractPositionTimeline::labelOn($entry->user_id, $entry->spent_on)`.

- [ ] **Step 1: Write the failing tests**
  - The header snapshot ends `,Contract Position,Time Entry Id`, and the row has 23 columns: `$cols[21]` is the position label on the entry date and `$cols[22]` is `(string) $entry->id`.
  - `it('includes archived project time in report totals and exports by default')`: the TimeReport totals include an archived project's 2.0h, and the CSV contains that row.
  - `it('names the file excluding-archived when archived projects are hidden')`: `->set('showArchived', false)->call('export')->assertFileDownloaded('detailed-time-2026-07-01-to-2026-07-31-excluding-archived.csv')`.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run `tests/Feature/Reporting`, `tests/Feature/Reports` and `tests/Feature/Mcp`.** Expected: PASS.
- [ ] **Step 5: Commit** `fix: include archived projects and entry ids in detailed export`

### Task 10: Audit export

**Files:**
- Create: `app/Domain/Reporting/AuditTimeCsvExport.php`, `app/Livewire/Reports/AuditExport.php`, `resources/views/livewire/reports/audit-export.blade.php`
- Modify: `routes/web.php` (in the reports group: `/audit-export`, name `reports.audit-export`), `resources/views/layouts/app.blade.php` (Reports menu link "Audit export")
- Test: `tests/Feature/Reporting/AuditTimeCsvExportTest.php`

**Interfaces:**
- Consumes: `ContractPositionTimeline`, `CsvFormatter::field()`, `HoursFormatter::asReportDecimal()`.
- Produces: `AuditTimeCsvExport::__construct(CarbonImmutable $from, CarbonImmutable $to, array $clientIds)`, `HEADERS`, `rows(): Collection<int, array<string, string|float>>`, `writeTo(mixed $handle): void` and `toCsv(): string`.
- Query: one SQL query that sums hours grouped by `user_id, spent_on, project_id, task_id`, joining users, projects, clients and tasks, with no archived filter. PHP then folds the rows to (name, role title, position on `spent_on`, `Y-m`, client, project, task). This keeps SQL portable and splits a month correctly when a position changes partway through it.
- Component: `from` and `to` (default last month), `array $clientIds` (default active clients whose `usesJdwTaskDefaults()` is true), and `download(): StreamedResponse` with filename `audit-time-{from}-to-{to}.csv`. Gate: `access-reports`.

- [ ] **Step 1: Write the failing tests**
  - `it('freezes audit export headers')`: the first line is `Name,Filter role,Contract position,Month,Client,Project,Task,Hours`.
  - `it('aggregates to name, role, position, month, client, project and task')`: 1.5 + 2.0 on the same combination in July gives one row of `3.50`; the August entry is a separate row.
  - `it('splits a month when the contract position changes mid-month')`: the user is Developer until 2026-07-15 and SeniorDeveloper from 2026-07-16, giving 2 July rows.
  - `it('labels users without a position as outside contracted team')`.
  - `it('never includes notes, rates or amounts')`: the CSV doesn't contain the note text `secret note`, `84.0` or `168.0`.
  - Ticket scenario, `it('matches the time report totals by client for July 2026')`: seed two clients, one with an archived project. The audit hours summed per client equal `TimeReportQuery(from: 2026-07-01, to: 2026-07-31)->groupBy(GroupBy::Client)` `total_hours` per client.
  - A standard user gets 403, and the download filename matches.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: add one-click audit export`

### Task 11: Contract position in team reports

**Files:**
- Modify: `app/Livewire/Reports/TeamOverviewReport.php` and `resources/views/livewire/reports/team-overview-report.blade.php` (a "Contract position" column), `app/Livewire/Reports/TeamReport.php` and `resources/views/livewire/reports/team-report.blade.php` (position, start date and leave date under the member's name)
- Test: `tests/Feature/Reports/TeamContractPositionTest.php`

**Interfaces:**
- Consumes: `ContractPositionTimeline::labelOn($userId, min($to, today()))`.

- [ ] **Step 1: Write the failing tests.** For the period 2026-07-01 to 2026-07-31, with the user as Senior Developer from 2026-01-01: the team overview shows `Senior Developer` on that user's row; the member page shows `Senior Developer`; and a user with no positions shows `Outside contracted team`.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run `tests/Feature/Reports`.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: show contract position in team reports`

---

### Task 12 (proposed; confirm before executing): JDW note check

Covers QA step 3. Execute only once the behaviour and term list are confirmed.

**Files:**
- Create: `config/time_tracking.php` (`'jdw_note_flagged_terms' => ['PersonalizeWP', 'Filter AI', 'Medivet']`)
- Modify: `app/Domain/TimeTracking/TimeEntryService.php` (`create()` and `update()`)
- Test: `tests/Feature/Timesheet/JdwNoteCheckTest.php`

**Interfaces:**
- When the project's client `usesJdwTaskDefaults()` and the notes contain a configured term (case-insensitive), throw `ValidationException` with key `notes` and the message `This note mentions PersonalizeWP. Log that time to the right project, or reword the note.`

- [ ] **Step 1: Write the failing tests.** `PersonalizeWP standup` on a JDW project is rejected; the same note on an agency project is accepted; `personalizewp` in lower case is rejected.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the tests.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: flag other-client notes on JDW projects`

---

## Verification and release

- [ ] `php artisan test`: all green.
- [ ] `vendor/bin/pint --test` and `vendor/bin/phpstan analyse`: clean.
- [ ] `npm run build`: succeeds.
- [ ] Rendered check in Chrome at desktop and 390px width: Month locks page, a locked DayView entry, the WeekView locked cell error, Reconciliation, Audit export, and the Users edit panel with the new fields.
- [ ] Post-deploy data steps for production, in order:
  1. `php artisan users:import-employee-matrix "<path>" --dry-run`, review, then run it for real.
  2. Enter contract positions from Olly's schedule.
  3. Align role titles with the org chart.
  4. Lock Jul, Aug and Sep 2026 for the JDW clients.
  5. Spot-check Reconciliation for July (the difference should be 0) and run the audit export for Jul–Sep 2026 against the October sheet.
- [ ] **For you to test:** open https://internal-tools.test/demo-login in Chrome and you land logged in as the admin demo user. Then:
  1. Set a report-issued date for a past month and edit an entry in it (as yourself without an override, then with one).
  2. View your timesheet as a standard user via Admin > Timesheets and try an edit.
  3. Run the audit export for that month and compare its client totals with the Time report.
  4. If Task 12 is approved, log a JDW entry noted "PersonalizeWP standup".
