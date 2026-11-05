# Preview screens

The static screens in `deploy/dev/previews/` are images of the application for
review without PHP or a database — useful on a sandbox, in a pull request or on a
call. They are not a second implementation of anything: the dashboard preview is
**rendered from the real Blade views**, so it cannot describe a screen the
application no longer has.

## Why the dashboard is generated, not copied

Until v7.78 the dashboard preview was a hand-maintained pane
(`previews/_dashboard-content.html`) sitting inside a generated shell. Nothing
connected it to `resources/views/dashboard.blade.php`, so it fell out of date: it
was still showing the pre-redesign layout with no action centre, pinned reports,
arrivals, departures, room status, shortcuts, activity or department snapshots,
while `build-dashboard-preview.mjs --check` happily reported "up to date"
because it only hashed the hand-written pane.

The pane is gone. The preview is now the dashboard:

| Piece | Comes from |
| --- | --- |
| Content, all sections | `resources/views/dashboard.blade.php` + `dashboard/management-overview.blade.php` + `dashboard/management-trends.blade.php` |
| Sidebar | `config/pms.php` → `menu` |
| Icon sprite | `resources/views/partials/icons.blade.php` |
| Page head, top bar | `resources/views/layouts/app.blade.php` |
| Figures | fixed sample, `deploy/dev/preview/dashboard-sample.mjs` |

## Build and check

```bash
node deploy/dev/build-dashboard-preview.mjs           # writes previews/management-dashboard.html
node deploy/dev/build-dashboard-preview.mjs --check   # exit 1 when the file on disk is stale
node deploy/dev/audit/dashboard_preview_check.js      # 73 assertions about the rendered screen
```

`--check` now compares the whole regenerated page, so editing a dashboard view
makes it fail until the preview is rebuilt.

## How the renderer works

`deploy/dev/preview/blade-render.mjs` runs a small Blade subset against the sample
scope:

* `@if` / `@elseif` / `@else` / `@unless`, `@foreach` / `@forelse` / `@empty`,
  `@include`, `@php` (simple assignments only), `@push` (skipped), `@section`
  (extracted), `{{ }}` escaped and `{!! !!}` raw;
* `<x-icon>` and `<x-empty>` resolve against the page's own sprite;
* expressions are evaluated as JavaScript after a PHP→JS pass — `$x` variables,
  `->` access, `::` static calls, `?:`, keyed arrays (`['k' => 'v']`), casts,
  `fn ($x) => …`, string concatenation — so ternaries, tones and badges stay
  consistent with the data instead of being hard-coded per branch;
* services and model counts the views call (`KpiService::openEvents()`,
  `Company::where(...)->count()`, …) answer from the sample table in
  `dashboard-sample.mjs`.

The builder prints a render report. It must stay clean:

```
echoes evaluated ....... 1066
fallback values ........ 0
@php blocks skipped .... 3 (values supplied by the sample)
```

The three skipped blocks build the conic-gradient mix charts, which the sample
supplies pre-computed. Anything the renderer cannot evaluate is reported and the
harness fails if it reaches the page as `undefined`, `NaN` or a stray `{{`.

## What is sample

Every figure is a fixed sample: room counts, revenue, arrivals, activity,
department snapshots. The permission set is "staff member with every
permission", which is why every module contributes rows to the action centre.
The structure, wording, ordering, icons, classes, links and conditional logic are
the application's own.

The screen carries no notice saying it is a preview — it is presented as the
screen itself.

## Serving

```bash
PORT=4182 node deploy/dev/management-dashboard-preview-server.mjs                      # landing: fleet overview
PREVIEW_LANDING=management-dashboard PORT=4183 node deploy/dev/management-dashboard-preview-server.mjs
```

`/management-dashboard` and `/__preview/management-dashboard` serve the dashboard
on either instance; `/previews` lists every screen. The server is read-only and
serves only the previews folder, `public/css/*` and `public/images/*`.
