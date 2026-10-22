---
name: Attendance
description: Iraqi attendance and leave reconciliation workspace styled as a station departures board.
colors:
  night: "#0E1A2B"
  night-strip: "#16263D"
  night-line: "#26384F"
  night-soft: "#A9B6C9"
  signal-amber: "#F2B33D"
  signal-amber-deep: "#DE9A1A"
  signal-amber-wash: "#FDF3DD"
  route-navy: "#1D3A63"
  route-navy-wash: "#E6ECF5"
  ink: "#14181F"
  working-ground: "#F2F4F7"
  hairline: "#DDE1E7"
  panel: "#FFFFFF"
  overdue-red: "#C2272D"
  overdue-red-wash: "#FDECEC"
  done-green: "#1F7A58"
  done-green-wash: "#E4F4EC"
  pending-ochre: "#9A6400"
typography:
  headline:
    fontFamily: "Readex Pro, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 700
    lineHeight: 1.25
  title:
    fontFamily: "Readex Pro, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.35
  body:
    fontFamily: "Readex Pro, sans-serif"
    fontSize: "0.9375rem"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "Readex Pro, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    letterSpacing: "0.14em"
  numeral:
    fontFamily: "JetBrains Mono, monospace"
    fontSize: "2.75rem"
    fontWeight: 700
    lineHeight: 1
    fontFeature: "tnum"
  countdown:
    fontFamily: "JetBrains Mono, monospace"
    fontSize: "0.8rem"
    fontWeight: 500
    letterSpacing: "0.025em"
    fontFeature: "tnum"
  document:
    fontFamily: "Noto Naskh Arabic, serif"
    fontSize: "1.125rem"
    fontWeight: 400
    lineHeight: 1.8
rounded:
  chip: "4px"
  control: "8px"
  panel: "12px"
  station: "9999px"
spacing:
  xs: "8px"
  sm: "12px"
  md: "20px"
  lg: "24px"
  page-gutter: "32px"
components:
  button-primary:
    backgroundColor: "{colors.signal-amber}"
    textColor: "{colors.night}"
    rounded: "{rounded.control}"
    padding: "10px 16px"
    height: "40px"
  button-primary-hover:
    backgroundColor: "{colors.signal-amber-deep}"
    textColor: "{colors.night}"
  button-secondary:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "10px 16px"
  input:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "8px 12px"
    height: "40px"
  panel:
    backgroundColor: "{colors.panel}"
    rounded: "{rounded.panel}"
    padding: "20px"
  departures-strip:
    backgroundColor: "{colors.night-strip}"
    textColor: "{colors.panel}"
    rounded: "{rounded.panel}"
    padding: "24px"
  countdown-chip:
    backgroundColor: "#0A1422"
    textColor: "#E8EDF5"
    typography: "{typography.countdown}"
    rounded: "{rounded.chip}"
    padding: "2px 8px"
  top-bar:
    backgroundColor: "{colors.night}"
    textColor: "{colors.night-soft}"
    height: "64px"
---

# Design System: Attendance

## Overview

**Creative North Star: "Baghdad Central Departures"**

Attendance reads like the departures board at Baghdad Central Station. Each employee day has a clear state: source coverage, calculated time, reconciliation and remaining questions. The midnight board hangs overhead (the top bar and summary strip); everything below it is a calm, light working ground where HR verifies source records and history over long office sessions.

The system is restrained by design. Colour does jobs, not decoration: signal amber marks the single primary action and current state, green means complete, red marks a problem requiring attention. The split-flap chip and route rail remain available visual patterns for measured time and progress.

The user explicitly rejected a generic SaaS look, playful colour, an old government look and cramped density.

**Key Characteristics:**
- Midnight shell overhead, light #F2F4F7 working ground below.
- The current reconciliation state and unresolved data issues are visible on working screens.
- Amber appears at most twice per screen: the current station and the primary action.
- One interface family (Readex Pro) for Arabic and Latin; JetBrains Mono only for numbers that are measured or counted.
- Right-to-left is native: every layout mirrors with logical properties.

## Colors

A midnight-and-amber station palette over cool, near-white working surfaces.

### Primary
- **Signal Amber** (signal-amber): the primary action button and the current route station only. Hover deepens to Signal Amber Deep. Never used for text on light grounds (insufficient contrast).

### Secondary
- **Route Navy** (route-navy): links, active filters, focus outlines and info badges on light grounds; its wash (route-navy-wash) backs active menu items and info badges.

### Neutral
- **Station Night** (night): the top bar and the next-step panel.
- **Board Strip** (night-strip): the departures strip and dashboard summary board.
- **Board Rule** (night-line): hairlines and chips inside the dark board.
- **Board Ink** (night-soft): secondary text on dark surfaces.
- **Working Ground** (working-ground): the page background.
- **Panel White** (panel): cards, tables and forms.
- **Hairline** (hairline): every border and divider on light surfaces.
- **Ink** (ink): body and heading text.

### State
- **Overdue Red** (overdue-red, wash overdue-red-wash), **Done Green** (done-green, wash done-green-wash), **Pending Ochre** (pending-ochre on signal-amber-wash): time and status meaning only.

