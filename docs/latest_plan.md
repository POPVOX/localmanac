CODEX CHECKLIST — Update demo calendar UI to “Option A” (Agenda list + mini-month)

Goal
- Keep the existing calendar ingestion + event click-through exactly as-is.
- Change ONLY the demo calendar page UI to:
  - Left: agenda list grouped by time (and “All day” section)
  - Right: mini-month calendar using flux:calendar
  - Top: simple day navigation (Prev / Today / Next) + selected date label
- Do NOT add typography/spacing opinions beyond using Flux defaults.
- Do NOT add recurrence logic. Do NOT add HTML fallback logic. (Unchanged)

Scope
- Only touch the demo calendar route/page (e.g. Calendar.php + calendar.blade.php and any view partials used by that page).
- No DB/schema changes.
- No changes to ingestion pipeline, fetchers, dedupe, or event_url linking.

Data assumptions
- Events already exist in `events` table and titles already link to `event_url`.
- Each event has:
  - starts_at (datetime with tz)
  - ends_at nullable
  - all_day boolean
  - location_name nullable
  - description nullable
  - source (via relationship or stored field; whatever is already present in the demo)

UI behavior
1) Selected date handling
- The page should show one selected day at a time.
- Accept `?date=YYYY-MM-DD` (default: today in the selected city’s timezone).
- Prev/Next buttons change the day by -1/+1 and keep the same query param.
- “Today” jumps to today.

2) Right column: flux mini-month
- Render a mini-month calendar (flux:calendar) on the right.
- Clicking a day navigates to the same page with `?date=YYYY-MM-DD`.
- Highlight the selected date.
- (If Flux supports it) show a subtle indicator on days that have events (dot). If not trivial, skip it.

3) Left column: agenda list (grouped)
- Fetch only events for the selected day (start_of_day to end_of_day in the selected city’s timezone).
- Split into:
  - All-day events (all_day = true OR starts_at has no time / ends_at null but flagged all_day)
  - Timed events (everything else)
- Display order:
  - All-day section first (only if there are any)
  - Then time groups ascending by start time
- Time group header example: “9:00 AM”
- Under each time header, list event rows/cards.

4) Event row/card content
For each event in the agenda list:
- Title (clickable to event_url) — keep current link behavior.
- Secondary line: location_name if present.
- Optional small badge: source name (e.g. “Visit Wichita”, “Wichita Public Library”) if already available in your view model.
- Optional short description snippet (first ~140–180 chars, strip tags). If empty, omit.
- If ends_at present, show “9:00 AM – 10:30 AM”. If not, show start only.
- If all_day, label as “All day”.

5) Empty states
- If no events for selected day:
  - Left column shows “No events scheduled for this day.”
  - Right column still shows mini-month.

6) Keep the existing grouping-by-day list view out of the template
- Replace the current “January 13, 2026” + long list that spans multiple days.
- This page is strictly “Day view”.

Implementation notes
- Use Carbon with the selected city’s timezone for day boundaries (no hardcoded timezones).
- The city timezone should come from the City model (e.g., `city.timezone`) or whatever existing city-context mechanism the demo route already uses; if none exists, derive it from the selected city_id passed to the demo page (do not hardcode a fallback city).
- Query should avoid N+1; eager load whatever relationships the page already needs.
- Use existing components/Flux patterns where possible. Do not introduce a new calendar library.

Acceptance checks
- Visiting /demo/calendar loads with today selected and shows events for today only.
- /demo/calendar?date=2026-01-13 shows only Jan 13 events.
- Prev/Next/Today navigation works.
- Mini-month click changes the day.
- Multiple events at the same time render cleanly under the same time header (no overlap).
- Titles remain clickable; dedupe unchanged; ingestion unchanged.

Deliverables
- Updated demo page UI implementing Option A.
- If needed, small helper(s) in the page/controller for grouping events by time and formatting display strings.
- Run pint on touched files and update/extend any existing demo calendar feature test only if it’s currently failing due to UI changes (keep tests minimal).

Do NOT do
- No new models, migrations, tables.
- No recurrence expansion.
- No HTML fallback additions.
- No redesign of typography; use Flux defaults.