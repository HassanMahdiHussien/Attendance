# Contract: Template blocks and placeholders

## Placeholder allow-list (all document types unless marked)

| placeholder | value |
|---|---|
| `{company_name}` | letterhead name in the page language |
| `{employee_name}` / `{employee_first_name}` | employee full / first name |
| `{employee_code}` | company employee number |
| `{position}` / `{department}` / `{branch}` | employee data |
| `{hire_date}` | Iraqi-style date |
| `{issue_date}` | issuance date (provisional = today in drafts) |
| `{reference_number}` | العدد (provisional in drafts) |
| `{response_deadline}` | defense letter: issue date + response days |
| `{complaint_date}` | case violation time date |
| `{mandate_number}` / `{mandate_date}` | recommendations / decision: mandate in effect |
| `{session_date}` | recommendations / decision |
| `{penalty}` | decision: written warning / termination wording per language |
| `{gm_name}` | decision: General Manager employee |
| `{union_rep_name}` | decision: union attendee of the session |
| `{m:…|f:…}` | gendered alternative (employee gender) |

Unknown names, an unbalanced `{`, or a gender group without both `m:` and `f:` → validation error.

## Blocks per document type (required ✱)

**defense_letter**: title✱, from_label✱, from_value✱, to_label✱, cc_label, cc_value, subject_label✱,
subject_value✱, date_label✱, reference_label, salutation✱, opening✱, deadline_sentence✱,
employee_block (lines, one per row).

**recommendations**: title✱, mandate_sentence✱, lead_in, findings_intro✱, recommendations_intro✱,
role_legal✱, role_hr✱, role_labor_union✱, chair_label✱, member_label✱, signature_line (format of each
attendee line), reference_label.

**decision_letter**: title_warning✱, title_termination✱, from/to/cc/subject/date labels as in the
defense letter, salutation✱, opening✱, defense_outcome_rejected✱, defense_outcome_partial✱,
defense_outcome_accepted✱, decision_sentence✱, gm_title✱, union_title✱, acknowledgment (lines).

Options: `show_footer` (bool, default true), `header_mode` (`logo_text` | `image`). The reference
number and issue date are always printed (fixed layout, not an option).

Required placeholders inside blocks: salutation ⊇ `{employee_first_name}`; opening ⊇ `{hire_date}`,
`{position}`; deadline_sentence ⊇ `{response_deadline}`; mandate_sentence ⊇ `{mandate_number}`,
`{mandate_date}`, `{session_date}`; decision_sentence ⊇ `{penalty}`.

Additional blocks: `prior_record` (defense letter: printed only when `include_prior_record`;
recommendations: printed when prior cases exist). The list lines come from the DB.

Recommendations body: `body` = findings, `secondary_body` = recommendations (both required).

The body (case-specific text) is not a block. It comes from `document_contents.body` per language,
placed between the opening and the closing blocks.
