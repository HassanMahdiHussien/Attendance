# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **HR staff and HR managers** in Iraqi private-sector companies are the primary users. They run disciplinary
  cases every working day from a desktop or laptop at the office: reviewing incoming reports, opening cases,
  drafting and issuing letters, recording paper hand-offs and signatures, tracking legal deadlines.
- **Supervisors, engineers and department managers** (reporter role) submit violation reports about their team,
  mostly from a phone on the factory or site floor, and only see the bare status of their own reports.
- **Committee members** (legal, HR and labour-union representatives named in the company's Administrative Order)
  approve or reject the committee recommendations occasionally, from phone or desktop.
- **The company's own lawyer** approves the company's internal regulation, article by article.
- **The platform owner** (Platform Admin) manages all tenant companies and the shared legal library.
- Employees who are subject to a case have no account in this phase; everything with them happens on paper.
- Arabic is the main working language; English is fully supported and the default interface language. Kurdish
  (Sorani) is a per-company document language, off by default.

## Product Purpose

Run an employee disciplinary procedure end to end under Iraqi Labour Law No. 37/2015 (federal Iraq) or Law
No. 71/1987 (Kurdistan Region): report → case → defense request letter → employee response → committee session →
committee recommendations (three approvals) → decision letter → appeal window → archive. Facts are entered once and
pulled into every document. Success means no legal deadline is missed, every step is provable after the fact, and
HR stops retyping the same facts into several legal letters.

## Positioning

A trusted legal process tool, not generic HR software. The AI drafts legal Arabic only from facts and decisions
humans entered, cites law articles only by verified id from a curated library (it never types article text), and
every draft passes a human confirmation gate. Deadlines are calendar-day exact and anchored to the Administrative
Order's rules; documents are immutable once issued; every action is in an append-only audit log. Calm, precise,
legally safe.

## Operating Context

- Official delivery is always on paper: letters are printed, signed (or refused before two witnesses), scanned and
  uploaded. Email and WhatsApp are alerts only.
- Legal deadlines drive the work: 3-day response, 15-day procedure (from the defense letter's issue date), 30-day
  appeal window; all calendar days.
- Every issued document carries several languages in one physical document (Arabic page, English page, optional
  Kurdish), with Arabic governing; it gets an official reference number at issuance.
- The committee is a pool per role (legal / HR / labour union) from a versioned Administrative Order; quorum needs
  three with a union member present.
- Multi-tenant SaaS: each company sees only its own data; HR partners see aggregate stats; an external partner law
  firm sees only cases where consultation was requested.

## Capabilities and Constraints

- Web app (Laravel, Livewire, Tailwind; Filament for admin/back-office screens), responsive for phone use; no
  native app in this phase.
- Full right-to-left Arabic interface and left-to-right English; Western digits everywhere.
- Printed legal documents are rendered separately (HTML → PDF with an Arabic Naskh document font) and must look
  like formal Iraqi official correspondence; they are not part of the app-interface redesign.
- AI is unavailable-tolerant: every drafting step has a manual "write it yourself" path.
- Terminology: case (حالة), report (بلاغ), defense request letter (طلب دفاع), committee recommendations (توصيات
  اللجنة), decision letter (كتاب القرار), Administrative Order (الأمر الإداري), reference number (العدد).

## Brand Commitments

- Name: **Displanery** (Arabic: **نظام الانضباط**) is final.
- The logo/mark and the whole visual identity are open to redesign (2026-10-14).
- Voice: calm, precise, plain-spoken, respectful of the employee; never alarmist, never playful about discipline.

## Evidence on Hand

- Pilot company: Al Waha (anonymised in seed data, Hilla branch), with its real Administrative Order and sample
  letters (`docs/`; real employee records in `docs/attachments` must never be committed or shown).
- No customer testimonials, logos, metrics or pricing exist yet; do not fabricate any.

## Product Principles

1. The human decides; the system records and drafts. Every AI output is visibly a draft until a person confirms it.
2. Deadlines first: what is due, for whom, and when must be the most visible thing on any working screen.
3. Enter once, reuse everywhere; never make a user retype a fact the system already holds.
4. Every action is provable later: state, actor and time are always recoverable and shown where it matters.
5. Arabic and English are equal citizens; right-to-left is native, not an afterthought.

## Accessibility & Inclusion

Keyboard-reachable controls and visible focus; WCAG AA contrast; works at 375 px; RTL-correct layouts; legible
Arabic text sizes for long reading on the document review screens.
