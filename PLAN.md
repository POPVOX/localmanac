# Localmanac Plan (Current)

Updated: March 2026

## Current State Summary

Implemented and operating:

- City-scoped article and calendar ingestion pipelines
- Multi-pass enrichment and analysis with projections
- Dashboard article feed/search/filter UX
- Ask API with citations and event-aware behavior
- Dashboard streaming chat with conversation memory
- Super-admin-gated admin console for all operational areas
- Site feedback capture and feedback review index

## Milestone Status

## Milestone 1: Data Model Foundations

Status: Completed

- [x] Cities and city scoping across primary records
- [x] Core entities and alias table
- [x] Articles, article bodies, and article sources
- [x] Event sources, events, and event source item lineage
- [x] Claims and projection tables

## Milestone 2: Ingestion

Status: Completed

- [x] Scraper runs and profile-based article ingestion
- [x] Event source runs and calendar ingestion
- [x] Deduplication and writer layers
- [x] Scheduled ingestion commands for scrapers and event sources
- [x] Sequence drift recovery for selected ingestion write paths

## Milestone 3: Enrichment and Analysis

Status: Completed

- [x] Enrichment job pipeline and evidence-pack flow
- [x] Civic analysis, entity extraction, and explainer passes
- [x] Article analysis persistence and civic relevance calculation
- [x] Claims writing and projection writers
- [x] Civic actions, process timeline, and explainer projectors

## Milestone 4: Search and Q&A

Status: Partially Completed

- [x] Scout-backed article search behavior in dashboard
- [x] Ask endpoint with citation response contract
- [x] Event-aware ask handling and local fallback behavior
- [x] Chat source selection with relevance + DB fallback
- [ ] Formal civic-relevance reranking policy in production ranking flow
- [ ] Expanded public search endpoint strategy beyond current surfaces

## Milestone 5: Admin and Operations

Status: In Progress

- [x] Super-admin authorization gates
- [x] Scraper, event source, event, city, organization admin pages
- [x] Chat source admin page and metrics views
- [x] Feedback capture + feedback admin index
- [ ] Claim review UI
- [ ] Alias management UI and workflows

## Beyond Original Plan (Now Shipped)

The following scope was delivered beyond the January 2026 baseline plan:

- [x] Super-admin gate model (`access-admin`, `manage-raw-scraper-config`)
- [x] Scraper assistant hardening with preview-gated saves for non-super-admin users
- [x] Ingestion quality guard and `articles:prune-low-quality` command
- [x] Dashboard streaming conversation memory persistence
- [x] Event-window interpretation and deterministic event fallback answers
- [x] Site-level feedback model, widget, and admin reporting surface
- [x] Playwright hardening controls (proxy, storage state, refresh, auto-scroll)

## Deferred / Backlog

- Claim moderation workflow and review UX
- Alias management and resolution tooling
- Explicit search reranking rollout with civic relevance weighting
- Additional search and answer quality benchmarking dashboards

## Immediate Next Priorities

1. Ship claim review UI and moderation actions.
2. Ship alias/entity resolution operator tooling.
3. Define and apply measurable search/answer quality KPIs.
4. Continue source onboarding with targeted parser hardening.
