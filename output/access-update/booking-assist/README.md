# Booking assistance — 2026-10-29

Deployed pages/secretary/book.php, index.php, includes/booking_room_availability.php, actions/room_availability.php and assets/js/booking-assist.js with verified hashes. Patient labels/search omit usernames and retain name, mobile and assigned therapist. Selecting a patient suggests their saved active therapist; missing assignments may fall back to multiple confirmed/completed appointments with exactly one active therapist. Suggestions are not persisted to the user record and can be cleared with × or changed manually. Booking labels use درمانگر.

The appointment room picker displays exact free/occupied intervals for the selected date and room. Room reservations and appointments are merged, overlaps and cross-day ranges handled, cancellations excluded, and outside-clinic selection skipped. Only time/status fields are returned by an authenticated, noncacheable endpoint. Existing booking conflict checks remain authoritative.

19 isolated PHP checks and 52 browser checks passed, including mobile layout, enhanced-select synchronization, clear/manual override, patient switching, date resets and free/busy room changes. Mobile screenshot inspected. Live database reads and therapist history SQL verified; anonymous API access confirmed blocked. No real booking/payment/SMS was created by the checks. Temporary diagnostic files removed.
