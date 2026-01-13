CODEX CHECKLIST — Add Headless Browser Fetcher (Playwright) as a LAST-RESORT fetcher

Goal:
- Add a new fetcher that uses a headless browser ONLY for sources that explicitly opt into it.
- Do NOT change existing ICS/JSON/HTML-XHR fetchers.
- Keep headless isolated, rate-limited, and cache-friendly.

1) Create a new fetcher type (do not modify existing ones):
- Add a new EventSource type: "headless_html"
- Create HeadlessHtmlFetcher (parallel to HtmlCalendarFetcher)
- HeadlessHtmlFetcher should return a raw HTML string (or a normalized list of HTML fragments), then reuse the SAME parsing/normalizing/writing path already used by HtmlCalendarFetcher.

2) Define a strict contract for when headless runs:
- HeadlessHtmlFetcher runs ONLY if EventSource.type == "headless_html"
- No fallback from json/rss/html to headless. Ever.
- Headless is explicitly opted-in per source.

3) Source config shape (per-source, no hardcoded city):
- config should support:
  - entry_url (string) — the page to load in the browser
  - wait_for (string) — a CSS selector to wait for OR a network idle strategy
  - extract_mode (string) — "page_html" | "selector_inner_html" | "selector_outer_html"
  - extract_selector (string|null) — required for selector-based modes
  - timezone_mode (string) — "city" (default), never hardcode America/Chicago
  - pagination (optional):
      - type: "click" | "url_template"
      - for click: next_selector + max_pages
      - for url_template: template + month_range or date_range

4) Implement safe runtime behavior:
- Concurrency:
  - Force headless sources to run with low concurrency (e.g., 1 per city at a time)
- Timeouts:
  - Page load timeout and selector wait timeout
- Retries:
  - Small retry count with backoff
- Caching:
  - Cache the fetched HTML per source+date window for a short TTL (e.g., 10–60 minutes) to avoid repeated browser launches during testing
- Observability:
  - Log: source_id, url, timing, wait strategy used, html length, and count of extracted event candidates

5) Make debugging painless:
- Add a "debug artifact" mode in config:
  - when enabled, persist the fetched HTML (or extracted fragment) to storage (or local file) with a deterministic filename:
    storage/app/debug/headless/source_{id}_{yyyy-mm}.html
- This is critical so you can inspect what the browser actually saw without re-running.

6) Keep parsing consistent:
- Do NOT parse in the browser.
- Browser is ONLY for “get me the DOM after JS ran”.
- After fetch, pass HTML to existing HTML parsing logic (or add a single new parser profile) that extracts:
  - title
  - start date/time (city timezone)
  - location (if present)
  - url to event detail page
  - description snippet if present
- Ensure absolute URL normalization happens (base URL from source config).

7) Add one seed + one test:
- Seed ONE headless source (pick the worst offender, not an easy one).
- Add a test that runs headless fetcher in “fixture mode”:
  - Store a captured HTML snapshot file in tests/fixtures/
  - The fetcher should support a test override: if config.fixture_path is set, load from disk instead of launching the browser
  - Assert events are extracted and written, and source_hash is stable.

8) Guardrails so this doesn’t blow up cost/time:
- Add a hard ceiling per run:
  - max_events_per_run (default 200)
  - max_pages_per_run (default 6)
- Add a kill-switch:
  - env/config flag to disable headless fetchers globally (so demos don’t hang)

Deliverables:
- New fetcher: HeadlessHtmlFetcher
- EventSource type support: headless_html
- Config-driven extraction/wait strategy
- Debug snapshot capability
- Fixture-backed tests (no headless runs in CI)
- One seeded example source using headless_html