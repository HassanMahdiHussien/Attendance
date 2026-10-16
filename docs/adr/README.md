# Architecture Decision Records — Index

| # | Title | Status |
|---|---|---|
| 0001 | Multi-tenancy via shared schema (`company_id`), not separate databases | Accepted |
| 0002 | Committee membership is a many-to-many pool, not fixed individuals | Accepted |
| 0003 | AI has five defined touchpoints, always behind a human confirmation gate | Accepted |
| 0004 | Issued documents are immutable; edits create new versions | Accepted |
| 0005 | PDF generation via Browsershot (headless Chromium), not DomPDF/mPDF | Accepted |
| 0006 | File storage on a private VPS, not third-party cloud (AWS/GCP) | Accepted |
| 0007 | Discipline ships first, as module 1 of an employee-lifecycle platform | Accepted |
| 0008 | Stay on Laravel + MySQL, not Supabase | Accepted |
| 0009 | Filament v5 with Livewire 4 | Accepted |
| 0010 | Native production runtime and independent encrypted backups | Accepted |
| 0011 | Single local reading for company regulations, with the lawyer as the second check | Accepted |
| 0012 | Ubuntu 26.04 and PHP 8.5 in production | Accepted |

Never delete an ADR. If a decision is reversed, write a new ADR that says "supersedes NNNN" and explains why.
