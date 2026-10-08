# Tasks: Employee CSV Import

**Tests**: REQUIRED (Pest + Livewire/Filament). Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan
test` · `… ./vendor/bin/pint --test`. Never run two test processes at once.

plan.md R6 (plan review resolutions) overrides earlier sections.

## Run U
- [ ] T001 CsvReader (encoding + delimiter detection, header mapping Arabic/English, limits) (R1)
- [ ] T002 EmployeeImport::preview + ImportPreview (validation, branch resolution, dates, gender, in-file duplicates, update diffs) (R2)
- [ ] T003 EmployeeImport::commit (re-validate, one transaction, SequenceService codes, audit) (R3)
- [ ] T004 Filament ImportEmployees page + header action + template download (R4, R5)
- [ ] T005 Tests: UTF-8/BOM/Windows-1256 + semicolon fixtures, every validation error, all-or-nothing (invalid file changes nothing), update diff + empty optional cells keep values, generated codes continue the sequence and skip used numbers, sequence change between preview and confirm, authorisation (company admin yes; hr_staff / other company / reporter no; Platform Admin within tenant), audit entries, 5,000-row limit
- [ ] T006 docs: WEIRDNESS (empty optional cells never erase stored values; Windows-1256 support); walkthrough; PR
