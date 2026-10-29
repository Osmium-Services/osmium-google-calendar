# Google Calendar

Puts confirmed bookings from Osmium Appointments, Hire and Reservations on a Google Calendar. It listens to the `appointment.*`, `hire.*` and `reservation.*` hooks: `confirmed` and `changed` create or replace the event, `declined` and `cancelled` remove it. One-way only; the site is the source of truth.

- **Event id:** derived from the booking (`md5("reservation:7")`), so a booking always maps to the same event. Nothing is stored, and the booking services are never called.
- **Titles:** `<type> - <name>`, `Table for 4 - <name>`, `Hire: <group> - <name>` (all-day). Prefixed by kind so two services can share one calendar.
- **Auth:** a Google service account (JSON key pasted in Content > Google Calendar, kept in `app/config/services/google-calendar.json.php`), signed JWT, no SDK. Share the calendar with the service account's email.
- **Not set up yet:** the handlers do nothing, so bookings carry on as normal. A failing Google call is logged by core and never stops a booking.

Only bookings confirmed after install are sent; earlier ones are not back-filled.
