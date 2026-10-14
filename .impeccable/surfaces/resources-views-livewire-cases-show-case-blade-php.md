---
version: 1
slug: "resources-views-livewire-cases-show-case-blade-php"
primary_target: "resources/views/livewire/cases/show-case.blade.php"
related_targets: ["resources/views/layouts/workspace.blade.php"]
---

## Scope

Case page (`resources/views/livewire/cases/show-case.blade.php`) in the workspace shell (`resources/views/layouts/workspace.blade.php`). Operate mode. HR staff on a desktop in a daylight office for long sessions; supervisors and committee members on phones. Arabic (RTL) and English (LTR).

## Direction contract

THESIS: A case runs like a timetable. What is due next, from which stage, and how long until it departs is the first thing read; the page refuses the category default of a header card above equal-weight cards where deadlines are one tile among many.

OWN-WORLD: Baghdad Central Station departures boards and Iraqi Airways timetables. Midnight #0E1A2B shell bar and #16263D departures strip overhead; light #F2F4F7 working ground with white panels and #DDE1E7 hairlines; signal amber #F2B33D for the primary action and the current stage; green #2F9E78 done or on time; red #E5484D overdue. Readex Pro for all interface text (Arabic and Latin); JetBrains Mono only for case numbers, dates and countdown values set as split-flap chips.

STORY: HR opens a case and sees within a second what is due and when, which stage the case is at, and the one next step; facts, documents and history sit below for verification.

FIRST VIEWPORT: Top bar with the main navigation. Below it the departures strip: case number large in amber mono with the employee name; a table of due events (event, stage, due date, remaining as a split-flap chip, status). Under the strip, a route rail of five stations spans the left two thirds; the dark next-step panel with the amber primary action holds the right third; case facts and documents follow beneath the rail.

FORM: Departures board, candidate 3 on the ordered list (my top-ranked grounded pick, chosen over the roll), seed key 839f490e. Signature interaction: countdown chips change state colour as a deadline nears (on time, due in 48 h, overdue) and the route rail's current station is the only amber point on the page besides the action.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

## Unresolved

- Roll-out to every other screen happens after the user approves the case page.
- Printed legal documents are out of scope (separate PDF templates).
