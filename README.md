# Localmanac

Localmanac is a city-scoped civic data system that ingests local government documents and public event calendars, normalizes them into structured records, and exposes them through administrative and public-facing interfaces.

## What Exists

### Articles
- Ingestion from RSS, HTML, PDF, and OCR sources
- Persistent raw bodies and normalized article records
- Enrichment pipeline producing explainers, timelines, participation actions, and entities
- Admin UI for managing sources, scrapers, and articles
- Public-facing article views

### Calendar Events
- Ingestion from ICS, JSON APIs, RSS, and HTML calendars
- City-scoped event normalization and de-duplication
- Admin UI for event sources and ingestion runs
- Public-facing calendar views

### Administration
- City management
- Organization management
- Article and scraper management
- Event source and event management

## City Scoping

All data is scoped by city. Wichita is the currently configured city. No city logic is hard-coded.

## Documentation

Additional technical documentation lives in the `docs/` directory.