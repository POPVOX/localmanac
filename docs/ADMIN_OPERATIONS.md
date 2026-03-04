# Admin Operations (Current)

Updated: March 2026

## Access Control

Admin access is super-admin only via gates:

- `access-admin`
- `manage-raw-scraper-config`

Gate checks resolve through `User::isSuperAdmin()`.

## Super-Admin Management

Command:

- `php artisan users:super-admin {email}`
- revoke with `--revoke`

## Admin Surfaces

Current super-admin pages include:

- Admin dashboard
- Cities
- Organizations
- Scrapers
- Event Sources
- Events
- Chat Sources
- Feedback index

## Scraper Admin Notes

`Admin/Scrapers/Form` supports assistant-guided config generation and preview.

Behavior differences:

- super admins can directly edit and save raw config JSON
- non-super-admin users cannot bypass assistant preview validation

Assistant pipeline components:

- source fetch
- heuristic draft
- optional AI refinement
- preview extraction

## Chat Sources Admin Notes

`Admin/ChatSources` supports:

- active/inactive toggle
- crawl renderer settings (`auto`, `http`, `playwright`)
- per-source link-follow and link-limit controls
- summary metrics, including Playwright usage rate and fetch latency trends

## Event Admin Notes

Admin event and source pages support:

- event source create/edit/show
- manual ingestion run flows
- event list/search/sort/edit/show
- source config masking for sensitive auth token fields

## Feedback Capture and Review

Implemented feedback components:

- user-facing widget: `App\Livewire\Feedback\Widget`
- admin list/filter: `App\Livewire\Admin\Feedback\Index`

Data model:

- table: `site_feedback`
- enum types: `like`, `dislike`, `trouble`, `suggestion`
- captured fields include user, type, message, page URL, route name, and optional city

## Branding/UI Asset Operations

Shared branded assets are served from `public/images/`:

- `logo.png`
- favicon set (`favicon.ico`, `favicon-16x16.png`, `favicon-32x32.png`, `apple-touch-icon.png`, Android icons, webmanifest)

These assets are referenced across:

- landing page
- auth layouts
- dashboard layouts

## Operational Commands

- `scrape:run`, `scrape:schedule`
- `calendar:run`, `calendar:schedule`
- `events:dedupe`
- `articles:prune-low-quality`
- `articles:reextract-documents`
- `articles:refresh-text`
- `enrich:article`, `enrich:backfill`
- `chat:ingest-sources`
- `db:sync-sequences`
