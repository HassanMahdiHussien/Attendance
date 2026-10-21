# Contract: Screens, access and stage mapping (feature 005)

## Routes × roles (✔ allowed; everything else 404 / 403 or redirect to landing)

| route | company_admin | hr_staff | reporter / dept_manager | committee_member / external_reviewer | hr_partner_staff | platform_admin |
|---|---|---|---|---|---|---|
| /login, /logout | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| /cases (board), /cases/new | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ (uses /admin tenant switch) |
| /cases/{case} | ✔ visible | ✔ visible | ✖ | read-only if visible (006 adds actions) | ✖ | ✖ |
| document edit / confirm | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ |
| /reports (mine), /reports/new | ✖ | ✖ | ✔ (branch scope) | ✖ | ✖ | ✖ |
| /reports/inbox | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ |
| /my-cases | ✖ | ✖ | ✖ | ✔ | ✖ | ✖ |
| /documents/{d}/pdf, attachment download | if case visible | if case visible | ✖ | if case visible | ✖ | ✔ |

## Case page actions by stage (HR / admin)

| stage | actions |
|---|---|
| draft | edit facts, open case (duplicate check first) |
| open | write defense letter (→ edit screen), assign HR |
| defense_letter_citation_check | back to edit, submit for review (CitationGate) |
| defense_letter_ready_for_review | confirmation screen (second review if required), send back |
| defense_letter_confirmed | issue (from the confirmation screen) |
| awaiting_employee_response | record receipt hand-off, record response hand-off + scan, "no response" (after due) |
| response_received / refused / delivery_refused | upload scan |
| any issued document | download PDF, start correction (reason) |

## 5-dot stage indicator
1 opened · 2 defense letter · 3 response · 4 committee · 5 decision (research R4).

## Pills
red "باقي N يوم" (≤ 2 days) / "متأخرة" (overdue) · amber "بانتظار توقيع" / "بانتظار الرد" ·
green "مغلقة" · indigo stage name otherwise.
