# 0008 — Stay on Laravel + MySQL, not Supabase

**Status:** Accepted

## Context
Supabase (Postgres + auth + storage + realtime + edge functions) was raised as an alternative backend, especially because a Flutter employee app is planned and Supabase has a good Flutter SDK.

## Decision
Keep Laravel + MySQL on the private VPS. Do not adopt Supabase.

## Consequences
**Why:** Laravel already gives auth, file storage, queues, scheduler and an API (Sanctum) for the future Flutter app, and it is the stack this project is being built in. Supabase would duplicate all of that and push business rules into database policies and edge functions, splitting logic across two places — the opposite of CLAUDE.md Section 19. Hosted Supabase is foreign cloud (contradicts ADR 0006); self-hosting it means running and patching many extra services on a small VPS.
**Lost:** Supabase's ready-made realtime and Flutter auth. Neither is needed at MVP; Laravel Sanctum covers app auth later.
**Revisit when:** never for this product, unless the backend is rewritten for other reasons.
