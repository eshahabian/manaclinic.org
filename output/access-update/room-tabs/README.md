# App room reservation tabs — 2026-10-29

Deployed pages/staff_app.php with one shared reservation panel and three room tabs. Only the selected room is rendered. The hour/purpose/patient/therapist booking form precedes that room's existing reservation cards. Existing edit/release forms, one-hour booking behavior, recurrence, CSRF and access checks are retained. Selected room is preserved through day navigation, the Jalali picker and create/edit/release redirects; invalid room selections fall back to room 1.

42 isolated PHP checks and 40 browser checks passed. Responsive fixtures at 360/390/768 px confirm three tabs on one row, no overflow and form-first ordering. Simulated tab navigation, purpose/repeat fields, reservation and release POST payloads were checked using a local mocked server only. No production reservation was created, edited or released. Mobile screenshot inspected. Deployed file hash and OPcache refresh verified; temporary remote test files removed.
