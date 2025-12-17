# Localmanac Ingestion Architecture — v1 Summary

## Purpose of this document
This document captures **where the project is right now**, **what is working**, and **where it is headed**, so work can resume cleanly even if chat context is lost.

This is not aspirational — it reflects the *actual implemented state* as of the latest commit.

---

## High‑level goal
Localmanac ingests civic and local news content from multiple sources, normalizes it into a consistent schema, deduplicates it, and prepares it for search, analysis, and future AI workflows.

Key design principles:
- Deterministic ingestion (no opaque agent magic by default)
- Config‑driven behavior
- Best‑effort handling of imperfect sources
- Skip junk instead of poisoning the dataset
- Preserve provenance (sources, raw HTML)

---

## Current ingestion types (WORKING)

### 1. RSS ingestion
**Use case:** Traditional RSS feeds (news, alerts, calendars)

- Fetches RSS XML
- Parses items
- Produces normalized article items
- Very stable, low maintenance

Status: ✅ Implemented and working

---

### 2. Documenters ingestion (Google Docs)
**Use case:** Wichita Documenters meeting notes

Flow:
- HTML listing page → meeting link
- Meeting link → Google Docs HTML
- Extract structured content

Stored fields:
- `body.raw_html`
- `body.cleaned_text` (full meeting notes)

Notes:
- Produces very high‑quality, long‑form civic text
- This is one of the most valuable content sources

Status: ✅ Implemented and working

---

### 3. Generic HTML listing ingestion
**Use case:** News/blog sites with listing pages and article pages (e.g. CommunityVoiceKS)

Flow:
1. Fetch listing page
2. Extract article links using CSS selectors from config
3. Fetch each article page
4. Extract title, canonical URL, published date, article body
5. Normalize text and store

Key characteristics:
- Config‑driven selectors
- No per‑site code required (for most sites)
- Works well on non‑paywalled WordPress / CMS sites

Status: ✅ Implemented and validated (CommunityVoiceKS)

---

## Best‑effort philosophy (important)

Some sources (e.g. Bizjournals) are paywalled or partially blocked.

Current behavior:
- If full text exists → ingest as `content_type = full`
- If only partial text exists → ingest as `content_type = snippet`
- If text is useless boilerplate → skip item

This avoids pipeline failures while protecting data quality.

Note: Bizjournals ingestion technically works, but snippet quality is poor; filtering or metadata‑only treatment may be applied later.

---

## Data model (relevant parts)

### Articles
- Core record for each piece of content
- Does **not** store raw text directly

### ArticleBody
- `raw_html`
- `cleaned_text`

### ArticleSource
- Tracks provenance
- Stores canonical source URL

### Scrapers
- Config‑driven ingestion definitions
- Important fields:
  - `type` (rss, html, etc.)
  - `source_url`
  - `config.profile`
  - `config.list.*`
  - `config.article.*`

---

## What is intentionally NOT done yet

These are **deliberate deferrals**, not missing features:

- No AI agent / browser‑based scraping
- No paid unblocking infrastructure
- No embeddings or vector search
- No UI polish
- No advanced summarization heuristics

The ingestion layer is intentionally solidified first.

---

## Known pain points / future improvements

- Tighten snippet vs full classification
- Add boilerplate rejection rules for paywalled sites
- Possibly split GenericListingFetcher into listing/article helpers
- Add search indexing once enough content accumulates

---

## Recommended next milestones

Pick one when resuming work:

1. **Quality hardening**
   - Stricter skip rules
   - Content‑type semantics cleanup

2. **More HTML sources**
   - City press releases
   - School district news
   - County / board announcements

3. **Search & discovery**
   - Full‑text search
   - Facets by city, source, date

4. **Analysis layer**
   - Summaries
   - Topic tagging
   - Civic relevance scoring

---

## Bottom line

As of now, Localmanac has a **complete, working ingestion foundation** that:
- handles multiple content types
- avoids brittle site‑specific hacks
- preserves raw source data
- produces searchable text

This is the hardest part of the system — and it’s done.

