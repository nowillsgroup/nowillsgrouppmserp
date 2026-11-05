# The reservation workspace

One screen the front desk can run a shift from: the day's numbers, the rooms as
pictures, the same rooms as a table, and the work that has to be done before the
phone rings. This document says what each part reads, what it may open, and which
promises the screen keeps.

| Piece | File |
| --- | --- |
| Screen | `resources/views/reservations/workspace.blade.php` |
| Controller | `app/Http/Controllers/ReservationWorkspaceController.php` (`index`, `report`) |
| Data | `app/Services/ReservationWorkspaceService::snapshot()` |
| Printed day | `resources/views/reservations/workspace-report.blade.php` |
| Routes | `reservations.workspace` (`GET /reservations/workspace`, `perm:reservations.view`), `reservations.workspace.report` |

---

## 1. What the screen shows

**Header** — the date stepper (bounded to ±62 days around the hotel business date
by `boardDate()`), then Arrivals, Departures, In house and Rooms sold.

**KPI strip** (`kpiTiles()`) — nine tiles: value, 14-day sparkline, change against
the previous selected day, and the screen each tile opens. Labels follow the
selected date ("Booked room-rate value" in the future, "Room-rate value" in
history, "Guests staying" instead of "In-house guests"). A tile only becomes a
link when it has somewhere honest to go — the selected date must be today and the
user must hold the tile's own permission.

**Command bar** — search, the six filters (status, type, floor, source,
housekeeping, VIP), removable chips for whatever is active, a group-by control and
the Board / Rack switch. Filter state lives in the address bar
(`status`, `type`, `floor`, `source`, `hk`, `vip`, `q`), as do the layout and the
grouping (`view`, `group`), so any view of the board can be linked or refreshed.
The layout last used is remembered in `sessionStorage` for a bare reload.

**Board** — one card per active room, and the card is the room's picture: the
photograph is full-bleed at the top, with the room number, type, floor, status,
housekeeping state and "arriving on this date" laid over it, size / occupancy /
smoking and the rate along the bottom, and the amenities, guest and stay, and the
actions underneath.

**Rack** — the same rooms as one dense table, sortable by column, with the same
inline actions and a thumbnail of the same picture. Both views are built from the
same card markup, so they cannot disagree; the rack is only built when it is
first opened.

**Rail** — "Needs attention" first, then the month calendar (arrival / departure
dots, dates outside the selectable range disabled), housekeeping and status
snapshots, the waitlist, and quick links.

**Room dialog** — facts, amenities, guest and stay, money, and the actions that
need a decision, for one room.

## 2. Needs attention

`ReservationWorkspaceController::attention()` derives the list from the snapshot
the board already rendered, so the counts can never disagree with the cards beside
them. Both the screen and the printed day report call it.

| Key | Counts | Tone | Opens |
| --- | --- | --- | --- |
| `unassigned` | Arrivals for the date with no room | amber | Arrivals |
| `checkin-blocked` | Arrivals whose room would refuse the check-in (not vacant, inactive, inventory block) | red | Arrivals |
| `checkout-held` | Departures held by POS orders, trips, activities, Form C or a balance | red | Departures |
| `rooms-not-ready` | Arrival rooms still dirty or waiting for a touch-up | amber | Housekeeping (`housekeeping.view`) |
| `waitlist` | Guests on the waitlist | slate | Reservations, waitlist view |

Each row carries `{key, label, count, detail, url, tone}`; `url` is `null` when the
user may not open the screen that fixes it, and the row is then plain text. When
the list is empty the card says so.

## 3. Pictures

A card shows, in order of preference:

1. the room's own primary photo (`RoomPhotoService`, `r_room_images`), at its
   640px card copy — `RoomImage::url('thumb')` falls back to the full picture when
   no copy exists (photos stored before thumbnails, hosts without GD);
2. the room type's picture (`room_types.image`);
3. a generated colour block, so a room with no picture still reads as a card.

The photograph is an `<img loading="lazy">`, so only the cards in view fetch it.
The room dialog uses the full picture; the rack uses the card copy. A printed
board shrinks the picture to a 104px band.

## 4. Keyboard and focus

| Key | Does |
| --- | --- |
| `/` | Focus the search |
| `B` | Board |
| `R` | Rack |
| `G` | Cycle the grouping |
| `Esc` | Clear the search, then leave the field; close a dialog |

Cards and rack rows are reachable by keyboard; the dialog traps Tab, restores
focus to whatever opened it, and locks the page behind it. Filters announce the
visible count through a polite live region.

## 5. Permissions

`perm:reservations.view` opens the screen. Inside it, everything that changes
something is gated separately and nothing is implied:

`reservations.edit`, `reservations.create`, `frontdesk.checkin`,
`frontdesk.checkout`, `frontdesk.roommove`, `frontdesk.view`,
`housekeeping.view` / `attendant` / `manage`, `folio.view`, `revenue.view`.

A missing permission hides the control rather than disabling it, and the
housekeeping columns disappear entirely without a housekeeping permission.

`node deploy/dev/audit/permission_reference_check.js` checks every permission the
application asks for — this screen's included — against the catalogue in
`config/pms.php`, and checks that the standard roles in `HotelProvisioner::ROLES` only
grant tokens (or prefixes) that exist.

## 6. Proving it

```bash
node deploy/dev/build-workspace-preview.mjs            # renders the screen from its own view
node deploy/dev/build-workspace-preview.mjs --check    # fails when the preview is stale
node deploy/dev/audit/workspace_preview_check.js       # drives the rendered page in jsdom
python3 deploy/dev/audit/workspace_check.py            # source contract: ids, CSS, permissions
node deploy/dev/audit/css_syntax_check.js              # every stylesheet the app ships parses
node deploy/dev/audit/permission_reference_check.js    # every permission asked for is grantable
```

The preview is the real view — including its `@push('head')` styles and
`@push('scripts')` behaviour — so the harness exercises the board, the filters,
the rack, the grouping, the dialogs and the landmarks rather than a mock-up. See
`docs/PREVIEWS.md` for how the renderer works.
