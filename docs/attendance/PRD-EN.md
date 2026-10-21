# Product Requirements Document — Attendance and Leave Reconciliation System

**Version:** 2.0 — Decisions updated during the 20 October 2026 session  
**Status:** Consolidated business requirements; unresolved calculation details are listed at the end  
**Initial users:** HR and authorized management, supporting more than 1,000 employees  
**Interface:** Responsive web application for desktop and mobile; English is the primary and default language, with Arabic as the second language  
**Document:** English translation of the updated Arabic PRD, retaining its requirements, examples, and open decisions

## 1. Problem and Objective

HR exports attendance and leave reports and compares them to identify employees without attendance or leave covering their absence. With more than 1,000 employees, reconciliation, follow-up, and checking again after notification become burdensome. Older cases can be lost between reports.

The product retains historical data, reconciles attendance, leave, and hours, and rechecks affected cases after each upload. It explains the source and reason for each result and brings each employee's position together on a clear profile page. An alert remains open until supporting data resolves it or an authorized user approves an appropriate action.

## 2. Responsibility Boundaries

- The company's existing system remains the place for submitting and approving leave. This product does not create an alternative leave application process.
- The product imports the leave report and uses its statuses. The first release does not require an employee explanation or response workflow within this product.
- Overtime is calculated in hours only. There are no payment amounts, payment processing, or new financial approvals.
- The external overtime report contains hours already approved and accounted for by the company. This product matches those hours and clears the corresponding outstanding hours and alerts only.
- The product does not infer disciplinary decisions or automatically issue formal requests for employee defense. Initial scope is calculation, reconciliation, and follow-up.
- Employee accounts and a self-service portal are future enhancements, not prerequisites for the first release.
- AI interpretation supports analysis; all calculations, balances, and adjustments follow auditable rules.

## 3. Users and Permissions

### Languages and Display

- English is the primary and default interface language. Users can switch to Arabic as the second language.
- Localization covers pages, dashboards, profiles, search, settings, chat, validation messages, alerts, email templates, and report headings.
- English uses a left-to-right (LTR) layout; Arabic uses right-to-left (RTL), with correct display of employee IDs, email addresses, times, and mixed-language text.
- The system saves each user's language preference. Switching languages does not change data, formulas, or calculation results.
- Chat supports English and Arabic. Report, export, and notification-template language choices must be clear. Employee names and original descriptions must not be automatically translated in a way that changes source information.
- The Arabic PRD remains available for review; the language of the requirements document does not determine the product's interface language.

### Roles

- **HR:** Upload reports; maintain employee data, schedules, and policies; review exceptions; approve holidays; correct punches or confirm absence; send notifications and generate reports.
- **Authorized management:** Make inquiries, generate reports, and perform changes permitted by their role, through either the interface or chat.
- **System administrator:** Manage users, permissions, and operational settings.
- **Employee:** Initially has no system account. Receives email notifications where possible, while HR explains the reconciliation statement.
- Chat applies the same permissions as the interface. It does not create additional privileges or expand access to employee data.

## 4. Data Sources

| Source | Purpose |
| --- | --- |
| Attendance transaction report | Check-ins, check-outs, restaurant punches, devices, and timestamps |
| Detailed leave report | Request ID, employee, duration, dates, type, status, rejection, and description where available |
| Active employee file | Define the population to analyze and update employee data |
| Email and work-pattern Excel file | Contact details; office/shift/security classification; rest day where applicable |
| Approved and accounted-for overtime report | Match and clear outstanding overtime |
| Official holiday announcement email | Extract, review, and approve holiday coverage |

Records are matched by employee ID, not name. Names support display, search, and identity resolution only. IDs are stored as text to preserve leading zeros. Original files, upload time, uploading user, source rows, errors, and coverage scope are retained.

