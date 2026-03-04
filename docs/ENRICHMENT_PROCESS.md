# Enrichment Process (Current)

Verified against code: Yes
Last updated: March 2026

## Purpose

This document describes the implemented article enrichment job path and persistence behavior.

## Entry Points

Enrichment is processed by `EnrichArticle` jobs.

Common triggers include:

- article text extraction workflows
- direct command dispatch (`enrich:article`)
- backfill command dispatch (`enrich:backfill`)

Queue: `analysis`

## Preflight Gates

Enrichment exits early when:

- `enrichment.enabled` is false
- article missing
- `ArticleBody.cleaned_text` is empty
- cleaned text is below `enrichment.min_cleaned_text_chars`

Text is UTF-8 sanitized and truncated to `enrichment.max_text_chars`.

## Evidence Pack Build

`EvidencePackBuilder` prepares bounded prompt text from cleaned text.

The pack captures signal-bearing segments and logs pack metrics.

## Multi-Pass LLM Enrichment (Implemented)

`Enricher` runs three passes in order:

1. `CivicAnalysisAgent`
2. `EntityEnrichmentAgent`
3. `ExplainerAgent`

Pass outputs are normalized into one payload containing:

- civic analysis dimensions and justifications
- opportunities
- entity/keyword/issue-area extraction
- process timeline
- explainer content
- merged confidence

If later passes fail, previously successful pass outputs are retained where possible.

## Persistence Path in `EnrichArticle`

After enrichment payload generation, `EnrichArticle` performs:

1. `ArticleAnalysis::updateOrCreate(...)`
2. `ClaimWriter->write(...)`
3. `ProjectionWriter->write(...)`
4. `CivicActionProjector->projectForArticle(...)`
5. `ProcessTimelineProjector->projectForArticle(...)`
6. `ArticleExplainerProjector->projectForArticle(...)`
7. `ArticleTextService->refresh(...)`

### Tables Written or Updated

- `article_analyses`
- `claims`
- `article_entities`
- `article_issue_areas`
- `keywords`
- `article_keywords`
- `civic_actions`
- `process_timeline_items`
- `article_explainers`

## Reliability and Recovery

`EnrichArticle` includes retry logic for recoverable PK sequence drift and calls `PostgresSequenceSynchronizer` for relevant tables before retry.

## Debug Checklist

1. Run `php artisan enrich:article {id}`.
2. Confirm cleaned text exists and meets min length.
3. Inspect logs for civic/entity/explainer pass outcomes.
4. Verify `article_analyses` row exists and contains payload.
5. Verify claims and projection tables contain rows.
6. Verify demo/article explainer page reflects projected data.
