# Feature Specification: Devices and defensible absence

**Created**: 2026-11-04 | **Status**: Product-owner decision

## Problem

A single Baghdad device records both directions with state `255`. The current import treats it as restaurant data. Declared coverage also continues after a branch's last punch, while employees never seen on a device are marked absent.

## Decision

HR can approve a `check_in_out` device. Its punches receive direction per person and Asia/Baghdad calendar day. A no-punch day can become absent only when the person has an approved attendance-device punch and the branch has attendance-device data through that date, in addition to declared coverage.

## Requirements

- Merge consecutive punches on a combined device when each is within the configured 15 minutes of the previous punch. One group before noon is in; one group from noon is out. Even groups alternate in and out. With three or more odd groups, use the first as in, the last as out, and mark middle groups `repeat` with `unclear_direction`. Existing repeat selection chooses the first in and last out within each group.
- State `255` does not suggest restaurant without a restaurant name. An unknown role requires HR selection. Branch suggestions also match the first word of names without underscores.
- No-punch days with no attendance device history stay pending with `no_device_for_employee`; no-punch days after the branch's last attendance-device date stay pending with `branch_data_ended`. Assessment and the hourly scheduler use the same check. Device approval and later uploads make eligible days assessable.
- Combined devices count as expected devices for outage detection and do not count meals. Device and upload screens show clear action and device evidence.

## Out of scope

Night shifts crossing midnight on a combined device are not resolved by the calendar-day direction rule. Office and shift formulas, rest allowance, reconciliation, allocations, overtime, exports and AI prompts stay as designed.

## Acceptance

Four groups at 08:29, 12:50, 13:55 and 17:53 produce 499 minutes of presence and 65 minutes out on an eight-hour minimum-presence office policy. One group yields a missing punch. Three groups retain unclear middle rows. No device history or branch data beyond its last punch never creates absence or recurring hourly jobs. HR can approve a combined device from its first punch date and see its counts and pending action after upload.
