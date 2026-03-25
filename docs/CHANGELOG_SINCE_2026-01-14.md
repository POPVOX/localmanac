# Changelog Since 2026-01-14

This document tracks notable delivered scope after the previous documentation baseline on January 14, 2026.

## 2026-01 to 2026-02: Chat and Scheduling Expansion

- Added chat sources management UI and source selection/search behavior
- Added ask pipeline migration to Laravel AI with event-aware tooling support
- Added scheduled ingestion for event sources and scrapers
- Added conversation persistence support for streaming dashboard chat

User impact:

- Better local answer quality and stronger citation continuity
- More reliable unattended ingestion operations
- Persistent chat context for authenticated dashboard users

## 2026-02: Ingestion Reliability and Fetch Hardening

- Stabilized Visit Wichita ingestion with direct token endpoint path and fallback behavior
- Hardened sequence drift recovery in ingestion and enrichment write paths
- Improved document extraction and article title quality handling
- Strengthened strict schema enforcement for AI structured payloads
- Added UTF-8 sanitization before enrichment requests

User impact:

- Fewer ingestion stalls and fewer malformed-content failures
- Better extracted article readability and resilience across noisy sources

## 2026-02 to 2026-03: Admin and Dashboard Access Controls

- Added super-admin access controls for admin routes and raw scraper config management
- Expanded admin/dashboard UI polish and role-appropriate navigation behavior
- Added branded favicon/logo asset integration across app surfaces

User impact:

- Clear separation between standard user and administrative capability
- More consistent cross-surface branding and UI behavior

## 2026-03: Feedback + Ingestion Quality Guard

- Added `site_feedback` model, migration, enum, widget capture flow, and admin feedback index/filtering
- Added `ArticleQualityGuard` with configurable rejection rules
- Added `articles:prune-low-quality` command with dry-run and force-delete modes
- Expanded tests around quality guard logic, prune command behavior, and feedback workflows

User impact:

- Direct in-product channel for user feedback capture and triage
- Reduced low-value article noise entering the content corpus

## 2026-03: Scraper Assistant and Browser-Fetcher Hardening

- Added stronger assistant drafting/refinement rules and selector preservation behavior
- Added preview validation requirements for non-super-admin scraper saves
- Added expanded Playwright option handling across fetchers and runtime script
- Added anti-bot recovery/failure signaling improvements across fetch workflows

User impact:

- Safer scraper onboarding and fewer broken scraper configs
- Better reliability for JS-heavy or challenge-protected sources

## 2026-03: SDK-Native Retrieval Pipeline

- Replaced raw SQL vector search with Laravel AI SDK `whereVectorSimilarTo` for both chat source chunks and article chunks
- Replaced 200+ lines of manual reranking heuristics with SDK `Reranking::of()->rerank()` using Cohere/Jina providers
- Added `article_chunks` table with pgvector embeddings so articles are discoverable via vector similarity search
- Added `ArticleChunkEmbedder` service with automatic chunk generation during article enrichment
- Added `QueryExpander` agent for LLM-driven query expansion on broad questions
- Added `AnswerQualityJudge` agent for automated answer classification (useful, no_answer, refusal, vague)
- Added `NavigationPageClassifier` service to filter hub/index pages from the chunk index
- Added `chat:backfill-article-chunks` command for one-time article chunk backfill
- Added `chat:purge-navigation-pages` command to remove navigation pages from the index
- Removed `ChatUpdatesAnswerService` routing — all questions now flow through a single retrieval-and-synthesis path
- Removed procedural answer constraining that was producing worse answers than the LLM
- Added Google Maps address linking in LLM answer instructions
- Added reranking, query expansion, and article chunks configuration entries

User impact:

- Articles are now findable via semantic search even when vocabulary doesn't match
- Broad questions like "service alerts" or "what's new" produce better results through query expansion
- Search results are semantically reranked instead of using keyword-based scoring heuristics
- Navigation/hub pages no longer pollute retrieval results
- Street addresses in answers link to Google Maps
- Answer quality is monitored via automated classification logging
