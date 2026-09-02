# Localmanac

Localmanac is a city-scoped civic information platform that ingests local public information, normalizes it into structured records, and exposes it through administrative tools, public/demo pages, and evidence-backed chat interfaces.

## What Exists

### Article Ingestion and Intelligence
- Ingestion from RSS and HTML sources (including profile-based HTML fetchers)
- Structured article storage with body/source provenance
- Enrichment pipeline with civic analysis, entity extraction, process timeline projection, and explainer projection
- Quality guard controls for low-value content suppression

### Calendar Event Ingestion
- Ingestion from ICS, RSS, JSON/JSON API, and HTML event sources
- Event normalization and deduplication with source lineage tracking
- Event ingestion runs and scheduling support

### Search and Q&A
- City-scoped article search (Scout-backed retrieval with SQL fallback)
- Ask endpoint with citation-based response contract
- SDK-native vector search across chat source chunks and article chunks (`whereVectorSimilarTo`)
- Semantic reranking via Cohere/Jina (`Reranking::of()->rerank()`)
- LLM-driven query expansion for broad questions
- Automated answer quality classification (useful, no_answer, refusal, vague)
- Event-aware question handling (intent + temporal window resolution)
- Dashboard chat with streaming and conversation memory for authenticated users
- Navigation page filtering to keep hub/index pages out of retrieval results
- Google Maps address linking in answers

### Administration and Operations
- Super-admin-gated admin interfaces for cities, organizations, scrapers, event sources, events, chat sources, and feedback
- Scraper assistant workflow for draft generation/refinement/preview
- Site feedback capture and admin review

## Technology Snapshot
- Laravel 12 + PHP 8.4
- Livewire 4 + Flux UI
- PostgreSQL
- Laravel Scout
- Laravel AI integrations for enrichment, answer synthesis, vector search, reranking, and query expansion
- pgvector for embedding storage and similarity search
- Queue-driven ingestion and analysis workflows

## City Scoping
All core data paths are city-scoped. Wichita is the current default city in active use, without hard-coded single-city logic.

## Documentation
Detailed technical documentation is organized in `docs/`:

- [Documentation Index](docs/README.md)
- [Product Vision](docs/PRODUCT_VISION.md)
