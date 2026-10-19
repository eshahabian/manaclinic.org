# Mana Clinic SEO — 2026-10-19

## Published and checked

- Scoped robots.txt restrictions to `/doctor$` and `/doctor/`; `/doctors`
  and public therapist profiles are crawlable, while staff routes remain blocked.
- Unfinished tests now emit `noindex,follow` and are omitted from sitemap.xml.
- Added verified location, therapist links and booking guidance to About.
- Added local service titles, booking instructions, FAQs and contact links to
  five service pages. No prices were added.
- Article authors link to an approved therapist profile only when the public
  author name matches that account. The same link is included in Article JSON-LD.
- Removed the public therapist price markup and its reveal behavior. Existing
  authenticated accounting/payment flows were not changed.
- Replaced the 2,268,248-byte hero PNG with desktop WebP (130,318 bytes) and
  mobile WebP (37,772 bytes), using an eager, high-priority picture element.
- Parsed all 14 changed PHP files with PHP TOKEN_PARSE before publication.
- Checked all 46 sitemap pages: HTTP 200, canonical present, exactly one H1,
  no noindex directives, and parseable JSON-LD.

## Measured result

PageSpeed mobile lab run: Performance 98, Accessibility 96, Best Practices 100,
SEO 100. LCP 1.9s, TBT 0ms, CLS 0.032. No real-user CrUX data was available.
These are one-run lab results, not search rankings or a guarantee of indexing.

Report: https://pagespeed.web.dev/analysis/https-manaclinic-org/4gicuka14h?form_factor=mobile

## Requires owner access or verified editorial information

- Search Console is signed out in the available browser. Owner must sign in;
  then select/verify manaclinic.org, submit https://manaclinic.org/sitemap.xml,
  inspect the homepage and public therapist pages, and request indexing where
  available. Sitemap existence does not confirm submission or Google indexing.
- Founder and technical director roles, clinic license details, and genuine
  clinic photos require confirmation. Do not invent these or use the generated
  hero image as evidence of the real premises.
- Scientific sources and clinical review must be confirmed per article. Do not
  label a text medically reviewed or change review dates without actual review.

## Pricing policy

Public prices remain unpublished until the owner explicitly changes the policy.
See ../AGENTS.md. SEO, structured data, marketing and conversion work must follow
the same policy. Authenticated billing and payment must continue to function.
