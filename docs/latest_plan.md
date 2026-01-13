CODEX CHECKLIST — Add Century II calendar JSON endpoint (month-by-month)

Goal: Ingest Century2 events into `events` via existing JSON ingestion pipeline, using the XHR endpoint:
  https://www.century2.com/events/calendar/{YYYY}/{M}

Important constraints:
- Do NOT implement any recurring-event logic.
- Do NOT add any HTML fallback.
- Treat each returned row as a normal event record; de-dupe by a stable hash.

1) Add a new JsonApiFetcher profile: `century2_calendar`
   - root_path: "events"
   - Each item contains:
     - title: Title
     - starts_at: StartDateTime
     - ends_at: EndDateTime
     - event_url: URL (already absolute)
     - description: Description (HTML string is fine)
     - image_url: ImageURL (optional)
     - location_name: parse from Description if easy (it contains <h4>Venue</h4>), otherwise leave null.

2) Implement month pagination for this source
   - This endpoint returns a single month only.
   - Add support in JsonApiFetcher (or EventSourceFetcher wrapper) to allow sources with a “month loop” mode:
     - Start from a configured start_month (e.g. current month)
     - Fetch N months forward (e.g. 12 months) per run
   - For each month, call:
     /events/calendar/YYYY/M?v=2&detail_partial=...
   - Combine all returned items into the ingestion run.

3) Add stable de-dupe hash for Century2
   - Use EventID + StartDateTime (or URL + StartDateTime) to generate source_hash.
   - Reason: Same EventID repeats across different days/times, and we want separate occurrences.

4) Add an EventSource seed for Century2
   - type: json_api
   - url_template: "https://www.century2.com/events/calendar/{year}/{month}
   - config:
     - profile: century2_calendar
     - json.root_path: "events"
     - months_forward: 12 (or whatever you want for demo)
     - timezone: use the city timezone (not hardcoded)

5) Add/extend tests
   - Add a fixture JSON file using the sample in docs/latest_plan.md
   - Assert:
     - correct root_path parsing
     - StartDateTime/EndDateTime mapped
     - URL mapped
     - source_hash differs across different StartDateTime rows even if EventID same

6) Manual verification (your demo sanity check)
   - Run ingestion for the Century2 source
   - Confirm events inserted into `events` with:
     - non-null title
     - starts_at populated
     - event_url populated
     - multiple occurrences exist for “Water for Elephants” on different days