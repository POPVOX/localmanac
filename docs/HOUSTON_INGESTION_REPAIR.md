# Houston ingestion and retrieval repair

Prepared September 14, 2026. Deploying this code does not itself recrawl sources or repair existing records; follow the production rollout steps below.

## Code changes

- Recognize municipal content wrappers, including Houston's `.mainContent` and the older preservation site's `#main`. Remove navigation, sidebar links, and footer content before extracting answer text or crawl candidates. Preserve the original link inventory separately.
- Honor each chat source's `link_follow_mode` and `link_limit`. Count eligible, unique links toward that limit. Traverse directory pages without indexing them as answer content. Bound total fetch attempts by `chat.crawl_max_pages`, including failed and directory pages.
- Allow the chat crawler to receive PDF bytes in automatic mode and pass them to its PDF extractor. HTML-only fetcher callers retain their existing behavior.
- Search indexed chunks across all active chat sources in the selected city, including sources outside the metadata selector's shortlist. Existing evidence limits still apply; inactive sources and other cities remain excluded.
- Restrict generic News listing links to the source host by default, excluding navigation and sponsor links before applying the item limit. Normalize relative URLs, fragments, and spaces. Recognize direct PDF/DOC/DOCX links and send them through the existing document extraction queue.
- Close Chromium in a `finally` block so navigation, selector, and storage-state failures also release its temporary profile. This does not clean up files left by earlier runs or guarantee cleanup after forced termination.

`www` and bare-host variants are treated as the same host. Intentional external News destinations must be declared in the scraper's JSON configuration, for example:

```json
{
  "list": {
    "allowed_hosts": ["documents.example.gov"]
  }
}
```

Merge that field into the existing `list` configuration. It contains hostnames, not URLs or wildcard patterns. Review generic listing scrapers that intentionally link to other sites before rollout. Chat's existing external-host policy is unchanged.

## Validation

Regression tests cover Houston-style markup, directory traversal, per-source limits, automatic PDF fetching, document job dispatch, sponsor filtering, and retrieval beyond the metadata shortlist in both retrieval modes. Retrieval tests also check inactive-source and cross-city exclusion.

The isolated release passed 121 relevant tests (488 assertions) against the production branch, with fresh Composer dependencies.

The subsequent browser-cleanup patch passed the 11 fetcher tests (45 assertions) and a local Chromium smoke test: both successful extraction and a missing-selector timeout left their isolated temporary directories empty.

