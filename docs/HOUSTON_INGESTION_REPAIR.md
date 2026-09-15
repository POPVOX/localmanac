# Houston ingestion and retrieval repair

Prepared September 14, 2026. Deploying this code does not itself recrawl sources or repair existing records; follow the production rollout steps below.

## Code changes

- Recognize municipal content wrappers, including Houston's `.mainContent` and the older preservation site's `#main`. Remove navigation, sidebar links, and footer content before extracting answer text or crawl candidates. Preserve the original link inventory separately.
- Honor each chat source's `link_follow_mode` and `link_limit`. Count eligible, unique links toward that limit. Traverse directory pages without indexing them as answer content. Bound total fetch attempts by `chat.crawl_max_pages`, including failed and directory pages.
- Allow the chat crawler to receive PDF bytes in automatic mode and pass them to its PDF extractor. HTML-only fetcher callers retain their existing behavior.
- Search indexed chunks across all active chat sources in the selected city, including sources outside the metadata selector's shortlist. Existing evidence limits still apply; inactive sources and other cities remain excluded.
- Restrict generic News listing links to the source host by default, excluding navigation and sponsor links before applying the item limit. Normalize relative URLs, fragments, and spaces. Recognize direct PDF/DOC/DOCX links and send them through the existing document extraction queue.

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

Replaying the saved September 14 [Houston Public Notice Portal](https://www.houstontx.gov/public-notices.html) HTML now produces 18 content links from 132 total links. The crawler selects the City Council Agenda and seven PDF links. Previously it attempted header navigation and missed the agenda. The generic News parser produces seven document items using `.mainContent a[href$=".pdf"]` as the listing selector. This replay records child requests without fetching them; it does not prove production ingestion or answer quality.

Saved official hazardous-waste, demolition-permit, and historic-preservation pages also yield substantive text. Whether production has indexed those sources still requires an admin/database check.

## Production rollout

1. Deploy the reviewed changes and restart the queue workers through the normal deployment process.
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
