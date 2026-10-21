# BDI-II integration

Production PHP route: `/tests/bdi`. The Next.js catalogue description is also corrected; the PHP application is the deployed site.

Sources checked 2026-10-21:

- Pearson instrument overview: https://www.pearsonassessments.com/en-us/en-us/Store/Professional-Assessments/Personality-%26-Biopsychosocial/Beck-Depression-Inventory/p/100000159
- Publisher score-entry documentation: https://qglobal.pearsonclinical.com/qg/static/Product/sv/BDI-II/BDI-II_Enter_Scores.htm
- NCTSN instrument review and conventional bands: https://www.nctsn.org/measures/beck-depression-inventory-second-edition
- Publisher sample report, including clinical attention to item 9: https://www.pearsonassessments.com/content/dam/school/global/clinical/us/assets/beck/BDI-II-Sample-Interpretive-Report.pdf

The original BDI-I and BDI-II are not interchangeable. This implementation requires 21 integer item scores 0–3, sums 0–63, and uses conventional bands 0–13, 14–19, 20–28, 29–63. No prorating of missing answers or unverified Persian norms. The self-service interface is restricted to adults; under-18 users are referred to a clinician. The underlying instrument's published age range is broader.

No question text has been copied, translated, paraphrased, or generated. Without a clinic-provided verified and licensed Persian form, the page is explicitly an existing-answer-sheet scoring tool. To enable questionnaire administration, configure the private server `config.php` key `bdi_ii_form` with:

- `version`: `BDI-II`
- `online_use_authorized`: boolean true, only after the clinic verifies applicable permission
- `source`: verifiable source/version/translation reference
- `instructions`: authorized instructions from that version
- `items`: a PHP array keyed 1 through 21, each with `text` and `options`
- each option has `text` and integer `score` 0–3; alternate options with the same score are supported for sleep/appetite direction changes

Do not place licensed question content in this README or a public JSON asset. Do not substitute a made-up translation or advertise Persian validation without evidence. Questionnaire wording, translation and implementation require the clinic's verification before enabling the form.

AI uses the existing private provider configuration through `assistant_ai_chat`, only after login and explicit consent naming the configured provider host. It sends only numeric item scores and calculated summary, never identity or chart records. This feature does not persist responses or analysis to a database/session/local storage. Remote provider processing follows that provider's policies; no claim of zero retention is made. Requests are throttled. Model errors retain the independently calculated result and chart. Clinical limitations are visible, and generated content is rendered as text.

Any positive item 9 triggers deterministic guidance and suppresses AI analysis, regardless of total. This is a prompt for human assessment, not a risk diagnosis. No hotline number is invented. No staff alert or live monitoring is claimed.

Charts show the raw total and the actual 21 item scores. They do not invent subscales, percentages of illness, or historical trends.