The attached reports are design samples, not synchronized operational datasets. The monthly annual leave balance report does not replace a daily leave request report. A sample containing request descriptions, an overtime report sample, and employee/email files are still needed to finalize import mappings.

## 5. Employee Data and Updates

Employee records contain employee ID, name, department, location, manager, email, the fallback manager email designated by HR, work pattern, rest day, joining date, last working day, employment status, and the effective date of each change.

- Changes can be made from the employee page or through a bulk Excel upload.
- Work classification is defined in the system or file: office, shifts, or security.
- The employee upload contains the full active employee population, not only changed records.
- A new version is uploaded when updates exist. No upload means the current list remains in effect.
- If an employee disappears from a new complete list, the system alerts HR and does not automatically end their service.
- The last recorded attendance date is proposed as the last working day. HR may enter another date manually and must confirm the result.
- Deactivation does not delete employee history or transactions.
- Absence is not assessed before the joining date or after the approved last working day.
- The impact of a bulk update is shown before application, including missing employees and changed fields.

## 6. Work Patterns

| Category | Working hours | Weekly rest | Declared break |
| --- | --- | --- | --- |
| Shift employees | 00:00–08:00 / 08:00–16:00 / 16:00–00:00 | Friday | 30 minutes |
| Office employees | 08:00–17:00, subject to the flexibility below | Friday and Saturday | One hour |
| Security employees | The same three shifts | An individual rest day defined in the system | No assumed break is deducted from calculations |

Shifts rotate, and there is currently no approved weekly roster to upload. The system infers the shift using punches, leave, leave duration, and descriptions together. Matching arrival to the nearest shift start is insufficient because partial-day leave can occur at any point in the shift.

If multiple reasonable interpretations remain, HR must determine the shift manually; the system must not confirm speculative lateness. Where there are no transactions, the system may identify an uncovered day if it is a known working day, without claiming to know an unverified shift.

The system retains the inferred shift, supporting evidence, clarity of the inference, and any manual change. Work-pattern and working-hour changes have effective dates and must not silently alter historical results.

### 6.1 Office Flexibility

- Arriving at 08:00 and leaving at 17:00, or arriving at 09:00 and leaving at 18:00, is acceptable.
- Arrival between 08:00 and 09:00 shifts the required departure time by the same amount: 08:30 corresponds to 17:30.
- Arrival after 09:00 is late. Arriving at 09:15 results in 15 minutes of lateness, even if the employee stays later.
- The required departure rule for arrivals after 09:00 remains to be finalized, as listed under outstanding details. The system must not guess it.

### 6.2 Shift Lateness

There is no declared grace period. Arrival after the shift start counts as lateness. For example, 08:01 for an 08:00 shift means one minute late, after considering applicable leave and exceptions.

### 6.3 Crossing Midnight

Attendance is assigned to the shift's start date. Monday 16:00 to Tuesday 00:00 belongs to Monday. Full timestamps are retained and transactions across midnight are linked. A 00:00 check-out must not automatically be interpreted as a new shift's check-in.

## 7. Importing, Coverage, and Duplicate Prevention

- Accept different periods and overlapping files uploaded in any chronological order.
- Before confirmation, show rows, employees, declared period, scope, duplicates, unknown values, changes, and errors.
- Re-uploading a file must not duplicate transactions, cases, entitlements, consumed hours, or notifications.
- Retain the external request identity so a status change updates the existing request instead of creating a new one.
- A request disappearing from a filtered report does not mean it was cancelled. Cancellation requires source evidence or authorized review.
- The first and last transaction do not prove report completeness; record the declared coverage scope.
- An unfinished day or shift, or one with incomplete data, remains pending completion. Do not prematurely confirm absence or a missing punch.
- Recalculate only affected employees, days, cases, and balances, retaining the differences between results.

## 8. Automatic Punch Cleanup

HR should not be asked about every repeated device punch. The system automatically handles clear repetition while preserving the original records.

