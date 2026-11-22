# Convention Schedule – Changelog

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
