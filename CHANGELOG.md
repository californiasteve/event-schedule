# Convention Schedule – Changelog

## 1.10.0 (2026-11-22)

**New: column matching for CSV import**
- If the column names in an uploaded CSV don't match the workshop fields, a **Match CSV Columns** screen opens before anything is imported. It lists each column in your file with sample values, and you choose which field it goes into (or "Don't import").
- Best guesses are filled in from common header names. For example, Session Name → Title, Day → Date, Location → Room, Track → Categories, Convention → Schedule, and Workshop # → Workshop ID.
- New **Time range** field: a single column such as "9:00 AM - 10:30 AM" or "11-1pm" is split into start and end times.
- Tick **Let me review how columns are matched before importing** on the upload form to see the matching screen for any file.
- The screen checks that Title is chosen and that no field is used twice. Cancel discards the upload. An unfinished upload expires after one hour.
- Files that already use the plugin's own column names (including exports from 1.8.x and 1.9.0) still import straight away, as before.

**Import improvements**
- When a row updates an existing workshop (by ID), only the columns in the file are changed. A file with just ID, Title and Room no longer clears the date, times or description.
- Semicolon- and tab-separated files are detected automatically. So are Windows/Excel (Windows-1252) encoded files, so accented characters come through correctly.
- Categories can be separated by `|`, `,` or `;`, and can be names or slugs.

## 1.9.0 (2026-11-22)

Schedules are now the one way to organize workshops. Schedule Events are retired.

**Changed**
- Removed **Schedule Events** from the Workshops admin menu, the workshop list columns, and the Add/Edit Workshop screen. Workshops now get a single **Schedule** picker.
- **Workshops** and **Schedule Categories** are unchanged.
- CSV export now has the columns `id, title, description, date, start, end, room, categories, schedule, previous_day`. The `event` column is gone.
- The Setup & Shortcodes page and the Settings page examples now describe the Schedules → Workshops → Categories setup.
- The front-end "Event" dropdown is gone. The Schedule dropdown replaces it.

**Migration (runs automatically after updating, and is safe to run again)**
- Any workshop with a Schedule Event but no Schedule is assigned to the Schedule with the same slug. If that Schedule doesn't exist yet, it is created with the event's name and description.
- Workshops that already have a Schedule keep it.
- Nothing is deleted. Old event data stays in the database.
- After the first run, a one-time notice in the admin says how many workshops were moved.
- Running it again (for example by deactivating and reactivating the plugin) changes nothing.

**Backward compatibility**
- `[convention_schedule event="slug"]` still works and shows the same workshops as before.
- `show_event_filter="1"` now shows the Schedule dropdown.
- Old `?csched_event=` links still work.
- A saved control order that included "Event" now uses "Schedule" in its place.
- CSV files exported from 1.8.x still import. A value in the `event` column is used as the schedule when the `schedule` cell is blank.
- If you change a workshop's Schedule, it also drops off the old event page it used to be on.

**Fixed**
- Re-importing an exported CSV now updates the existing workshops instead of creating duplicates. The Excel byte-order mark had been hiding the `id` column.
- CSV import now saves the `previous_day` flag, and export includes it.
- Updating a workshop by CSV no longer publishes it if it was a draft.
- A CSV without a `schedule` column no longer clears the schedules of the workshops it updates.
- When a CSV creates a schedule from a bare slug, the schedule gets a readable name ("spring-gathering" becomes "Spring Gathering").