- Exact duplicate rows are counted once in calculations while retaining traceable source references.
- Two consecutive check-ins without a check-out between them, even 15 minutes apart, do not create two work intervals or two separate missing-punch cases. Use the first valid check-in for the interval and mark subsequent check-ins as repetition according to the sequence.
- Repeated check-outs are handled within the same group, using the last valid check-out where the sequence supports that interpretation.
- A valid check-out between two check-ins may indicate two actual work intervals. Do not merge them merely because their times are close.
- Do not merge restaurant punches with attendance punches or transactions belonging to separate shifts.
- Request review only where actual ambiguity prevents calculation, not merely because a repeat check-in or check-out can be resolved automatically.
- Retain each transaction's original value, included/excluded status, repetition group, and processing reason.
- Cleanup must not inflate work hours, erase a genuine exit, or move a transaction to another shift without supporting evidence.

## 9. Time Fields and Calculations

Display two fields for each attendance session:

1. Elapsed time between the first check-in and last check-out.
2. Total recorded presence: the sum of valid check-in/check-out intervals.

Use recorded intervals without deducting an assumed break. Show a short-hours alert when total recorded presence is below eight hours, including the shortfall. Office arrival/departure obligations are assessed separately and are not automatically satisfied merely by reaching eight hours.

Calculate lateness and early departure minutes separately. Combine uncovered hours into one balance requiring reconciliation. Whether temporary exits count toward this balance is configurable.

The same minutes must not be counted twice, for example as both early departure and short hours. Detailed observations may remain separate, but the total requiring reconciliation must contain non-overlapping time only.

Staying beyond the end of the workday does not automatically cancel lateness. Lateness and overtime remain separate. Authorized management may record a reconciliation decision and its reason.

## 10. Missing Punches

For check-in only or check-out only, retain the available transaction and record the day as absent pending review, clearly distinguished from a final confirmed absence.

Only HR may choose either action:

- Approve a correction and manually enter the missing time, then recalculate attendance, lateness, and early departure.
- Confirm absence while retaining the original punch.

Record the decision, user, timestamp, reason, and before/after values. A manual time entry must not replace the original device record or appear to be a device-generated punch.

## 11. Leave and Reconciliation

Retain the request ID, employee, type, start/end dates, duration, status, creation date, rejection date, and description. A rejected request does not cover absence or hours, and its rejection date is retained. Do not rely only on an approval-stage column if the rejection field establishes that the request is rejected.

A pending request does not finally close a case. Follow-up moves to the person currently holding the approval. An approved request that fully covers the case automatically closes it, retaining the reference, reason, and closure time.

The company system offers a full day equal to eight hours or half a day equal to four hours. A half-day may occur at any time, commonly at the beginning or end of the workday. Do not assume a fixed position without evidence.

### 11.1 Linking Leave to Attendance

| Situation | Action |
| --- | --- |
| Full-day absence with matching approved full-day leave | Reconcile the absence |
| Half-day shortfall with matching approved half-day leave | Cover the shortfall |
| Leave on a day with attendance and outstanding lateness/early departure hours | Allocate leave hours to reconcile the balance, even without a description |
| Leave on a day with attendance and no hours requiring reconciliation | Raise an alert and note for HR; do not invent a purpose for the request |

Descriptions guide matching and explain intent. Leave plus attendance must not always be treated as an unresolved conflict: some requests intentionally cover accumulated lateness.

Lateness is aggregated whenever reports are uploaded. HR chooses the reconciliation period; it is not necessarily a calendar month. Re-uploading must not duplicate existing obligations. Reconciliation does not require another internal approval once a matching, externally approved request exists.

### 11.2 Surplus Hour Credit

If three hours require reconciliation and an approved four-hour half-day is available, reconcile the three hours and retain one hour as credit for the employee. This is a confirmed decision, not an open question.

