# Roadmap (Current)

Updated: March 2026

## Shipped Core Capabilities

- Article ingestion for RSS and profile-driven HTML sources
- Calendar ingestion for ICS/RSS/JSON/HTML sources
- Enrichment pipeline with civic analysis, entity extraction, process timeline, and explainer projections
- City-scoped article search and dashboard article feed/search/filter UX
- Ask API with citation contract and event-aware answer handling
- Dashboard streaming chat with conversation memory
- Super-admin-gated admin console surfaces
- Site feedback capture and feedback admin review

## Shipped Beyond Original Plan (Scope Drift)

- Super-admin gate model and raw scraper config privilege separation
- Scraper assistant hardening with draft generation, AI refinement, and preview validation gates
- Ingestion quality guard plus low-quality pruning command
- Playwright fetch hardening with proxy/storage-state/refresh/auto-scroll controls
- Chat source admin performance instrumentation (Playwright usage and latency summaries)
- Explicit temporal context anchoring for chat prompts

## In Progress

- Continued source onboarding and selector hardening
- Ongoing chat retrieval quality and citation quality tuning
- Ongoing admin table and UX polish

## Deferred / Open Backlog

- Claim review UI and moderation workflow
- Alias/entity resolution management UI and tools
- Search reranking policy formalization with civic relevance integration
- Potential dedicated public search endpoint expansion

## Near-Term Priorities

1. Ship claim review workflow with approval/rejection operations.
2. Ship alias management and deterministic entity resolution tools.
3. Formalize measurable search and answer quality benchmarks.
