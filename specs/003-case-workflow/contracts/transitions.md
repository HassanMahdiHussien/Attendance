# Contract: Allowed transitions (CaseTransitions map)

`→` lists every allowed target. Anything not listed is refused (`IllegalTransition`). The guards
column names the precondition checked (`TransitionBlocked`).

| from | → to | guard / trigger |
|---|---|---|
| draft | open | facts complete; law version found (CaseOpening) |
| open | ai_drafting_defense_letter, defense_letter_citation_check | a draft document exists (human) for the manual path |
| ai_drafting_defense_letter | defense_letter_citation_check, open | AI draft stored / cancel |
| defense_letter_citation_check | defense_letter_ready_for_review | citation check passed or override logged (stub check: no unverified citations) |
| defense_letter_ready_for_review | defense_letter_confirmed, open | HR confirms / sends back for rewrite |
| defense_letter_confirmed | defense_letter_issued | DocumentIssuer issued defense letter |
| defense_letter_issued | awaiting_employee_response | automatic, right after issuance |
| awaiting_employee_response | response_received, response_refused_to_sign, response_delivery_refused, response_scanned_uploaded, committee_session_held | hand-off recorded / deadline passed with no response |
| response_received, response_refused_to_sign, response_delivery_refused | response_scanned_uploaded, committee_session_held | scan attached / nothing to scan |
| response_scanned_uploaded | ai_ingested_response, committee_session_held | — / session recorded |
| ai_ingested_response | committee_session_held, response_scanned_uploaded | session recorded / cancel |
| committee_session_held | ai_drafting_recommendations, recommendations_citation_check | majority reached |
| ai_drafting_recommendations | recommendations_citation_check, committee_session_held | AI draft / cancel |
| recommendations_citation_check | recommendations_awaiting_approvals | check passed |
| recommendations_awaiting_approvals | recommendations_confirmed, recommendations_citation_check | 3 valid approvals / HR edit (RecommendationsEditor) |
| recommendations_confirmed | recommendations_citation_check | HR edit after full approval (approvals invalidated atomically) |
| recommendations_confirmed | recommendations_printed | DocumentIssuer issued Doc A |
| recommendations_printed | awaiting_committee_signatures | automatic |
| awaiting_committee_signatures | signed_recommendations_uploaded | signed scan attached |
| signed_recommendations_uploaded | ai_verify_and_close, decision_letter_citation_check, archived_final | — / penalty (manual Doc B) / no penalty |
| ai_verify_and_close | decision_letter_citation_check, archived_final, signed_recommendations_uploaded | penalty / no penalty / cancel |
| decision_letter_citation_check | awaiting_external_legal_consultation, decision_letter_ready_for_review | consultation requested and not completed / otherwise |
| awaiting_external_legal_consultation | decision_letter_ready_for_review | consultation completed |
| decision_letter_ready_for_review | decision_issued, decision_letter_citation_check | DocumentIssuer issued Doc B (leave confirmation for termination) / rewrite |
| decision_issued | awaiting_appeal_window | automatic; appeal tracking created |
| awaiting_appeal_window | appeal_pending, archived_final | appeal logged / window ended (scheduler) |
| appeal_pending | appeal_resolved | outcome logged |
| appeal_resolved | archived_final | — |
| archived_final | — | terminal |

Corrected document versions (version > 1) are issued without any transition (R10).
A DB trigger refuses `cases.status` changes made outside `CaseWorkflow` (R10).

Every transition writes a `status_changed` case event. Issuance-driven transitions are performed
by the services that call `DocumentIssuer`, inside the same transaction.