- Clearly show the credit balance, its source, and its allocations.
- Its validity period is configurable, including no expiry.
- Do not automatically use it against new lateness. The request or authorization mechanism for allocating existing credit will be finalized during implementation.
- Do not automatically convert surplus hours into paid overtime or rest-day compensatory leave.
- Prevent spending the same hour twice. Every allocation must link its source and target.

### 11.3 Reversing Reconciliation

When cancellation is established by the source, reverse the request's allocations and recalculate balances. Previously covered lateness becomes uncovered again and its alerts reopen automatically. If credit originating from the request was subsequently used, recalculate the dependent effects and flag exceptions. No credit may remain without a valid source.

## 12. AI Interpretation of Descriptions

AI reads every request description, not only compensatory leave. It distinguishes, for example, compensation for rest-day work, coverage of lateness, an external assignment, a description of work performed, text unrelated to reconciliation, or an ambiguous explanation.

Its output includes the proposed meaning, mentioned dates/hours/parties, supporting text, information needed for verification, and any conflicts or ambiguity.

Interpretations are checked against punches, policies, and requests. A description of work alone does not prove attendance or an approved assignment. Employee text is not an instruction to change the system or bypass permissions. Ambiguous text must not generate a confirmed credit through guesswork.

Missing descriptions do not prevent the approved reconciliation rules in Section 11 from applying. Retain the description version, analysis used, and matching outcome so the decision can be explained later.

## 13. Rest Days and Compensatory Leave

| Category and day | Overtime | Compensatory leave |
| --- | --- | --- |
| Office: Friday | Recorded work hours | Yes, subject to configured conditions |
| Office: Saturday | Recorded work hours | No |
| Shift employees: Friday | Recorded work hours | Yes, subject to configured conditions |
| Security: individual designated rest day | Recorded work hours | Yes, subject to configured conditions |
| Official holiday that is not an eligible compensatory rest day | Recorded work hours | No |
| Official holiday coinciding with an eligible compensatory rest day | Recorded work hours, counted once | Yes, subject to configured conditions |

- Working both Friday and Saturday is not required to qualify for Friday's entitlement.
- Minimum qualifying hours and other entitlement conditions are configurable.
- When conditions are met, the entitlement becomes available automatically without additional internal approval.
- The dashboard shows the number of entitled employees, total available entitlements, and details.
- If unused in the following week, the entitlement remains available with an alert; it does not automatically expire.
- When approved compensatory leave appears, link it to the originating workday, consume the entitlement, and clear the alert.
- If the leave is cancelled and the cancellation is established by the report, automatically restore the entitlement and retain its history.
- The same entitlement cannot be consumed twice. A second request exceeding the available balance is flagged for review without creating additional entitlement.
- If the quantity policy supports half-days, track available and consumed quantities rather than assuming any two requests are necessarily duplicates.

## 14. Overtime

- Calculate overtime from punches and display hours only, separately from lateness.
- Extra work on a normal working day does not generate compensatory leave.
- Rest-day and holiday work follow the preceding table, without counting the same hours twice when reasons overlap.
- The external overtime report contains hours already approved and accounted for by the company.
- Match by employee, date, hours, and available reference.
- Clear the matched portion from outstanding hours and alerts, while retaining total calculated overtime and the matching history.
- A partial match leaves the remaining hours visible with an alert only.
- Unreported overtime does not create an automatic claim or escalation. Normal-day thresholds, rounding, and allowances must follow a configurable policy.

## 15. Restaurant Punches

Code `255` in the sample represents restaurant activity, not work check-in or check-out. Exclude it from attendance, lateness, absence, and overtime calculations.

Count each employee once per day in restaurant usage. Repeated punches do not increase the count and produce a repetition note. Preserve the original records and distinguish import-row duplication from an actual repeated punch.

Visitors are a separate category and are not automatically included in the employee population to be assessed. For new sources, approve the code/device mapping before use.

## 16. Official Holidays

