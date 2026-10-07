# Contracts: Legal Library

## Services
- `LawImportService::start(Law $law, User $actor, ?UploadedFile|string $source, ?int $pageFrom, ?int $pageTo, ImportMode $mode): LegalImportRun`
  — Platform Admin for statutes; company admin (own company) for regulations. Refuses a second active
  run, page counts above the limit, page ranges outside the file. Audits `legal_import.started`.
- `LawImportService::retryAi(LegalImportRun $run, User $actor): void` — only pages with ai_status
  failed/unavailable; refused while running.
- `UnitBuilder::build(LegalImportRun $run): void` — split both readings, compare, write/version units (R3–R5).
- `MismatchResolver::resolve(LegalUnit $unit, User $actor, string $choice /* a|b|edit */, ?string $text): LegalUnit`
  and `exclude(LegalUnit $unit, User $actor, string $reason)` — statutes: Platform Admin; regulation:
  reviewer of that company. Audits with both readings.
- `UnitCorrection::correct(LegalUnit $unit, User $actor, string $text, string $reason): LegalUnit` —
  verified units only → new version.
- `LawManagement::{create, update, setJurisdictions, repeal}` — Platform Admin, statutes only.
- `RegulationUpload::upload(Company $company, User $actor, UploadedFile $file, array $meta): Law` — creates
  the regulation law row (company-scoped) and starts the import (txt/docx → `verified_text` units).
- `RegulationApprovals::{approve(LegalUnit, User), approveAllVerified(Law, User)}` — reviewer only.
- `PageReader::read(string $pngPath, array $context): PageReading{text, illegible}` — throws
  `AiUnavailable` (no key / retries exhausted). Bound to `FakePageReader` in tests.

## Routes / screens
| path | who | notes |
|---|---|---|
| `/platform/laws` (Filament) | Platform Admin | list, create, edit, jurisdictions, repeal, import, runs, units, correct |
| `/platform/mismatch-queue` | Platform Admin | statute mismatches |
| `/admin/{company}/internal-regulation` | company admin (upload), reviewer (resolve/approve) | company units only |
| `GET /legal-library/laws/{law}/pages/{page}` | see research R7 | PNG, private, 404 when not visible |

## Citability (used by CitationGate and the Citation model)
`LegalUnit::scopeCitableFor(Builder, int $companyId, Jurisdiction $j, CarbonInterface $onDate)` — the single
definition of "citable"; 008 retrieval will reuse it.
