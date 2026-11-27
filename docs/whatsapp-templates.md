# WhatsApp templates (Kapso)

Schedule messages go out as **approved WhatsApp templates** (category **Utility**,
language **en**), because WhatsApp allows free-form text only within 24 hours of
the recipient's last message. Submit these four in Kapso (Templates) or Meta
Business Manager with exactly these names and bodies. The names are settings
(`kapso_tpl_*`) if you must use others; the parameter ORDER is fixed in
`api/modules/integrations/lib/kapso.php` (`MAM_KAPSO_TEMPLATES`).

| Name | Body | {{1}} | {{2}} | {{3}} | {{4}} | {{5}} |
|---|---|---|---|---|---|---|
| `mam_session_reminder` | Hello, this is MAM Academy. {{1}} has {{2}} on {{3}} ({{4}}). See you there. | learner | class | when | where | |
| `mam_session_rescheduled` | MAM Academy: {{1}}'s {{2}} has moved from {{3}} to {{4}}. Reason: {{5}}. Your calendar updates automatically if you subscribed. | learner | class | old time | new time | reason |
| `mam_session_cancelled` | MAM Academy: {{1}}'s {{2}} on {{3}} is cancelled. Reason: {{4}}. A free make-up class has been credited; nothing is charged. | learner | class | when | reason | |
| `mam_timetable_ready` | MAM Academy: {{1}}'s timetable for {{2}} is ready — {{4}} sessions, starting {{3}}. Open the portal to add it to your calendar. | learner | class | first session | number of sessions | |

The console's **Send test message** uses Meta's built-in `hello_world` template,
which every WhatsApp Business account already has.

## What must be set

- `KAPSO_API_KEY` (environment, preferred) or the Kapso API key field on the
  console's Integrations card.
- **Phone number ID** (Kapso → your connected number).
- Optional: `KAPSO_WEBHOOK_SECRET` + point Kapso's Meta webhook at
  `https://<your host>/api/whatsapp/kapso-webhook` to record delivered/read and
  the 24-hour window.

Who receives: guardians and staff who have **explicitly** turned WhatsApp on in
their notification settings. Students' own numbers are never messaged unless
`kapso_message_students` is set to 1. Safeguarding and money messages ignore the
toggle, as everywhere else (`mam_guardian_channels()`).
