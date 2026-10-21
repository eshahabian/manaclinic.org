# Assessment preparation and publication policy

Current user-authorized state (2026-10-21): BDI-II, its questionnaire, result charts and optional AI explanation are active. The other nine tests show their generated tile illustrations and a coming-soon notice only. Their forms, scoring UI, charts and AI routes are blocked server-side. Supplying licensed content alone does not enable any other test.

Private configuration switches are independent per slug:

- `psych_test_enabled[slug]`: strict boolean; default true only for `bdi` per the explicit user exception.
- `psych_test_ai_enabled[slug]`: strict boolean; default true only for `bdi`. The parent test switch must also be on.
- `psych_test_forms[slug]`: authorized and verified version-matched question content. No questions for other tests have been fetched or added.

Prepared scoring: BAI (21, 0–3); adult DASS-21 (three seven-item scales multiplied by two once); GHQ-28 (separate Likert and binary results); SCL-90-R (nine raw means plus GSI, PST, PSDI); PSS-10 (reverse 4/5/7/8, no invented cutoffs); SWLS (five 1–7 items, Diener descriptive bands); original Y-BOCS (ten 0–4 clinician/self-report severity ratings, not Y-BOCS-II).

Important proprietary dependencies remain explicit rather than guessed:

- NEO-FFI: five raw factor scores can be entered from an already-scored report. The 60-item scoring pipeline requires a verified original-version key with 12 items per domain and explicit reverse flags. No NEO-FFI-3 key or norms are silently substituted. No personality total, T scores or population bands are invented.
- MMPI-2: clinician-only viewer accepts the T scores and validity status of an official report. Invalid or uncertain reports receive no AI interpretation. This is **not a 567-item scoring engine**. A licensed official scoring/validity/norms engine must be connected before administering and automatically scoring that questionnaire. A plain sum of responses is never used.
- GHQ, PSS, SCL and raw NEO scores have no invented diagnostic bands, Iranian norms, percentiles or risk percentages.

All input is version-bound and validated. Blank answers are not zero. Missing/extra items and values outside the exact integer scale are rejected. Positive sensitive items trigger deterministic human-follow-up guidance before AI. No clinical data are saved to a new database/session/local-storage feature. AI is optional, authenticated, consented, rate-limited and receives only numeric results and instrument metadata. It does not set the score. No real patient's data are used in tests.

The BDI-II Persian source appendix is hosted by i4Health at Palo Alto University. Source SHA-256: `afa2fa97d79f2266f5c2971a8f94e1c028bfb70932a819efeac027152fc1157f`. The clinic owner explicitly affirmed permission and instructed internet download. The source is not claimed to be identical to a particular Iranian norming study. The loader supports all seven directional options for sleep/appetite, preserving choice identity; equal-severity directions map to equal numeric scores. PDF extraction typography repairs are recorded in `output/bdi/source/extract-form.py`. Its printed scoring table has an apparent missing digit in the mild-band lower bound; the implementation retains the publisher/NCTSN BDI-II ranges 0–13, 14–19, 20–28, 29–63.

Sources and exact methods are stored alongside each version in `cpanel/includes/psych_assessments.php`. Those method pages remain hidden for tests not yet enabled.

Images: ten distinct built-in ImageGen illustrations, saved as 512px WebP files in `cpanel/assets/img/tests/` and mirrored to `public/images/tests/`. They are symbolic illustrations, not purported clinic photographs. Subject prompts and shared style are recorded in `image-prompts.json`; file dimensions and sizes are in `image-manifest.json`. Total transfer size is approximately 141 KB for all ten thumbnails. No external image host is required.
