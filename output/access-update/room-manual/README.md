# Manual room reservation time — 2026-10-29

Deployed and hash-verified pages/staff_app.php. Room creation and editing use editable text start-time fields with optional existing clock suggestions. Existing clock parser accepts Persian/Arabic/Latin digits, whole-hour input and minute values. Error/help text now reflects manual entry. One-hour duration, conflict checks, CSRF, room tabs, recurrence and access checks are unchanged.

50 isolated PHP checks and 40 browser checks passed, including Persian ۱۴:۳۰, whole-hour ۸, arbitrary minute input, invalid hours/minutes, edit prefill and mocked POST of 10:30. Mobile screenshot inspected. No live booking was created/changed/released during testing. Temporary remote test files removed.
