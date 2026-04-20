# Psyerns AuctionHouse — Known Issues (v1.0.0)

Three documented v1 limits. All are deliberate scope-calls, not bugs. Each entry has
an impact description, any workaround, and the planned resolution path.

Item #1 (Steam-UID helper redundancy in Listings + Pending-Actions) was resolved in
the same v1.0.0 release — see Changelog.

---

## 2. Outbid-history not tracked — `/user/bids` can hide listings

## 1. Outbid-history not tracked — `/user/bids` can hide listings

**Description.** `GET /user/bids` (the handler behind `[psyerns_auctionhouse_my]`
bid list) selects listings WHERE `current_bidder_uid = <me>`. If User A bids, is
outbid by User B, and then User B is overbid by User C, the listing disappears from
User A's feed entirely — there is no row to flag as "Outbid" because A is neither the
current bidder (C is) nor the seller.

**Impact.** A user who bid early in an active auction and was overbid twice will not
see the listing in their `Mein Bereich` view at all. They can still navigate to the
listing detail page directly and see their previous bid in the on-page history.

**Workaround.** For now, direct links to listing-detail pages preserve the full bid
history. The admin Pending tab also records every bid action that was successfully
dispatched to the mod.

**Planned resolution (v2.0).** Two options, not yet decided:
  * Add a new `psyern_ah_bid_history` table populated by the mod's PF_AH_ActionExecutor
    on every bid (delta upload).
  * OR: Keep the current schema but have the mod push a per-listing bidder-roster
    array that WP mirrors to a denormalized column.

---

## 2. Two admin actions use `admin_post_*` instead of REST

**Description.** Force-Resync and Reset-Data are wired through
`add_action('admin_post_psyern_ah_force_resync', ...)` and
`add_action('admin_post_psyern_ah_reset_data', ...)` rather than as REST routes.
The other 15 admin write-endpoints are REST routes under `psyern-ah/v1/internal/*`.

**Impact.** None — intentional design choice. These endpoints need WP's native admin
nonce flow (`check_admin_referer`) and the POST-Redirect-GET pattern for the classic
admin notice UX. They are NOT called by the mod or external tooling; they are purely
admin-UI triggers with side effects (filesystem flag + table truncation).

**Workaround.** Not applicable.

**Planned resolution.** None planned. If a headless admin API is ever requested (for
CI tooling or a companion mobile app), these would be added as additional REST routes
without removing the existing `admin_post_*` handlers.

---

## 3. Price-history buckets are not zero-filled

**Description.** `GET /public/price-history?item_class=...&period=...` returns only
buckets that contain at least one sale. Empty windows (e.g. a 30-day chart with one
item that sold only on days 5, 12, and 28) omit the non-selling days from the JSON
response entirely.

**Impact.** The Chart.js frontend renders empty windows as gaps in the line. This is
visually correct — a gap means "no data" rather than "zero sales" — but can confuse
users who expect a continuous time axis.

**Workaround.** The frontend uses `spanGaps: false` (explicit gaps) rather than
interpolation; the current UX is the designed one.

**Planned resolution.** Not planned. Zero-filling would either require a client-side
sparse-fill (adds ~30 lines of JS per chart instance) or a server-side synthetic-bucket
emission (adds SQL complexity for minimal UX gain). If user feedback changes the
assessment, we would prefer the client-side approach because the API-response stays
semantically honest ("no data" vs. "zero").

---

_Last updated: 2026-04-20 (v1.0.0)_
