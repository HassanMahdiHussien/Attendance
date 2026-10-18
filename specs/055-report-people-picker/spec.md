# Feature Specification: People picker and a simpler report form (step 1 of 2)

**Branch**: `055-report-people-picker` | **Created**: 2026-10-18 | **Status**: Approved

**Input**: Product owner testing the reporter form (2026-10-18):
- "No need to show all employees; type a name (Arabic or English) or number, pick, then search for another if needed."
- "If the employee is not listed, just type his name in the same field."
- Witnesses are picked from the employee list too (companies upload all employees).
- «الشخص المسؤول المزعوم»: owner chose **AI detects it** — remove the field from the reporter form; in step 2 the AI picks it up from the description and the reporter confirms. HR can still set it on the case. The repeat-responsible-person warning keeps working (CLAUDE.md §3).
- Step 2 (separate feature 056): description-first report where the AI fills the fields, asks simple questions and blocks sending until complete.

## Requirements
- **FR-001 People picker** (one reusable component): a search box matching employee name (Arabic), English name and employee number within the user's allowed branch(es) and company; up to 8 results; choosing one adds a removable tag; search again to add more; keyboard and screen-reader usable; RTL-correct; phone-friendly. Option "Add «typed text» as a name not in the list" when nothing matches (or always, as the last option) for fields that allow free names.
- **FR-002 Employees concerned** (reporter form): multi-select picker; the first tag is the main employee; free names allowed. Free names are stored on the report (new nullable field, e.g. JSON list) and shown to HR in the Reports inbox with a clear "not in the employee list — add the employee before opening a case" hint; conversion to cases still requires real employee records (existing rules unchanged). The long checkbox list is removed.
- **FR-003 Witnesses** (reporter form and HR New case form): picker with employee links (`witnesses.employee_id`) and free names, replacing the comma-separated text box. Existing conflict-of-interest and duplicate checks keep working (they already read witness employee_id and name).
- **FR-004 Responsible person**: removed from the reporter form only. HR New case form keeps it but uses the same picker (single choice, free name allowed). Data columns unchanged.
- **FR-005 HR New case form** uses the same picker for employees (multi, incident) and witnesses, so both forms behave the same.
- **FR-006** Translations en/ar, Western digits, Departures design; behaviour inventory updated; tests: search by Arabic name / English name / number, branch and tenant scoping (no cross-company or out-of-branch employees ever returned), adding/removing tags, free names stored, witnesses saved with employee_id, report without responsible field, HR form parity, conversion still requires real employees.
