# Feature Specification: AI Reading of Scans

**Feature Branch**: `009-scan-reading`

**Created**: 2026-10-07

**Status**: Draft

**Input**: User description: "AI reading of scans (CLAUDE.md §3b touchpoints 2 and 4a; §3 signature verification is
presence only; §20 handwritten Arabic responses). (1) Transcribe the employee's handwritten response scan into a
draft text that HR checks against the scan before it becomes case context; the scan stays the record. (2) Suggest,
per committee attendee, whether the signature box on the signed recommendations scan appears filled or empty; HR
confirms and records it. Never identity verification. Manual paths stay."

## Clarifications

### Session 2026-10-07

- Q: Scope? → A: Product owner split the AI work: 008 drafting (done), 009 scan reading (this). Email intake is later.
- Q: Does the AI decide anything? → A: No. Both readings are suggestions a person checks and records (CLAUDE.md §3,
  §20). The transcription is never used until HR confirms it against the scan.
- Q: What if the response scan contains a national ID, salary or phone number? → A: Before an AI reading HR ticks
  that the scan contains none of these; otherwise HR transcribes manually (product owner, 2026-10-07).
- Q: Signature wording? → A: Presence only: "خانة التوقيع تبدو ممتلئة / تبدو فارغة / غير واضحة". Never "verified",
  "authentic" or a signer identity claim (CLAUDE.md §3, §16 item 7).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - HR gets a draft transcription of the handwritten response (Priority: P1)

After the response scan is uploaded, HR presses "قراءة الرد بالذكاء الاصطناعي". In the background the AI reads the
scan and returns the text in its original language, marking unreadable words as [؟]. HR sees the scan and the
transcription side by side, corrects the text, and confirms "راجعتُ النص مقابل الصورة الممسوحة". Only then is the
text stored as the case's response text (with who and when) and the case moves on to "response read". HR may
instead type the text manually, or skip transcription entirely (the committee works from the scan).

**Acceptance Scenarios**:
1. **Given** an uploaded response scan (image or PDF), **When** HR requests a reading, **Then** a draft transcription
   appears for review; nothing is stored as the case's response until HR confirms.
2. **Given** HR edits the draft and confirms, **Then** the case response text equals the edited text, records the
   checker and time, the origin (AI-assisted or manual), and the case moves to "response read".
3. **Given** the AI is unavailable, **Then** HR sees "تعذرت القراءة بالذكاء الاصطناعي — يمكنك كتابة النص يدوياً" and can
   type it; the case is never stuck.
4. **Given** a confirmed response text, **Then** the AI recommendations draft (008) receives it as context with names
   masked, and the committee screens show it labelled "نص منقول — الأصل هو الصورة الممسوحة".

### User Story 2 - AI suggests which signature boxes look empty (Priority: P1)

HR uploads the signed recommendations scan. The AI looks at the signature area of each attendee (by printed name and
role) and suggests "تبدو ممتلئة / تبدو فارغة / غير واضحة". The presence form opens pre-filled with the suggestions;
HR checks against the scan, changes any value, and records it. The existing rules then apply (an empty box keeps
the case waiting; override with reason).

**Acceptance Scenarios**:
1. **Given** a signed scan, **When** the suggestion is ready, **Then** each attendee row shows the AI suggestion and
   HR's choice defaults to it; "غير واضحة" has no default and must be chosen by HR.
2. **Given** HR records the presence, **Then** each signature row records HR as the checker and whether the AI
   suggestion was accepted or changed.
3. **Given** the AI is unavailable, **Then** the form works exactly as today (manual).
4. **Given** any screen or API response about presence, **Then** the wording never claims verification or identity.

### Edge Cases

- A multi-page PDF scan: all pages are read (limit 20 pages); larger files are refused with a clear message.
- A blurry or rotated scan: the AI marks it illegible / unclear; HR proceeds manually.
- A new scan uploaded after a suggestion: suggestions belong to one scan; the form uses the latest scan's suggestion.
- The response text is in Kurdish or English: transcribed in its own language; no translation.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: HR (company admin, or the case's HR user) MUST be able to request an AI transcription of the latest
  response scan while the case is at "response scan uploaded"; it runs in the background.
- **FR-002**: The transcription MUST be stored as a draft attempt (origin, text, illegible flag, AI log) and MUST NOT
  become the case's response text until HR confirms it; confirmation stores the final text, checker, time and origin
  and moves the case to "response read" (`ai_ingested_response`).
- **FR-003**: HR MUST be able to enter the transcription manually (origin human) through the same confirmation.
- **FR-004**: The original scan stays the record; every screen showing the text labels it as a transcription.
- **FR-005**: The confirmed response text MUST be passed (masked, as in 008) to the recommendations drafting payload.
- **FR-006**: Uploading the signed recommendations scan MUST be separate from recording presence, so the AI suggestion
  can run in between; recording presence references that scan.
- **FR-007**: The AI MUST return per attendee: `appears_filled | appears_empty | unclear` with a short reason; the
  form pre-fills filled/empty and leaves unclear unselected.
- **FR-008**: Signature records MUST keep HR as the checker and note whether the AI suggestion was accepted.
- **FR-009**: Presence wording MUST never claim verification or identity (automated wording test over views and
  responses).
- **FR-010**: Only the scan image/PDF and the list of attendee printed names and roles are sent to the AI; every call is
  logged without the scan bytes (attachment id + hash only); failures leave the manual path.
- **FR-011**: Before an AI transcription HR MUST confirm the scan contains no national ID, salary or phone number;
  otherwise only the manual path is offered.

### Key Entities

- **Response transcription**: an attempt to transcribe the response scan (case, scan, origin, text, illegible flag,
  AI log, created by/at); append-only.
- **Signature presence suggestion**: AI suggestion for one signed scan (document, scan, per-attendee results, AI log);
  append-only.

## Success Criteria *(mandatory)*

- **SC-001**: HR gets a draft transcription of a one-page response in under 1 minute.
- **SC-002**: 100% of response texts used by the committee or the AI were confirmed by a named person.
- **SC-003**: No screen, notification or API response describes a signature as verified or authentic.
- **SC-004**: With the AI unavailable, both steps complete manually with no extra clicks beyond today's flow.

## Assumptions

- The scan contains personal data (names, handwriting); it must be sent to read it. This is the minimum needed, is
  logged, and is covered by the Data Processing Agreement (CLAUDE.md §20).
- Model: Claude Opus 5.5 (vision), configurable; PDFs are sent as documents, images as images.
- Decision-letter receipt and GM/union signature presence stay manual in this feature.
