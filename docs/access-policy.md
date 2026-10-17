# App access policy

The named exceptions are Atieh Garsichi (عطیه گارسچی), Shiva Geranmaye Pour (شیوا گرانمایه پور), and Emad Shahabian (عماد شهابیان / eshahabian). `clinic_user_is_access_exception()` in `cpanel/includes/doctor_profile_fields.php` is the shared predicate for scoped app restrictions. Preserve these three exceptions when adding such restrictions; this does not grant new roles or bypass authentication, account status, or room membership checks.

Other clinicians' Mana Call contact lists, search, presence results and group member selection only include PATIENT users whose current `users.preferred_doctor_id` points to that clinician's `doctor_profiles.id`. Appointment or call history does not count as a current assignment. The same check applies when starting a new direct call or adding chosen users to a group.

Secretaries retain their existing app permissions and have no Mana Call access. New doctors inherit the shared app theme and limited permissions automatically.
