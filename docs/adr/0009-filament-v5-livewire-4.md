# 0009 — Filament v5 with Livewire 4

**Status:** Accepted

**Approved by product owner:** 2026-10-04

## Context

CLAUDE.md §2 pins Filament v4 for back-office CRUD. The approved project setup installed Filament v5 with Livewire 4. Keeping the older pin would conflict with the actual package baseline.

## Decision

Use Filament v5 with Livewire 4 for back-office screens. This supersedes only the Filament version pin in CLAUDE.md §2. The division between Filament back-office CRUD and custom Livewire case workflow remains in force.

## Consequences

Future Filament resources target v5 APIs and are styled with the project RTL theme. No package change is made by this ADR.