Replaying the saved September 14 [Houston Public Notice Portal](https://www.houstontx.gov/public-notices.html) HTML now produces 18 content links from 132 total links. The crawler selects the City Council Agenda and seven PDF links. Previously it attempted header navigation and missed the agenda. The generic News parser produces seven document items using `.mainContent a[href$=".pdf"]` as the listing selector. This replay records child requests without fetching them; it does not prove production ingestion or answer quality.

Saved official hazardous-waste, demolition-permit, and historic-preservation pages also yield substantive text. Whether production has indexed those sources still requires an admin/database check.

## Production rollout

1. Check server disk and inode capacity before starting another crawl. The September 14 production run logs for Houston Permitting Center (Chat source 236) and the Public Notice Portal (News scraper 61) report `ENOSPC` while Chromium creates `/tmp/playwright-artifacts-*`; logging also failed with `errno=28`. Inspect disk usage and clean only confirmed disposable files from completed processes, preserving uploads, database files, and active browser profiles. Deploy the reviewed changes and restart the queue workers through the normal deployment process.
2. In Houston's Chat sources, verify active entries for the [Environmental Service Centers](https://www.houstontx.gov/solidwaste/esc.html), [residential demolition permit](https://www.houstonpermittingcenter.org/hpwcode1142), [historic preservation manual](https://www.houstontx.gov/planning/HistoricPres/historicmanual.html), and Public Notice Portal. Confirm the separate building-permit source. Set each source's follow mode and limit deliberately: `none` or limit `0` disables following; the limit is now the actual maximum eligible links per page.
3. Queue a targeted recrawl for the verified source IDs. Replace `SOURCE_ID` below with the actual ID; repeat `--source` for additional sources.

   ```bash
   php artisan chat:ingest-sources --city=houston --source=SOURCE_ID
   ```

4. After crawling and embedding jobs finish, audit coverage. A successful crawl or queued embedding count alone does not establish that vectors exist.

   ```bash
   php artisan chat:audit-embeddings --city=houston
   ```

   If the audit reports repairable gaps, queue the bounded repair and audit again after those jobs finish:

   ```bash
   php artisan chat:audit-embeddings --city=houston --repair --limit=100
   ```

5. For News, use a document-specific listing selector for the Public Notice Portal. The PDF selector above collects direct documents; agendas on linked HTML pages need their own listing source. Review intentional external document hosts. Run the affected source and verify extraction jobs complete.
6. For Community Impact, verify the source uses the Houston RSS feed (`https://communityimpact.com/rss/houston/`). The existing canonical-body hydrator and content-repair command handle feed items without usable bodies. Start with one affected article, verify its extracted body and summary, then process the remaining affected articles:

   ```bash
   php artisan articles:repair-content --city=houston --article=ARTICLE_ID
   ```

7. Retest hazardous waste, demolition permits, building permits, and historic designation in Houston chat. Check that answers cite the relevant official pages. Retest bulk waste and family events as controls. Review previously imported sponsor/map articles separately; this change prevents new ingestion from those links but does not delete existing records.

The fixed code still needs actual source coverage and completed extraction/embedding jobs. This batch does not change city-wide suggested questions or ingestion success reporting.

### Source inventory observed September 14

- Solid Waste Department: Chat source 234, `https://www.houstontx.gov/solidwaste/`; the last successful run reported 73 pages. Verify that the Environmental Service Centers page is actually present in the index.
- Permitting Center: Chat source 236, `https://www.houstonpermittingcenter.org/`; repeated failed runs reported zero pages and disk-space errors.
- Historic Preservation: Chat source 258, `https://www.houstontx.gov/planning/historicpres/`; successful runs reported only one page. Source 271 also points to the preservation site using `/planning/HistoricPres/`; review coverage before adding duplicates.
- Public Notices: Chat source 348 and News scraper 61. The News scraper's stored browser wait selector is `main`, whereas the portal uses `.mainContent`. Correct that selector if browser rendering is needed; HTTP fetching worked for the captured portal page.

These are observed run records, not a current capacity check or proof that the fixes have been deployed. No production source settings were changed during this inspection.

## October 10 follow-up

The follow-up patch preserves Playwright's actual HTTP status, rejects HTTP errors and common error pages returned with status 200, and marks an empty crawl failed while retaining previously valid content. Retrieval excludes stored error pages, requires the requested procedural topic, ranks candidates before limiting them, limits repeated chunks from one page, and combines search ranks without comparing incompatible raw scores. Generic words such as "how" and "get" no longer drive the relaxed full-text search.

Read-only checks against the current PostgreSQL database found:

- Source 234 includes the Environmental Service Centers page and its chunks. The repaired candidate retrieval returns that official disposal guide; the original retrieval did not. This is a retrieval check, not a generated-answer evaluation.
- Source 258 contains a single "404 Not Found" page recorded with HTTP status 200. Source 271 already covers the correctly cased `/planning/HistoricPres/` root, with 73 pages. Repair source 258 to the specific [historic landmark designation page](https://www.houstontx.gov/planning/HistoricPres/historic_landmarks.html), rather than adding another duplicate root source, then recrawl and verify its chunks.
- The city's residential/commercial demolition pages (`hpwcode1142` and `hpwcode1159`), permit office landing page, and historic landmark designation page were absent across Houston's stored sources. Add or repair targeted source coverage only after checking the actual fetch response. Public web checks returned HTTP 403 for the permitting pages, so deployment alone cannot promise their availability.
- A 5,000-job enrichment sample contained only 929 distinct article IDs. The enrichment patch prevents unchanged redispatch, uses shared processing locks and failure cooldowns, and records completed input fingerprints. It does not purge the backlog; existing records without fingerprints may need one successful run.

Before rollout, identify the repository, branch and deployed revision for the public web application and every queue consumer. The AWS Forge checkout was at `1ec1761` during inspection and runs Horizon against remote Laravel Cloud PostgreSQL and Redis. Public DNS and matching frontend asset filenames do not establish the public PHP revision. Laravel Cloud deployment inspection still requires an authenticated session; do not treat a successful Forge deployment as proof that the public application was updated.

Deploy the enrichment migration before restarting workers, then verify successful fingerprints, queue progress, error-page rejection and the four Houston questions. Preserve the existing queue and source data during verification.
