# Feature Specification: Case page and letter polish

**Branch**: `062-case-page-and-letter-polish` | **Created**: 2026-10-21 | **Status**: Approved

**Input**: Product owner review of an incident case (2026-10-21), with a screenshot of an AI-drafted defense letter:
- "why we add this part «وصف خاص بهذا الموظف»? if not necessary remove it … make it an option or edit, not mandatory, no need to be at the beginning and take space"
- "what does «تعارض لجنة: … ارتباط مباشر بطرف في الحالة — يلزم بديل» mean?"
- "«المستندات والإصدارات» should be at the beginning; when HR comes to the case the main thing is to process"
- "«كتاب طلب الدفاع — إصدار 1 AI» must be a draft that can be edited; once accepted it becomes a PDF"
- "the Arabic font is not easy to read; use the one used in official letters"
- "the AI text is bad to read (e.g. «في الموقع المسجّل باسم (pet)، أُفيد بأن بعض مشغّلي…»); this letter goes to the employee, it must speak to him about what belongs to him"

Decisions (2026-10-21):
- Letter font: **Noto Naskh Arabic** (chosen from a side-by-side mockup with Noto Sans Arabic, IBM Plex Sans Arabic and Amiri).
- The case number on letters is a **company setting**, off by default.
- The case number on screens: no change (already shown).

## Requirements

- **FR-001 Per-employee description is optional (incidents)**: the defense letter of an incident case no longer waits for a per-employee description. When the case opens, the defense letter is drafted automatically (as for single cases) from the shared incident facts, with every sentence that names another incident member left out (`IncidentPrivacy::sharedFactsFor`), and the AI is told to address only this employee.
  - The description box moves out of the top of the page into a collapsed, optional «تعديل وصف هذا الموظف (اختياري)» section lower on the page. It has a one-line explanation: the letter goes to one employee only, so the description must not name the others.
  - Saving a description there is still checked: no other member's name. If no defense letter draft exists yet, saving queues the draft. If a draft exists, HR can use "Redraft with AI".
  - Remove the "confirm this employee's description" next-step state and the background proposal job (`ProposeIncidentDescription`, its cache key and its tests), since nothing needs them any more.
  - `AiDrafting::request` and `canRequestAi` accept a blank description. They still refuse a description that names another member.
- **FR-002 Committee conflict notice in plain words, where it matters**: replace the red «تعارض لجنة: … ارتباط مباشر بطرف في الحالة — يلزم بديل» banner.
  - The case page shows a calm notice, for example: «رنا ياسر جبار من أعضاء لجنة هذا الفرع، وهي طرف في هذه الحالة، لذلك لا يمكنها حضور جلسة اللجنة. اختر بديلاً عنها عند تسجيل الجلسة.» It uses the member's grammatical gender when known, and neutral wording otherwise.
  - Name-only matches read: «اسم :name من أعضاء اللجنة يطابق اسم أحد أطراف الحالة. تأكّد أنهما شخصان مختلفان.» The existing verification input stays.
  - The committee-session screen keeps enforcing the substitute and shows the same plain sentence beside the excluded member.
  - The legal rule is unchanged (CLAUDE.md §3 conflict-of-interest check).
- **FR-003 Documents first**: on the case page, «المستندات والإصدارات» moves directly under the stage rail / next-step row, above actions, attachments, related cases and the timeline.
- **FR-004 Drafts look and act like drafts**:
  - An unlocked document reads «كتاب طلب الدفاع — مسودة» (no version number), with a small «صياغة آلية» or «كتابة يدوية» note instead of the bare "AI" pill. It has one action, «تعديل المسودة», which opens the editor. It never offers a PDF or the file viewer.
  - Issued documents keep «الإصدار N», the PDF download and the viewer.
  - The editor and confirmation screens are unchanged apart from FR-005.
- **FR-005 Letter font**:
  - Documents (PDF and on-screen previews, including the confirmation screen's preview card) use Noto Naskh Arabic for Arabic and Kurdish text, from the local `resources/fonts/NotoNaskhArabic-Variable.ttf` (embedded; no network).
  - Size and line height are tuned for reading, about 15–16pt body with line-height ≥ 1.9 in the PDF. Bold weights must render, so check the variable font's 700 weight in Chromium.
  - English pages keep their current Latin face.
  - Update the font references in CLAUDE.md §2/§18, DESIGN.md (frontmatter `fontFamily` and the prose), and add a WEIRDNESS line.
- **FR-006 Letters speak to the employee**: tighten the drafting instructions (`DraftPrompt`) for all documents, and the defense letter especially:
  - Address the employee directly, in the second person, in the employee's recorded grammatical gender.
  - Restate the reported facts in formal Arabic from the company's side («تلقّت إدارة الموارد البشرية بلاغاً يفيد بأن…»). Never copy the reporter's own voice or wording («أُفيد»، «شاهدتُ»، «بعض المشغلين»), and never use filler such as «الموقع المسجّل باسم». Write the place naturally («في موقع خط PET»).
  - State only what the facts say about this employee. When they do not say what this employee did, the letter stays factual and the AI adds a drafting note telling HR so. It never invents an act.
  - The same rules hold in every enabled language, and Arabic stays authoritative.
- **FR-007 Dates without dashes in documents**: `App\Documents\IraqiDate::format` writes «23 تموز 2026» / "23 July 2026" (no « – » separators), and the AI's `incident.date_written` follows it. Screen dates (`Ui::date`) are unchanged.
- **FR-008 Smoother fixed wording (defaults only)**: the defense letter default opening becomes «{m:تعمل|f:تعملين} في الشركة منذ {hire_date}، {m:وتشغل|f:وتشغلين} حالياً وظيفة {position}، {m:وأنت|f:وأنتِ} على علمٍ بمسؤوليات وظيفتك.» The English default changes to match. Companies that edited their template keep their own text.
- **FR-009 Case number on letters (company setting)**:
  - A company setting «إظهار رقم الحالة في الكتب» (off by default) sits in Company settings next to the letterhead.
  - When on, every issued document (defense letter, recommendations, decision letter) shows «رقم الحالة: 0003» in the header block under «العدد» and the date, in every language.
  - Changing the setting never alters already-issued documents. They are immutable, and their stored PDF stays as issued.
  - This needs a migration (a boolean on `companies`, or the existing company settings store if there is one) and an ERD update.
- **FR-010 Tests and docs**:
  - Translations (en + ar), the behaviour inventory and the help guides ("Open a case", "Defense letter", incidents).
  - Tests (FakeClaudeClient):
    - An incident case drafts on open without a description.
    - A description naming another member is still refused.
    - No proposal job is dispatched.
    - The conflict notice text for linked and name-only matches.
    - The documents section comes before the actions.
    - A draft row offers edit and no PDF; an issued row offers the PDF.
    - `IraqiDate` has no dashes.
    - The case number shows on documents only when the setting is on, and an issued document is unchanged after the setting flips.
    - The prompt contains the new rules.
  - Pint and the full suite.

## Out of scope
Changing the legal rules, the approval gates, the 15-day clock, or immutability; the screen (UI) fonts; translating companies' customised templates.
