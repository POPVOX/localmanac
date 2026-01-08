# Task: Replace CivicTextWindow with EvidencePackBuilder (multi-slice, coverage-checked)

## Goal
Build a deterministic, type-agnostic “evidence pack” excerpt from ArticleBody.cleaned_text that:
- is capped by char length (start with 3,500 chars)
- includes multiple slices (opening + date/time clusters + contact/link clusters + heading/list clusters + tail)
- performs a coverage check comparing FULL text vs PACK for generic signals (dates/times, urls/emails/phones, headings/bullets)
- if pack misses important generic signals that exist in full text, rebuild once with broader sampling
- logs one debug entry with original_len, pack_len, and coverage flags (do not spam logs)

Then wire Enricher to use the evidence pack instead of CivicTextWindow.

Do NOT change Prism or model providers. Do NOT add new jobs/queues.

---

## Files to touch
- app/Services/Enrichment/CivicTextWindow.php (keep it if referenced, but replace with new builder OR create new class and update references)
- app/Services/Enrichment/Enricher.php (switch to evidence pack)
- tests/Unit (add focused unit tests)

If your app uses a different namespace for CivicTextWindow, follow the existing structure.

---

## Step 1 — Implement EvidencePackBuilder (deterministic)
Create: app/Services/Enrichment/EvidencePackBuilder.php

### Public API
- `public function build(string $text, int $maxChars = 3500): EvidencePackResult`
- Result should include:
  - `pack_text` (string)
  - `original_length` (int)
  - `pack_length` (int)
  - `signals_full` (array)
  - `signals_pack` (array)
  - `rebuild_used` (bool)
  - `slices` (array of slice metadata: type, start, end, score/why)

Create a tiny DTO-like class `EvidencePackResult` or return array; follow codebase style.

### Slice strategy (MUST be type-agnostic)
Build pack from these slice families, in order, deduping overlaps and enforcing maxChars:

1) OPENING slice:
- first 1,000 chars (or up to first blank-line boundary if easy)
- always included

2) DATE/TIME CLUSTERS:
- Find matches across full text for:
  - month names (Jan..Dec, full names), numeric dates (MM/DD/YYYY, YYYY-MM-DD), weekdays, and time patterns (9:00, 9 AM, 9:00 A.M.)
- For each match, take a surrounding snippet window, e.g. 240 chars before + 340 chars after
- Score “event-likeness” higher when:
  - contains time pattern
  - contains weekday
  - contains “at”, “by”, “no later than”, “will”, “held”, “conducted”, “meeting”
- Penalize likely boilerplate blocks (optional, lightweight):
  - if snippet contains “AFFIDAVIT”, “Notary”, “SUBSCRIBED AND SWORN”, reduce score
  - if snippet contains “published on”, “publication”, reduce score
- Keep top 3 clusters by score (or fewer if not found)

3) CONTACT/LINK CLUSTERS:
- Detect URLs, emails, and phone-like patterns
- Take surrounding snippet windows
- Keep top 2 clusters

4) HEADING/LIST CLUSTERS:
- Identify heading-ish lines (ALL CAPS lines, Title Case lines surrounded by blank lines, or lines ending with “:”)
- Identify bullet-ish lines (starts with “-”, “•”, “*”, numbered “1.”)
- Take a block window around the heading/list start (e.g. include next ~5–12 lines, capped)
- Keep top 2 clusters

5) TAIL slice:
- last 700 chars

### Deduping + assembly
- Maintain selected ranges as [start,end]
- Merge overlaps (or skip if overlap > 50%)
- Assemble in the order above, separated by clear delimiters like:
  - `\n\n--- [OPENING] ---\n\n`
- Ensure final pack_text length <= maxChars (hard cap, truncate last slice if needed)

---

## Step 2 — Coverage check (generic, not doc-type)
Implement a signal detector used on BOTH full text and pack:
Return boolean flags + counts:

- `date_like_count` (count)
- `time_like_count` (count)
- `has_url` (bool)
- `has_email` (bool)
- `has_phone` (bool)
- `heading_like_count` (count)
- `bullet_like_count` (count)

Coverage rule:
- If FULL has time_like_count > 0 and PACK time_like_count == 0 => FAIL
- If FULL has date_like_count > 0 and PACK date_like_count == 0 => FAIL
- If FULL has (has_url||has_email||has_phone) and PACK has none => FAIL
- If FULL heading_like_count > 0 and PACK heading_like_count == 0 => SOFT FAIL
- If FULL bullet_like_count > 0 and PACK bullet_like_count == 0 => SOFT FAIL

If FAIL (hard fail) or ≥2 SOFT FAILS => rebuild once.

---

## Step 3 — Rebuild once with broader sampling
On rebuild:
- increase max clusters:
  - date/time clusters: top 5 instead of 3
  - contact/link: top 3 instead of 2
  - heading/list: top 3 instead of 2
- widen snippet window a bit (e.g. +/- 350 before, +450 after) but still honor maxChars
- set rebuild_used=true

Do not loop beyond one rebuild.

---

## Step 4 — Wire Enricher to use evidence pack
In app/Services/Enrichment/Enricher.php:
- replace CivicTextWindow usage with EvidencePackBuilder
- use `pack_text` as the text fed to the LLM calls
- add ONE debug log line:
  - message: “Evidence pack built.”
  - include original_length, pack_length, rebuild_used, signals_full, signals_pack
Do NOT log the pack body itself.

Keep your existing LLM prompt shape unless necessary.

---

## Step 5 — Tests (focused)
Add: tests/Unit/EvidencePackBuilderTest.php

Test cases:
1) Deterministic output:
- same input twice => same pack_text

2) Always includes opening:
- pack begins with the first N chars of the input (or contains them)

3) Date/time coverage:
- given a text with both a publication date early and an event sentence later containing weekday+time (“Tuesday, October 21, 2025 at 9:00 A.M.”)
- assert pack_text contains the event sentence (or at least contains “October 21” and “9:00”)

4) Coverage-triggered rebuild:
- craft input where the initial selection would likely miss time-like patterns unless clusters are included
- assert rebuild_used is true and pack has time-like pattern

Keep tests small and not dependent on DB.

---

## Step 6 — Minimal integration check
Run:
- vendor/bin/pint --dirty
- php artisan test --filter=EvidencePackBuilderTest

Do NOT modify unrelated files.

---

## Deliverable
- EvidencePackBuilder + Result structure
- Enricher wired to it
- Unit tests passing
- One debug log line added