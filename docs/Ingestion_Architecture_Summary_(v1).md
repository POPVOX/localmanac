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

## Database structure (ingestion‑relevant)

Localmanac uses a relational core with normalized tables and a small number of purpose‑built companion tables. The schema is designed to support multi‑city ingestion, deduplication, provenance, and future search/analysis.

### Cities
- Represents a geographic scope (e.g. Wichita).
- Almost all ingestable data is scoped to a city.

### Organizations
- Represents the source organization (news outlet, government body, nonprofit, etc.).
- First‑class `type` (e.g. government, news_media, nonprofit, school).
- Linked to scrapers and article sources.

### Scrapers
- Defines *how* content is ingested.
- Key fields:
  - `type` (`rss`, `html`, etc.)
  - `source_url` (listing page, feed URL, etc.)
  - `is_active`
  - `last_scraped_at`
  - `config` (JSON / JSONB — see below)

### Articles
- Canonical record for a piece of content.
- Contains metadata only (title, published_at, content_type, etc.).
- Does **not** store large text blobs directly.

### ArticleBodies
- Stores large text fields:
  - `raw_html`
  - `cleaned_text`
- Separated to keep the Articles table lean and index‑friendly.

### ArticleSources
- Tracks provenance and attribution.
- Stores:
  - canonical source URL
  - source type
  - organization relationship

### Deduplication
- Articles are deduplicated using a combination of:
  - canonical URL
  - content hash derived from cleaned_text
  - time proximity checks (for RSS)

---

## Scraper config strategy (critical)

Scraper behavior is driven almost entirely by the `scrapers.config` column.

This is an intentional design choice to avoid per‑site code and allow rapid iteration without migrations or deploys.

### Core ideas
- Code defines *capabilities*
- Config defines *behavior*
- Most new sources require **config only**, not new classes

### Config structure (common patterns)

### Example scraper configs

Below are representative examples of real scraper configurations used in Localmanac. These are meant for reference and onboarding, not as exhaustive schemas.

#### Example: Documenters scraper (Google Docs)

```json
{
  "profile": "wichitadocumenters",
  "list": {
    "link_selector": "h4 a[href*=\"docs.google.com\"]",
    "link_attr": "href",
    "max_links": 50
  }
}
```

This profile:
- Scrapes a meeting listing page
- Follows links to Google Docs
- Extracts full meeting notes as long‑form civic text

---

#### Example: Generic HTML listing scraper (CommunityVoiceKS)

```json
{
  "profile": "generic_listing",
  "list": {
    "link_selector": "a[href^=\"https://www.communityvoiceks.com/20\"]",
    "link_attr": "href",
    "max_links": 25
  },
  "article": {
    "content_selector": "article .entry-content",
    "remove_selectors": [
      "script",
      "style",
      "nav",
      "header",
      "footer",
      "aside",
      ".sharedaddy",
      ".jp-relatedposts"
    ]
  },
  "best_effort": true
}
```

This profile:
- Scrapes a WordPress listing page
- Follows article permalinks
- Extracts full article content when available
- Gracefully degrades on imperfect pages

#### Profile
- `config.profile` determines which fetcher is used.
- Examples:
  - `wichitadocumenters`
  - `generic_listing`

#### Listing configuration
Used by listing‑based HTML scrapers:

- `config.list.link_selector`
- `config.list.link_attr`
- `config.list.max_links`

These define how article URLs are discovered.

#### Article configuration
Used when fetching individual article pages:

- `config.article.content_selector`
- `config.article.remove_selectors[]`

These define how meaningful content is extracted and how boilerplate is removed.

#### Best‑effort flag
- `config.best_effort = true`
- Allows partial ingestion instead of hard failure (e.g. paywalled sites).

### Why config lives in the database
- Enables per‑source tuning without code changes
- Allows experimentation and rollback via SQL
- Makes ingestion behavior auditable and explicit
- Supports future UI‑based scraper management

### Non‑goals (intentional)
- Config is not meant to encode complex logic
- Extremely bespoke sites may still justify custom fetchers
- The goal is *80–90% coverage via config*, not 100%

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

