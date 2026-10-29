# Separate room start/end fields — 2026-10-29

Deployed and hash-verified pages/staff_app.php. Create and edit now have two manual time boxes, start and end, displayed side by side. New forms no longer force a one-hour duration. The legacy hour-only path only computes an end when no explicit end was supplied. Existing assignment/update services retain time-order validation and conflict checks. Multi-hour reservation edits now show the complete original start/end range; per-hour release controls are preserved.

58 isolated PHP checks and 49 browser checks passed, including room/day state, CSRF/access checks, explicit start 10:30 and end 12:15 in a mocked POST, two required mobile fields and full-range multi-hour edit prefill. Mobile screenshot inspected. No real booking was created, edited or released in tests. Temporary remote fixtures removed.
