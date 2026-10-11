# Enrichment Process (Current)

Verified against code: Yes
Last updated: October 10, 2026

## Purpose

This document describes the implemented article enrichment job path and persistence behavior.

## Entry Points

Enrichment is processed by `EnrichArticle` jobs.

Common triggers include:

- article text extraction workflows
- direct command dispatch (`enrich:article`)
- backfill command dispatch (`enrich:backfill`)

Queue: `enrichment` by default, configurable with `ENRICHMENT_QUEUE`.

## Preflight Gates

Enrichment exits early when:

- `enrichment.enabled` is false
- article missing
- the current input fingerprint already completed successfully

Missing body text falls back to the article title and summary. Short text is logged but still processed.

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

If any pass fails, the job retries without overwriting existing completed analysis with a partial payload.

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

Unchanged scrape results no longer dispatch another enrichment job. Pending jobs coalesce by article ID, and a shared cache lock prevents concurrent processing of an article. Successful jobs store an input fingerprint after claims and projections finish; old duplicate jobs then exit without another AI call. A changed body or enrichment configuration requires new work. Embedding failures remain logged separately and do not rerun completed analysis.

Failures share a five-minute cooldown across duplicate jobs. Lock contention releases the job without consuming its exception budget; actual exceptions are limited to three per new job. Previously serialized jobs retain their original retry settings.

Deploy the nullable `article_analyses.enrichment_input_hash` migration before restarting workers. `ENRICHMENT_LOCK_STORE` defaults to the database cache; all producers and workers must use the same database, cache prefix and lock store. Upgrade every worker host, including consumers outside Horizon. Existing analyses without a fingerprint may be processed once to establish one. This change does not purge the existing queue.

## Debug Checklist

1. Run `php artisan enrich:article {id}`.
2. Confirm cleaned text exists and meets min length.
3. Inspect logs for civic/entity/explainer pass outcomes.
4. Verify `article_analyses` row exists and contains payload.
5. Verify claims and projection tables contain rows.
6. Verify demo/article explainer page reflects projected data.
