---
Verified against code: TODO (fill in once checked)
Last updated: TODO
Scope: Documentation only (code is source of truth)
---

# Enrichment Process (Current)

This document is a high-level map of the enrichment pipeline. It is intended to help new devs / other LLMs:
- understand where enrichment is triggered
- know what each pass produces
- know what tables should be written
- debug "why is X missing?" quickly

Note: the code is the source of truth; if this doc diverges, update this doc.

## Entry Points
- `App\Services\Ingestion\ArticleWriter` dispatches `App\Jobs\EnrichArticle` after saving an article body with cleaned text.
- `App\Jobs\ExtractPdfBody` dispatches enrichment after successful PDF extraction.
- `php artisan enrich:article {id}` dispatches enrichment on demand.
- The job runs on the `analysis` queue (see `EnrichArticle::__construct`).

## Preflight Checks and Text Preparation
- Enrichment is gated by `config('enrichment.enabled')` and requires `ArticleBody.cleaned_text`.
- Minimum length: `enrichment.min_cleaned_text_chars` (default 800).
- Maximum length: `enrichment.max_text_chars` (default 18000).
- The article is loaded with `body`, `scraper.organization`, and `city`.

## Evidence Pack (LLM Context)
- `EvidencePackBuilder` builds a compact context bundle for LLM calls.
- If the text is under the configured cutoff (see `enrichment.*`), the full text is used.
- Otherwise, it assembles slices (opening, date/time windows, contact windows, heading windows, tail) with delimiters.
- It compares signal counts (dates, times, contacts, headings, bullets) between full text and pack; if signals drop, it rebuilds with larger windows and more clusters.
- Debug logs record pack size and signal stats.

## Structured LLM Extraction (Prism)
- Uses configured provider/model with `Prism::structured()` and strict JSON schemas.
  Each pass is independent; if one pass fails, the job should still persist any successful prior pass outputs and continue where reasonable.
- Pass 1: civic analysis (`civicSchema` + `civicPrompt`)
  - Dimension scores and justifications
  - Participation opportunities
  - Process timeline items
  - Overall confidence
- Pass 2: entity enrichment (`enrichmentSchema` + `enrichmentPrompt`)
  - People, organizations, locations, keywords, issue areas
  - Issue areas are restricted to the city `IssueArea` slugs
- Pass 3: explainer (`explainerSchema` + `explainerPrompt`)
  - whats_happening, why_it_matters, key_details, what_to_watch, evidence
- Failures are reported and logged; the pipeline falls back to empty structures.

## Normalization and Validation
- Confidence is clamped to 0..1.
- Enum values are validated (opportunity type, organization type, timeline status).
- Issue areas are filtered to the allowed list.
- Evidence quotes are normalized with optional offsets.

## Persistence and Projections
- `ArticleAnalysis` is upserted with `llm_scores` and `final_scores` (analysis + process_timeline + explainer).
- `CivicRelevanceCalculator` derives `civic_relevance_score` from the dimension scores.
- Model and prompt version are persisted for auditability.
- `ClaimWriter` replaces proposed `claims` for the article/source with extraction claims (people/org/location/keyword/issue area).
- `ProjectionWriter` maps claims (min confidence from `enrichment.projections.min_confidence`) into:
  - `article_keywords`
  - `article_entities`
  - `article_issue_areas`
- `CivicActionProjector` turns participation opportunities into `civic_actions` (from analysis; if missing, it falls back to claims).
- `ProcessTimelineProjector` creates `process_timeline_items` with normalized dates/status.
- `ArticleExplainerProjector` persists `article_explainers`.

## What Gets Written (Persistence Targets)
When enrichment runs successfully, you should expect writes (or upserts) to these tables:

- `article_analyses` — upserted per article (LLM scores / final scores, civic_relevance_score, model + prompt version, last_scored_at)
- `claims` — replaced for the article (proposed/extracted claims used for projections)
- `article_keywords` — rebuilt from claims (keyword projection)
- `article_entities` — rebuilt from claims (people/org/location projection)
- `article_issue_areas` — rebuilt from claims (issue area projection)
- `civic_actions` — projected "How to Participate" actions (primarily from analysis opportunities)
- `process_timeline_items` — projected timeline items with normalized dates/status
- `article_explainers` — persisted explainer ("What's happening / Why it matters / ...")

## Consumption (Demo UI)
- `App\Livewire\Demo\ArticleExplainer` loads analysis, explainer, timeline, civic actions, and entities.
- The UI renders these in `resources/views/livewire/demo/article-explainer.blade.php`.

## Quick Debug Checklist
Use this when something is missing on the demo page.

1. Run enrichment for a single article:
   - `php artisan enrich:article {id}`

2. Check logs (debug) for:
   - evidence pack stats (original vs pack length)
   - "Civic enrichment call completed"
   - "Entity enrichment call completed"
   - "Explainer enrichment call completed"

3. Verify DB writes in this order:
   - `article_analyses` row exists and has `final_scores`
   - `article_explainers` exists (for "What's happening / Why it matters")
   - `civic_actions` exists (for "How to participate")
   - `process_timeline_items` exists (for timeline)
   - `claims` exists (for extracted entities/keywords)
   - join tables (`article_entities`, `article_keywords`, `article_issue_areas`) populated

4. Common causes:
   - no `ArticleBody.cleaned_text` or too short
   - enrichment disabled via config
   - LLM call failed or returned empty arrays
   - projector ran but produced 0 rows due to validation/min-confidence rules

## Failure Behavior
- If enrichment is disabled, jobs exit early and no updates are written.
- If text is missing/too short or a call fails, the pipeline produces empty sections but still records what it can.
