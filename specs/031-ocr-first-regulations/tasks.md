# Tasks: OCR-First Internal Regulations

- [x] T001 Migration + config: `ocr_confidence` column, `LEGAL_OCR_CONFIDENCE_MIN`, regulation default reading mode `now`.
- [x] T002 `PdfPages` OCR with confidence (TSV), stored by `ReadLegalPageText`.
- [x] T003 Regulation page decision after reading A: confident → `ai_status = skipped`; otherwise AI reading (now/batch); reconcile and batch submission respect it; statutes unchanged.
- [x] T004 `VerifiedSingle` status everywhere verified statuses are listed (builder, correction, model guards, triggers, archive rejection, labels).
- [x] T005 `UnitBuilder`: all-skipped articles → `verified_single`; mixed articles per plan.
- [x] T006 Approvals: approve-all excludes single-reading units; individual approval audited; screen label + page link; translations.
- [x] T007 ADR 0011 + README index, WEIRDNESS line.
- [x] T008 Tests (plan), behaviour inventory, Pint, full suite.
