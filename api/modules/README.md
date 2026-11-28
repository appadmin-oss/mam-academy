# api/modules — the application's domain modules

| | |
|---|---|
| **Owner** | The academy's technical lead (whoever holds the `afv/` branches). Change the module map only with their review. |
| **Version** | 1.0 — 2026-11-27 (first cut: libraries moved into modules, behaviour unchanged) |
| **Guarded by** | `api/tests/test_module_boundaries.php`, `api/tests/test_code_structure.php` |

The PHP back end used to be 101 loose files in `api/lib/`, plus one hand-kept
list of them. They now live in **15 domain modules**. Each module owns its
files and states which other modules it calls. This is step one of a
modular-monolith restructure (see "Where this is going"). It moved files only.
No function, route, table or behaviour changed.

## The map

| Module | What it owns | Libraries |
|---|---|---|
| `kernel` | Request plumbing: logging, JSON responses, validation, the database and migrator, settings, sessions, CSRF, rate limits, idempotency, audit log, job queue, inline edit, backup checks | 13 |
| `integrations` | Outbound services: mail, FX, WhatsApp (Kapso), Klangio, AI gateway, Google Drive, private media store (R2), uploads | 8 |
| `site` | Facts the public pages print | 1 |
| `identity` | People and access: auth, member records, guardians, consent, data-subject rights, NDPA layer, ID cards | 7 |
| `safeguarding` | Staff vetting, the safeguarding gate, concerns and incidents | 2 |
| `finance` | Money: minor units, Paystack, Flutterwave, settlement, payment guards, booking pricing, application invoices, fee invoices and aid, invoice review, wallet, promotions, store | 12 |
| `academics` | Academic structure: sessions and calendar, courses and cohorts (LMS), legacy-class carry-over, curriculum seed, scheduling, slot finder, rescheduling, make-ups | 12 |
| `admissions` | Getting in: student, scholarship and faculty applications, Auditer placement, completing an enrolment, decisions, funnel, referrals | 8 |
| `learning` | Lessons, exams, adaptive questions, pre-class, marking, mastery, progress, termly reports, academic record, accountability, growth, academic email | 14 |
| `live` | Running a class: clock-in, meeting links, live classes (JaaS), attendance, check-in | 5 |
| `community` | Class spaces, Recital Hall, repertoire, challenges, achievements, studio leaderboard, practice logs | 7 |
| `comms` | Notification inbox, guardian threads, support hub, lifecycle automations | 4 |
| `portals` | Read providers for the student and instructor portal screens | 2 |
| `admin` | Console read model, phone subset, upper-admin, BI, analytics | 6 |
| `v2` | The dormant schema-v2 ETL and read model (only `readmodel` loads, and it is off unless `V2_READ_DB`/`V2_READ_DSN` is set) | 1 loaded, 8 more used by the ETL tools |

Each module is a folder:

```
api/modules/<name>/
  module.php    manifest: name, depends, libs (in load order)
  lib/*.php     the module's libraries
```

`api/modules/registry.php` lists the modules in load order.
`api/modules/libs.php` resolves registry + manifests into the library list
(`name => path`). `api/lib/bootstrap.php` (the app) and `api/tests/run.php`
(the suite) both read it, so there is still exactly one list. It throws if a
manifest is inconsistent: a library named twice, a listed file missing, or an
unknown module.

## The rules

1. **A library belongs to exactly one module**, and the module's manifest
   names it. A file a manifest does not name is never loaded, and
   `test_code_structure.php` fails on it.
2. **`depends` is the measured truth.** It lists every module this one calls.
   `test_module_boundaries.php` measures the call graph from source
   (`api/modules/graph.php`). It fails on a call into a module the manifest
   does not name, and on a declared dependency that is no longer used. New
   coupling is therefore a visible one-line manifest edit in review.
3. **The kernel depends on nothing new.** Its current reach into six modules
   is pinned as debt (below), so it can only shrink.
4. **Root pages, routes and `admin/index.php` call module functions. They do
   not belong to a module yet.** That is steps 2 and 3.

See the graph at any time:

```bash
php api/tools/module-graph.php               # every edge, with call counts
php api/tools/module-graph.php --undeclared  # only the edges a manifest is missing
```

## How to …

**Add a library.** Create `api/modules/<module>/lib/<name>.php` and add
`'<name>'` to that module's `libs` in `module.php`. Run
`php api/tests/run.php`. If the new code calls another module, the boundary
test names the edge. Add it to `depends` if it is right, or move the code if
it is not.

**Add a module.** Create the folder, `module.php` and `lib/`, then add the
name to `registry.php`. Use a module only for a real domain with its own
tables and rules, not one per feature.

**Move a library between modules.** `git mv` the file, move its name between
the two manifests, and update path references with
`grep -rn "modules/<old>/lib/<name>.php"`. Run the suite, then
`php api/tools/module-graph.php` and tidy each manifest's `depends`.

## Known debt (measured 2026-11-27)

These are real couplings the first cut exposed rather than created:

- **The kernel calls into domain modules.**
  - `jobs.php` drives academics and learning sweeps. (Its one admissions call,
    the abandoned-draft nudge, was dead and was removed on 2026-11-28, so the
    kernel no longer depends on admissions.)
  - `validation.php` calls the promo discount (finance) and the upload-URL
    check (integrations).
  - `log.php` prunes logs through the identity/NDPA layer.
  - `backup.php` uses identity.

  These belong in their modules, scheduled or called from outside the kernel.
- **Cycles.** academics ↔ live, academics ↔ learning, identity ↔ learning,
  identity ↔ community, finance ↔ admissions. Most come from "is this student
  enrolled / allowed" checks, which are written in nine places (see the
  structural audit). One `academics` predicate that the others call would cut
  most of them.
- **`lms` / `lms2` and `analytics` / `analytics2`** are second files added
  beside the first rather than merged into it.
- **`admin` reads everything** (depends on 10 modules). That is acceptable
  for a read model, but its SQL should move into the owning modules'
  functions.

## Where this is going

1. ✅ **Libraries into modules**, with measured boundaries (this step).
2. **Routes into modules.** Move each route declaration out of the 509-line
   `api/index.php` into `api/modules/<m>/routes.php`, and the handler files
   with it.
3. **One write path.** Move the 137 `admin/index.php` action handlers into
   their modules' functions. Then the console form, the JSON API and the
   payment webhook call the same code, with one capability table,
   deny-by-default.
4. **Data.** One migration source with a fail-fast runner run at deploy time,
   not per request. Dead tables dropped, the identity tables reconciled,
   money in integer minor units only. Each needs the owner's sign-off because
   it touches production data.
5. **Autoloading.** Once each module has a small public surface, give it a
   namespace and let Composer's classmap load it on demand instead of all 101
   files on every request.