HR uploads the official announcement email. AI extracts dates, locations, employee groups, and exceptions and displays them alongside the source email.

The holiday is not applied until HR reviews, edits if necessary, and approves it. Retain the approval and scope. Retroactive changes trigger reprocessing of affected days and display result differences without silent changes.

## 17. Cases and Notifications

- Create traceable cases linked to days, requests, and balances, without duplication on re-upload.
- Authorized users choose between automatic sending and HR review followed by manual sending.
- Email is the initial notification channel.
- Import email addresses from Excel. If an employee has no email, HR designates a fallback manager email for all their notifications.
- For a submitted but pending request, remind the current approver, not the employee who has already completed their action.
- Identifying the current approver requires actual data. Missing information creates a data issue for HR; do not guess an approver.
- Consolidate relevant employee cases into one email per manager per sending batch, including employee name, date, issue type, and required action.
- Reminder timing and repetition limits are configurable. Record attempts, successes, failures, and actual recipients.
- Sending does not close a case. Approved leave covering the case fully closes it automatically, with a closure record.
- An unmatched overtime alert does not become automatic follow-up or escalation.
- Device outages or shared coverage gaps create a grouped data issue rather than attributing absence to every affected employee.

## 18. Employee Profile

Every employee has one simple, readable profile. It opens with numerical summaries for the selected period, with details available by selecting each figure.

Summary metrics include uncovered absence; lateness; early departures; total hours requiring reconciliation; settled and remaining hours; missing punches; calculated, matched, and outstanding overtime; available, used, and overdue compensatory entitlements; surplus hour credit; and open cases.

Details include the daily record, original and cleaned punches, shift and determination source, leave requests/statuses/descriptions, hour allocations, compensatory balances, alerts and messages, actions and changes, and supporting sources.

Example printable explanation: Three hours were required for the listed dates. Request X was approved for a four-hour half-day. Three hours were allocated to reconciliation, leaving one hour of employee credit. State whether the link came from the description or the automatic rule.

Historical facts remain visible after reconciliation. Their coverage status and explanations change so HR can answer employee questions.

## 19. Dashboard

Show the current position as of the latest import and processing run, not live attendance unless a direct integration is introduced later. Clearly display each source's last update, selected period, and coverage quality.

Metrics include active employees, uncovered absence, lateness and early departure, required/settled/remaining hours, missing punches, cases waiting for HR or managers, compensatory entitlements and eligible employee counts, outstanding overtime, restaurant usage, and data issues.

Every figure opens a detailed list using the same filter. Period, location, department, manager, work pattern, status, and employee filters must be consistent and clear. Do not present an ongoing day as complete without justification.

## 20. Search and Reports

- Provide an obvious search entry point for employee name or ID, supporting spelling variations and displaying location/department to distinguish similar names.
- Filter multiple employees or groups and generate a report through a clear action or chat.
- Reports cover absence, lateness/early departure/reconciliation, missing punches, overtime, compensatory leave, hour credit, restaurant usage, cases, and a comprehensive employee statement.
- Offer detailed Excel exports and a clear printable statement for explaining results to employees.
- Reports include period, filters, latest update, totals, supporting rows, linked requests, and calculation/decision explanations.
- Distinguish settled and unsettled results. Totals must agree with report detail, employee profiles, and the dashboard.
- Exporting does not send an email unless an authorized user explicitly requests sending.

## 21. Administrative AI Chat

Chat is an additional way to operate the product alongside its screens. It understands natural conversation in English and Arabic and uses actual system tools rather than merely telling the user to visit settings.

### 21.1 Inquiries

Examples: “How much lateness does employee 23000123 have?”, “Show absence details for these employees”, or “How many compensatory entitlements are available in this department?”

Return answers from actual records, including period, last update, totals, and accessible details. Do not invent results when information is missing or claim changes/reports were completed without a successful tool result.

### 21.2 Changes

