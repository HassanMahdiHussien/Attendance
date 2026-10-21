# Contract: Domain services and data invariants (feature 001)

There is no HTTP API in this feature. The contract other features rely on is the set of services and
invariants below. Later features (workflow, Filament, a future Sanctum API) MUST go through these
and MUST NOT duplicate them. Signatures are indicative; follow Laravel conventions.

## App\Support\Tenancy\TenantContext (singleton, scoped per request/job)

| method | behaviour |
|---|---|
| `setCompany(Company\|int $company): void` | context = that company |
| `setAllCompanies(): void` | context = all (Platform Admin only) |
| `clear(): void` | context = unset |
| `companyId(): ?int` | current company id, null if all/unset |
| `isAllCompanies(): bool` / `isSet(): bool` | |
| `runForCompany(Company\|int, Closure)` | run callback in that company, restore previous context after |
| `runAcrossAllCompanies(Closure)` | explicit cross-tenant callback (jobs, scheduler, seeders), restore after |

- Querying any `BelongsToCompany` model while unset throws `MissingTenantContext`.
- Creating a model while context = company X stamps `company_id = X`; an explicit different
  `company_id` throws `TenantMismatch`. While context = all, `company_id` must be given (or derived
  from the parent) or the save throws.
- Middleware `SetTenantContext` (web group, after auth): company user → `setCompany`;
  `platform_admin` → `setAllCompanies`; others → unset.

## App\Services\SequenceService

`next(int $companyId, SequenceKey $key, int $year = 0): int`. MUST be called inside a DB
transaction; row-locks the counter; returns the value and advances it. Never reused.

## App\Services\Documents\DocumentIssuer

`issue(Document $draft, User $actor, ?string $manualReference = null): Document`
- One transaction: lock the document row; require status draft or ready_for_review; set
  `reference_number` (manual, or `SequenceService::next(company, document_reference, year)`),
  `reference_year`, `reference_is_manual`, `issued_at = now()`, `issued_by`, `status = issued`,
  `is_locked = true`; if `previous_version_id` is set, mark that document superseded with
  `superseded_by_id = this`; append case event `document_issued` (type, version, reference).
- Rejects a duplicate manual reference in the same company + year with a validation error.
- Generated numbers skip values already taken in that company + year (R5).
- Gate (R6b): content rows for every enabled language; if the company requires second review,
  `second_reviewed_by` set and ≠ actor; for `recommendations`, three valid approvals (legal, hr,
  labor_union); otherwise throws `IssuanceBlocked` with the reason.

`previewReference(Document $draft): string` — the provisional next number for the confirmation
screen; reserves nothing.

## App\Services\Documents\DocumentCorrector

`startCorrection(Document $issued, string $reason, User $actor): Document`
- Requires the source to be issued (not superseded) and no open draft for the same case + type.
- Creates a draft: same case, type, penalty_type, drafted_by `human`, `version + 1`,
  `previous_version_id`, `correction_reason`; copies all content rows; appends case event
  `document_correction_started` (from version, reason).

## App\Services\CaseEventLogger / App\Services\AuditLogger

- Context lifecycle: queued jobs receive a company id and run their body inside
  `runForCompany`; a `Queue::before`/`after` listener clears the context between jobs.

## Creation boundaries

`SequenceService::next()` throws `LogicException` when called outside a DB transaction. Creating a
case or an employee therefore has to happen inside `DB::transaction()`. The factories and seeders
used here, and the services in later features, do this.

## Logging

- `CaseEventLogger::log(DisciplinaryCase $case, string $eventType, array $payload = [], ?string $notes = null, ?User $actor = null): CaseEvent`
- `AuditLogger::log(string $action, ?Model $entity = null, array $metadata = [], ?User $actor = null): AuditLog`
  (captures company_id from the entity or context, and the request IP when present).
- Actor defaults to the authenticated user; null for system/AI.

## App\Services\CaseNumberer / employee codes

- Creating a `DisciplinaryCase` without `case_number` assigns `SequenceService::next(company, case)`
  in a `creating` hook inside a transaction.
- Creating an `Employee` with a blank `employee_code` assigns `EMP-` + zero-padded 4-digit sequence.

## Database-level invariants (MySQL triggers, migration-managed)

| table | rule |
|---|---|
| case_events, audit_logs, ai_generation_logs, attachments | UPDATE and DELETE always rejected |
| documents | if `OLD.is_locked = 1`: DELETE rejected; `is_locked` 1→0 rejected; UPDATE rejected unless the only changes are `status` issued→superseded, `superseded_by_id` null→value, `pdf_file_path` null→value (with `updated_at` allowed to move only alongside one of those); `updated_at` alone rejected |
| document_contents, citations | INSERT/UPDATE/DELETE rejected when parent document `is_locked = 1` |
| document_approvals | INSERT rejected when document locked; UPDATE rejected except `invalidated_at` null→value; DELETE rejected |
| legal_units | when `OLD.status` is verified_*: UPDATE rejected except `superseded_by_id` null→value; DELETE rejected |
| all tenant tables | composite FKs reject cross-company references |

## Invariant test matrix (each cell is a Pest test)

| invariant | Eloquent | query builder | raw SQL |
|---|---|---|---|
| append-only logs / attachments: update, delete | ✔ | ✔ | ✔ |
| locked document: each protected column, `updated_at` alone, delete, unlock | ✔ | ✔ | ✔ |
| locked document: allowed transitions succeed | ✔ | — | — |
| locked contents / citations / approvals: insert, update, delete | ✔ | ✔ | ✔ |
| verified legal unit: update text/article/page, delete | ✔ | ✔ | ✔ |
| cross-company FK on each tenant FK | ✔ | — | ✔ |
| tenant scope: list, find, count, update/delete via builder, create stamping, mismatch | ✔ (every T model) | ✔ | — |
| fail-closed when no context; restore after `runForCompany`; job context reset | ✔ | — | — |
| sequences: per company, per year, manual skip, outside-transaction throws, rollback | ✔ | — | — |
| migrations: fresh + rollback recreate/drop triggers | ✔ | — | — |

Error surface: triggers raise `SQLSTATE 45000` with a message prefixed `IMMUTABLE:`; model guards
throw `App\Exceptions\ImmutableRecordException` before the query is sent.
