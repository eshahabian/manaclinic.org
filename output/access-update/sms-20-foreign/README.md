# 20:00 digest and non-Iranian numbers — 2026-10-29

Deployed and verified on production. Supersedes the 21:00 schedule recorded in ../sms-nightly/README.md. Each therapist receives tomorrow's own appointments from 20:00 Asia/Tehran. Only Shiva and Atieh programs are copied to Emad. Existing every-minute cPanel PHP cron remains active; live worker last run was 18:39:07 Tehran.

User explicitly confirmed that a non-Iranian phone number is the criterion for excluding patient SMS. Foreign destinations are not queued, immediate sends return SKIPPED, and existing foreign-number pending jobs are rejected before provider dispatch. Foreign patient appointments remain in therapist programs; patient SMS suppression does not suppress therapist cancellation notifications. No residency inference or fee-flag changes were introduced.

35 isolated checks passed with zero test provider calls, including 19:59:59/20:00 Tehran boundaries, UTC conversion, midnight/year rollover, domestic and international Iranian phone formats, Persian digits, non-Iranian prefixes, foreign immediate/queued/pending sends and separate clinician cancellation. Live file hashes, 20:00 schedule and foreign-number rejection are in verification.json. Temporary diagnostic endpoints were removed.