### Named Rules
**The Two Amber Points Rule.** On any screen, signal amber appears on at most the current stage and the one primary action. Repeated list actions use the secondary button.

**The Meaning-Only State Rule.** Red, green and ochre appear only where they carry a time or status meaning, never as decoration.

## Typography

**Interface Font:** Readex Pro (with sans-serif)
**Figures Font:** JetBrains Mono (with monospace), tabular figures
**Document Font:** Noto Naskh Arabic (with serif), printable documents only

**Character:** A Kufi-rooted workhorse sans that sets Arabic and Latin at the same weight and rhythm, paired with a station-board mono for measured times, dates and counts.

### Hierarchy
- **Numeral** (700, 2.75rem, 1): the main measured figure in the departures strip, signal amber on the dark board.
- **Headline** (700, 1.5rem, 1.25): page titles.
- **Title** (600, 1.125rem, 1.35): panel and section headings, the next step.
- **Body** (400, 0.9375rem, 1.5): all interface text; prose capped near 75ch.
- **Label** (500, 0.75rem, 0.14em, uppercase): table column headers on the departures board only.
- **Countdown** (500, 0.8rem, uppercase, tabular): split-flap chips.

### Named Rules
**The Measured Mono Rule.** Mono is for numbers that are counted or measured (times, dates, countdowns, counts), never for labels or decoration.

**The No Eyebrow Rule.** No small uppercase kicker above a heading; the heading speaks for itself.

## Layout

A single centred column (max 80rem, 32px gutters on wide screens, 16px on phones) under a sticky 64px top bar. Working screens stack full-width panels with 20px rhythm; an employee result may open with a departures strip followed by source and reconciliation detail. The top-bar navigation appears from 1280px; below it a four-item bottom bar and a "More" sheet carry navigation. Tables collapse into labelled stacked rows under 768px.

## Elevation & Depth

Mostly flat with tonal layering: depth comes from the dark board against the light ground. Panels carry a single soft hairline shadow; menus and dropdowns lift with one ambient shadow.

### Shadow Vocabulary
- **Panel rest** (`box-shadow: 0 1px 2px rgb(14 26 43 / .05)`): every light panel.
- **Board lift** (`box-shadow: 0 8px 24px -12px rgb(14 26 43 / .45)`): the departures strip.
- **Menu** (`box-shadow: 0 12px 32px -8px rgb(14 26 43 / .28)`): dropdowns and the account menu.

## Shapes

Gently squared: 12px panels, 8px controls and buttons, 4px chips; only route stations and avatars are round. Borders are 1px hairlines; the route rail is a 3px line.

## Components

### Buttons
- **Shape:** gently squared (8px).
- **Primary:** Signal Amber with Station Night text, 40px tall; hover Signal Amber Deep.
- **Hover / Focus:** 150ms colour transition; 2px Route Navy focus outline offset 2px.
- **Secondary:** white with a hairline border and Ink text; used for repeated list actions.
- **Ghost / Danger:** transparent Ink; Overdue Red with white text for destructive actions.

### Chips
- **Countdown chip (signature):** near-black board tile with a hairline split across its middle, mono uppercase text; on-time light, approaching amber, overdue red, done green, idle Board Ink.
- **Badges:** 4px-rounded tinted washes with matching text for status.

### Cards / Containers
- **Corner Style:** 12px.
- **Background:** Panel White on Working Ground; Station Night for a priority panel; Board Strip for summary boards.
- **Shadow Strategy:** Panel rest (see Elevation).
- **Border:** 1px Hairline.
- **Internal Padding:** 20px (16px on phones).

### Inputs / Fields
- **Style:** white, 1px Hairline, 8px radius, 40px tall.
- **Focus:** 2px Route Navy outline, offset 2px; caret Route Navy.

### Navigation
- **Top bar:** Station Night, 64px, links in Board Ink at 14px/500; hover white; current page in Signal Amber with a 3px amber underline at the bar's foot. Settings and the account are dropdown menus. The admin panel uses the same bar.
- **Phones:** white bottom bar of four items; the current item gets an amber cap and Ink text.

### Route Rail (signature)
When a process has stages, five stations sit on one 3px line: done stations filled Station Night, the current station Signal Amber with a soft amber ring, future stations white with a Hairline ring. In compact lists it shrinks to 10px stations joined by 2px lines.

### Departures Strip (signature)
A summary header: employee name, selected period and a measured figure as the amber numeral, followed by the source coverage and reconciliation state on the Board Strip.

## Do's and Don'ts

### Do:
- **Do** put the employee result, source coverage and unresolved issues first.
- **Do** keep amber to the current station and the single primary action (The Two Amber Points Rule).
- **Do** set every measured time, date and count in JetBrains Mono with tabular figures.
- **Do** use logical properties so every layout mirrors for Arabic.

### Don't:
- **Don't** use a generic SaaS look: purple gradients, oversized rounded cards, glassy surfaces.
- **Don't** use playful or decorative colour; colour carries state or action only.
- **Don't** imitate old government styling (heavy borders, seals, crests).
- **Don't** cram content: keep 20px panel padding and readable line lengths.
- **Don't** put a small uppercase eyebrow above a heading.