Example: “Change this employee's workday to end at 17:00.” Resolve the employee, identify the requested fields and effective date, and execute an authorized change from chat without requiring the user to open settings.

- Execute clear, specific, reversible requests within the user's permissions, then show the change, previous/new values, and effective date.
- Ask only for missing information when similar names or material ambiguity exist. Do not guess the employee or change unrequested fields.
- Changing the end time alone does not automatically change work classification, rest days, or start time.
- For bulk changes, show affected employees and impact for review in chat before applying, without directing the user to settings.
- HR-only permissions, such as missing-punch correction, remain HR-only in chat.
- Recalculate affected days and show differences. Log every change, actor, and source, with reversal through a documented action.

### 21.3 Report Generation

Examples: “Create a lateness report for these ten employees”, “Export the details to Excel”, or “Compare settled and outstanding hours for this department.”

Generate the actual file using the selected employees, period, and fields, and provide a download link. Support follow-up requests to refine filters, add columns, or move from totals to details.

### 21.4 Trust Controls

The interface and chat use the same calculation rules and services. Financial formulas or alternative policies must not exist only inside model prompts. File content and employee descriptions are analysis inputs, not administrative commands. Log change requests and outcomes; restrict inquiries to the user's authorized scope.

## 22. Information Model and Audit

Keep separate records for employees and their historical versions, import batches, raw transactions, processed transactions, attendance sessions and shifts, lateness/shortfall observations, leave requests and statuses, hour allocations, rest-day entitlements and their consumption, overtime matches, follow-up cases, and notification deliveries.

These are not mutually exclusive daily states. A day can include attendance, lateness, and partial leave while its case is waiting for a manager. Preserve each fact in its appropriate dimension.

Audit records identify who did what, when, and why; before/after values; evidence source/version; and recalculation outcomes. Original records and consumed balances must not be erased without a documented reversal. Preserve processing-run results and policy versions so historical outcomes can be explained.

## 23. Operational and Privacy Requirements

- Support more than 1,000 employees and several years of history, with resumable importing/processing and no data loss or duplication.
- Restrict viewing, editing, and export by role, location, and authorized scope.
- Do not email sensitive medical details. Include only information needed for follow-up.
- Provide backups and tested restoration, original-file retention, and an approved retention policy before operational use.
- Failure to analyze a description or send an email must not discard transactions or corrupt balances. Show the cause and allow retry.
- Set import-time and response-time targets after testing representative file volumes, rather than making unmeasured numerical commitments.

## 24. Delivery Scope and Sequence

### Phase One: Core and Internal Pilot

Deliver importing, employee lists/settings, punch cleanup, shifts and leave, calculation/reconciliation/balances, profiles, dashboard, search, exports, and audit. Begin a parallel trial against the existing process using a sample with known outcomes.

### Phase Two: Complete Operations

Add overtime and compensatory matching, consolidated notifications/follow-up, description and holiday analysis, and testing of cancellation, re-uploads, and late updates. Administrative chat is part of the required product: start with inquiries/reports, then enable changes through the same system services once stable.

### Later Enhancements

Employee accounts, self-service portal, mobile notifications, and direct integrations. These must not block the internal release or turn the product into a replacement for the company's leave system.

## 25. Acceptance Criteria and Test Cases

