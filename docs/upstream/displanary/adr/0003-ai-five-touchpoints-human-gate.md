# 0003 — AI has five defined touchpoints, always behind a human confirmation gate

**Status:** Accepted

**Implementation note (008):** Drafting, citation markers, per-claim relevance review, human overrides with reasons, and the confirmation gate were implemented in feature 008. The AI request masks known names as whole words and does not receive personal IDs; the recorded outcome supplies the operative recommendation sentence through the template. A keyword check flags prose for human review.

## Context
The product's legal credibility depends entirely on AI never being seen as the decision-maker in a disciplinary process governed by Iraqi Labor Law. Early design treated the AI as alert-only (citing law articles, never drafting). Later requirements clarified the AI should also do the actual legal drafting (defense letters, recommendations, decision letters) to save HR the repetitive writing work — but the facts and the decision must still always originate from a human.

## Decision
The AI (Claude API) is used at exactly five points: (1) draft the defense letter from HR-entered facts, (2) ingest the employee's scanned response for context only (no drafting), (3) draft the committee recommendations from a committee member's typed decision notes, (4) verify signature presence (not authentication) + close the case + draft the decision letter if a penalty applies, (5) surface side-channel notes/flags during drafting. Every drafting output requires human review and confirmation before printing; documents are locked (immutable) once issued.

## Consequences
**Gained:** the core "eliminate repetitive manual writing" value proposition, while keeping an unambiguous, defensible line: the AI drafts language, humans supply facts and decide outcomes. Legally cleaner than a generic "AI assistant" framing.
**Lost:** more prompt-engineering and UI work than a pure alert-only design — five distinct prompts/flows instead of one, plus a mandatory confirmation screen at every drafting point.
**Revisit when:** the "sensitive case" trigger list or the external legal reviewer's role changes — this ADR assumes the current touchpoint boundaries (see CLAUDE.md Section 3b / PRD Section 26).
