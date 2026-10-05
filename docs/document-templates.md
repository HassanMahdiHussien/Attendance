# Document templates — structure from the pilot samples

Anonymised analysis of the pilot company's real letters (kept locally in `docs/attachments/`, which is
git-ignored because the files are real employee records). The document feature builds three templates
from this structure. Every issued document is one PDF: the Arabic page first, then English, plus any
extra enabled language.

**Customisation (product owner decision, 2026-10-05):** the layout skeleton is fixed and matches the
samples. Each company can change its letterhead and these text blocks per language: header labels,
fixed paragraphs, signer titles and on/off options. Placeholders are filled from the database. Template
changes are versioned. An issued document keeps the text it printed (ADR 0004). Facts and decisions
are never part of a template; they come from HR / committee input through the AI draft or manual
writing.

## Shared page frame (all three documents)

- **Letterhead:** company logo at the top left, company name and address block at the top right
  (Arabic text on the Arabic page, English on the English page). In the samples it is red and small:
  name, region, road, phone, email.
- **Footer:** `CONFIDENTIAL` centred in red. Editable text, on by default.
- **Missing from samples, required by CLAUDE.md §3:** official reference number (العدد) and issue date,
  editable on the confirmation screen.
- **Dates:** Arabic uses Iraqi (Levantine) month names, day-month-year: `18 – حزيران – 2026`.
  English uses `18 – June – 2026`.
- **Fonts:** Amiri / Noto Naskh Arabic for Arabic, a serif or sans font for English (ADR 0005).

## 1. Defense request (طلب دفاع / DEFENSE REQUEST)

| block | Arabic sample wording (template default) | placeholder data |
|---|---|---|
| title | طلب دفاع | — |
| header: from | من: اللجنة الانضباطية | company setting |
| header: to | إلى: {employee_name} | employee |
| header: cc | بالإشارة الى قسم إدارة الموارد البشرية، المدير القانوني | company setting |
| header: subject | الموضوع: طلب الدفاع | — |
| header: date | التاريخ: {issue_date} | issuance |
| salutation | عزيزي السيد {first_name}، / عزيزتي السيدة {first_name}، | **employee gender required** |
| opening | أنت تعمل مع الشركة منذ {hire_date} وظيفتك الحالية هي {position} وأنت تعرف مسؤولياتك جيداً. (feminine: أنتِ تعملين … وأنتِ تعرفين) | employee |
| body | facts of the case: complaint date, listed violations | **AI draft or manual, from HR-entered facts** |
| deadline | يرجى تزويدنا بخطاب الدفاع الخطي الخاص بك عن هذه القضية حتى {response_deadline} | issue date + 3 calendar days (config) |
| prior record | only if HR ticks "include prior record" (CLAUDE.md §24.4) | cases |
| employee block | اسم الموظف: / القسم: / التوقيع: | blank lines for the paper hand-off |

The English page mirrors it with a From / TO / CC / SUBJECT / DATE grid, "Dear Mr/Ms {first_name},"
and an "Employee Name / Position / Signature" block. One English sample still contains a stale
second deadline line. Generated documents compute the deadline once, so this cannot recur.

## 2. Committee recommendations (توصيات اللجنة / Recommendations of the Disciplinary Committee)

| block | default wording | data |
|---|---|---|
| title (underlined) | توصيات اللجنة الانضباطية | — (the samples say "التحقيقية"; PRD §25.1 unified the name as "Disciplinary Committee", and the wording is editable) |
| mandate sentence | استناداً الى الامر الاداري المرقم ({mandate_number}) في {mandate_date}، اجتمعت اللجنة بتاريخ {session_date} للتحقيق فيما يتعلق بقضية الموظف/الموظفة {employee_name} وتبعاتها. | mandate in effect on `defense_letter_issued_at`; session date |
| lead-in | حيث تمت الإجراءات واتخذت القرارات ادناه: | — |
| findings | بناءً على كتاب طلب الدفاع … تبيّن للجنة ما يلي: then numbered or bulleted findings | **AI draft / manual, from the committee's typed notes** |
| prior record (السوابق) | short paragraph listing prior cases by date, type and outcome | **from the database only** (§24.4) |
| recommendations | وبناءً على ما تقدّم، توصي اللجنة بما يأتي: then bullets | committee decision (penalty or non-penalty) |
| signatures | one line per attendee: `- السيد {name} - {role}: {رئيساً/عضواً}` | attendance records; the chair defaults to the Legal attendee |

Role labels: القانونية / الموارد البشرية / ممثل نقابة العمال. English: Legal / Human Resources /
Labour Union Representative; Chairman / Member.

## 3. Decision letter (إنذار خطي / WARNING LETTER; same template for termination)

| block | default wording | data |
|---|---|---|
| title | إنذار خطي / إنهاء خدمة | `penalty_type` |
| header | as the defense letter (subject = the title) | — |
| salutation + opening | as the defense letter | employee |
| defense outcome | تم توجيه كتاب طلب دفاع إليك بخصوص … وبعد دراسة إفادتك التحريرية ومراجعة نتائج التحقيق، قررت اللجنة عدم الأخذ بالدفوع المقدمة من قبلك … | **`defense_acceptance` (full / partial / rejected) is a mandatory explicit field** (PRD §18.2) |
| reasons | the penalty reasons | from the approved recommendations (AI / manual) |
| decision sentence | وعليه، تقرر توجيه عقوبة {penalty} بحقك … | penalty type + cited article ([[cite:id]]) |
| signatures (side by side) | المدير العام: {gm_name} · ممثل نقابة العمال: {union_rep_name} | `employees.is_general_manager`; union attendee |
| acknowledgment | لقد قرأت هذا الانذار / أوافق بموجب هذه الشروط والأحكام / وقعت …… / بتاريخ …… | blank lines |
| termination only | HR confirmation "employee is not on statutory leave or documented sick absence" before issuance | recorded who and when (CLAUDE.md §3) |

## Data the templates need that the schema lacks today

- `employees.gender` (male / female). Required for Arabic grammar; needed before any document can
  be issued.
- Company letterhead per language (name and address lines in ar and en; today there is one address
  field).
- Per-company template text blocks, versioned per document type and language.