1. Re-uploading the same or overlapping files does not duplicate balances or counts.
2. Updating a request status under the same ID reconciles affected days and preserves the previous state.
3. Consecutive check-ins 15 minutes apart without an intervening check-out do not create two intervals or unnecessary review.
4. Actual check-out/check-in intervals are not erased as duplicates.
5. Restaurant punches do not prove work attendance; count an employee once daily and note repetition.
6. An incomplete ongoing day does not confirm a missing punch or final absence.
7. Cross-midnight shifts belong to their start date without duplicating a shared transaction.
8. Partial leave is not forced to the beginning or end of a shift without supporting evidence.
9. Genuine shift ambiguity is referred to HR, and calculation reruns after determination.
10. Office arrival at 09:00 and departure at 18:00 is not late; 09:15 means 15 minutes late.
11. A single check-in or check-out creates absence pending review; correction is restricted to HR.
12. Lateness and early departure remain separate in detail but combine without double counting for reconciliation.
13. Four hours of leave settle three required hours and retain one hour of credit; it is not automatically used for later lateness.
14. Leave plus attendance with no hours requiring settlement raises an alert instead of creating a fictitious reconciliation.
15. Cancellation reverses leave allocations and restores relevant hours and alerts.
16. Qualifying rest-day work automatically creates an available entitlement that remains after the following week with an alert.
17. Office Friday work gives compensatory entitlement and overtime; Saturday gives overtime only.
18. An official holiday coinciding with an eligible rest day does not double overtime hours but also permits compensatory entitlement.
19. A second compensatory request exceeding the balance is reviewed without creating duplicate entitlement.
20. Confirmed cancellation of compensatory leave restores the entitlement automatically.
21. An overtime report covering two of three hours clears two and leaves one hour with an alert only.
22. An employee missing from the latest list is not deactivated before HR confirms the last working day.
23. Approved leave closes the case; pending leave directs reminders to the current approver.
24. The fallback manager receives one consolidated email per batch, not one message per employee.
25. A holiday extracted from email does not apply before HR approval.
26. Profiles, dashboards, reports, and chat agree on figures and periods.
27. Chat resolves names/IDs, asks about ambiguous identities, executes authorized changes only, and logs them.
28. Changing an employee's end time does not change rest days or classification without an instruction to do so.
29. Chat-generated reports are actual downloadable files whose details match source data.
30. Instructions embedded in employee descriptions cannot execute administrative changes or leak data.
31. The system defaults to English and can switch to Arabic with RTL without changing numbers, balances, or permissions; screens, reports, and templates use the selected language.

## 26. Details to Finalize Before Approving Calculations

These details do not require restarting discovery. Resolve them using sample files, a short policy definition, and real-case testing:

- Mapping of descriptions and cancellation statuses, current approver identity, and unknown leave types.
- The mechanism for allocating previous hour credit when its use is requested. It remains credit and is not automatically consumed.
- Normal-day overtime thresholds, rounding, allowed early arrival, and the required office departure time after arrivals later than 09:00.
- Compensatory entitlement quantities and half-day rules if supported, plus the calendar definition of the following week.
- Allocation priority within a reconciliation period when descriptions are missing, and treatment of requests whose hours are less than the matching absence, without guessing.
- Supported holiday email upload formats and file types, and travel/work-from-home authorization evidence in the actual reports.
- The effective date of a chat change when the user does not specify one: establish and display a clear default before operational adoption.
- Hosting, backups, and management role scopes approved by the employer.

## 27. Sample File Observations

The original attendance sample contains 4,828 transaction rows and 578 distinct IDs, including 907 additional exactly duplicated rows, 1,201 restaurant transactions with code `255`, and 204 visitor transactions across 14 IDs. These describe the sample and are not fixed system assumptions.

The detailed leave sample contains 21,249 requests and 1,027 employees. The rejection field may contain a date while the status column still shows an approval stage; interpret this using the agreed rejection rule. Implausible dates must be isolated from calculations. Different periods across sample files do not prove actual absence or missing leave applications.

## 28. Decision Record for This Version

Where requirements conflict, this version supersedes the earlier comprehensive draft. Key changes are: removing the initial dependency on employee accounts and responses; using company reports as the source for requests; automatic repeated-punch cleanup; surplus hour credits; Friday/Saturday and compensatory rules; clearing approved overtime without monetary calculations; fallback manager email and consolidation; readable employee profiles and dashboards; and administrative chat for inquiries, changes, and report generation. English is the default product language and Arabic is the second language.
