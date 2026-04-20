# Commit Plan — Psyerns AuctionHouse v1.0.0

Eight logical commits, not one per agent. Commits 1-7 land in the main repo (parent
of `WP-Plugin_Psyerns_AuctionHouse/`). Commit 8 is scoped to the
`Psyerns_Framework` mod repo (separate directory, separate `.git`).

**All commands assume the current working directory is the parent of the plugin
tree, i.e.** `C:\Users\Administrator\Desktop\Psyerns_Framework`. Do NOT `cd` into
the plugin folder for the git calls; git tracks the path as-is.

**Format.** Each commit uses Conventional Commits (`feat:`, `chore:`, `docs:`).
Subject line <70 chars, imperative mood. Body explains the why in 3-5 lines.
Author tag: `Psyern <info@kaiser-studios.de>` — no co-author line.

---

## 1 — Foundation: Database + Auth + Bootstrap

**Type.** `feat`
**Subject.** `feat(auctionhouse): bootstrap plugin with DB schema and auth`

**Files.**
* WP-Plugin_Psyerns_AuctionHouse/psyerns-auctionhouse.php
* WP-Plugin_Psyerns_AuctionHouse/uninstall.php
* WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-database.php
* WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-auth.php
* WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-steam-auth.php
* WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-api.php

**Stage + commit.**
```bash
git add WP-Plugin_Psyerns_AuctionHouse/psyerns-auctionhouse.php \
        WP-Plugin_Psyerns_AuctionHouse/uninstall.php \
        WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-database.php \
        WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-auth.php \
        WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-steam-auth.php \
        WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-api.php

git commit -m "$(cat <<'EOF'
feat(auctionhouse): bootstrap plugin with DB schema and auth

Add the Psyerns AuctionHouse plugin scaffolding: plugin header, activation
routine, uninstall cleanup, the 5-table dbDelta schema, the API-key guard for
/internal/* REST routes, the Steam OpenID 2.0 login flow, and the REST
namespace Psyern_AH_Api with stubs for service endpoints pending Phase 2.

Tables created: *_psyern_ah_listings, *_transactions, *_balances,
*_pending_actions, *_users.

Authored-by: Psyern <info@kaiser-studios.de>
EOF
)"
```

---

## 2 — Business Logic: Listings, Transactions, Stats, Balances, Pending, Upload

**Type.** `feat`
**Subject.** `feat(auctionhouse): add core services for marketplace and orders`

**Files.**
* WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-listings.php
* WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-transactions.php
* WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-stats.php
* WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-balances.php
* WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-pending-actions.php
* WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-upload.php

**Stage + commit.**
```bash
git add WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-listings.php \
        WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-transactions.php \
        WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-stats.php \
        WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-balances.php \
        WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-pending-actions.php \
        WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-upload.php

git commit -m "$(cat <<'EOF'
feat(auctionhouse): add core services for marketplace and orders

Implement the six Phase-2 service classes: Listings (CRUD + full-sync +
public/user queries), Transactions (delta-sync + history), Stats
(top-sellers + popular + avg-prices + price-history), Balances
(Expansion/Internal mirror), Pending_Actions (async state-machine
queued->dispatched->executing->success|failed_*), and Upload (single
mod-push entry point).

Write-handlers enforce WP nonces + per-user/per-type rate limits (10/60s).
Idempotency via UNIQUE action_uuid and INSERT IGNORE. Transactions
invalidate the price-history transient cache on successful insert.

Authored-by: Psyern <info@kaiser-studios.de>
EOF
)"
```

---

## 3 — Frontend: Shortcodes + Templates

**Type.** `feat`
**Subject.** `feat(auctionhouse): add public shortcodes and template partials`

**Files.**
* WP-Plugin_Psyerns_AuctionHouse/public/class-psyern-ah-shortcodes.php
* WP-Plugin_Psyerns_AuctionHouse/public/templates/marketplace.php
* WP-Plugin_Psyerns_AuctionHouse/public/templates/listing-card.php
* WP-Plugin_Psyerns_AuctionHouse/public/templates/listing-detail.php
* WP-Plugin_Psyerns_AuctionHouse/public/templates/my.php
* WP-Plugin_Psyerns_AuctionHouse/public/templates/history.php
* WP-Plugin_Psyerns_AuctionHouse/public/templates/stats.php
* WP-Plugin_Psyerns_AuctionHouse/public/templates/price-chart.php

**Stage + commit.**
```bash
git add WP-Plugin_Psyerns_AuctionHouse/public/class-psyern-ah-shortcodes.php \
        WP-Plugin_Psyerns_AuctionHouse/public/templates/

git commit -m "$(cat <<'EOF'
feat(auctionhouse): add public shortcodes and template partials

Register six shortcodes and seven logic-free template partials:
[psyerns_auctionhouse_marketplace], [..._listing], [..._my], [..._history],
[..._stats], [..._price_chart]. Each shortcode normalizes attributes,
resolves the active theme, enqueues CSS/JS via Psyern_AH_Theme, localizes
the shared psyernAh data object (apiBase, nonces, currentUser, translations,
currencyFormat), fetches server-side initial state, and includes a template
partial.

Authored-by: Psyern <info@kaiser-studios.de>
EOF
)"
```

---

## 4 — Frontend: JS + Chart.js

**Type.** `feat`
**Subject.** `feat(auctionhouse): add frontend JS bundles and Chart.js vendor`

**Files.**
* WP-Plugin_Psyerns_AuctionHouse/public/js/psyern-ah-marketplace.js
* WP-Plugin_Psyerns_AuctionHouse/public/js/psyern-ah-listing.js
* WP-Plugin_Psyerns_AuctionHouse/public/js/psyern-ah-my.js
* WP-Plugin_Psyerns_AuctionHouse/public/js/psyern-ah-price-chart.js
* WP-Plugin_Psyerns_AuctionHouse/public/vendor/chart.min.js

**Stage + commit.**
```bash
git add WP-Plugin_Psyerns_AuctionHouse/public/js/ \
        WP-Plugin_Psyerns_AuctionHouse/public/vendor/chart.min.js

git commit -m "$(cat <<'EOF'
feat(auctionhouse): add frontend JS bundles and Chart.js vendor

Ship four public JS modules: marketplace (filter/sort/paginate via AJAX),
listing (buy/bid buttons, live countdown), my (pending-action polling,
status badges), and price-chart (Chart.js wrapper with period switcher).
Chart.js 4.4.1 is bundled locally in /public/vendor to avoid any runtime
CDN dependency.

Authored-by: Psyern <info@kaiser-studios.de>
EOF
)"
```

---

## 5 — Frontend: CSS + Theme + Assets

**Type.** `feat`
**Subject.** `feat(auctionhouse): add public CSS, theme layer and default assets`

**Files.**
* WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-theme.php
* WP-Plugin_Psyerns_AuctionHouse/public/css/psyern-ah-public.css
* WP-Plugin_Psyerns_AuctionHouse/public/assets/img/default-item.png

**Stage + commit.**
```bash
git add WP-Plugin_Psyerns_AuctionHouse/includes/class-psyern-ah-theme.php \
        WP-Plugin_Psyerns_AuctionHouse/public/css/psyern-ah-public.css \
        WP-Plugin_Psyerns_AuctionHouse/public/assets/img/default-item.png

git commit -m "$(cat <<'EOF'
feat(auctionhouse): add public CSS, theme layer and default assets

Add the Psyern_AH_Theme helper that bridges into the Psyerns_Framework
plugin's CSS themes when active (Soft-Dependency) and falls back to a
standalone base stylesheet otherwise. Includes the fallback public CSS and
the default item-icon PNG used when the admin item-map has no entry.

Authored-by: Psyern <info@kaiser-studios.de>
EOF
)"
```

---

## 6 — Admin Panel

**Type.** `feat`
**Subject.** `feat(auctionhouse): add admin panel with six tabs`

**Files.**
* WP-Plugin_Psyerns_AuctionHouse/admin/class-psyern-ah-admin.php
* WP-Plugin_Psyerns_AuctionHouse/admin/css/psyern-ah-admin.css
* WP-Plugin_Psyerns_AuctionHouse/admin/js/psyern-ah-admin-tabs.js
* WP-Plugin_Psyerns_AuctionHouse/admin/views/settings-page.php
* WP-Plugin_Psyerns_AuctionHouse/admin/views/listings-page.php
* WP-Plugin_Psyerns_AuctionHouse/admin/views/history-page.php
* WP-Plugin_Psyerns_AuctionHouse/admin/views/balances-page.php
* WP-Plugin_Psyerns_AuctionHouse/admin/views/pending-page.php
* WP-Plugin_Psyerns_AuctionHouse/admin/views/tools-page.php

**Stage + commit.**
```bash
git add WP-Plugin_Psyerns_AuctionHouse/admin/

git commit -m "$(cat <<'EOF'
feat(auctionhouse): add admin panel with six tabs

Register the top-level AuctionHouse menu and dispatch six views:
Settings (API-key + currency format + visibility + item-map JSON editor),
Listings (admin-cancel), History (filter by date/player/item), Balances
(read-only mirror), Pending (action log with status filter), and Tools
(Force-Resync flag, cache-clear, table-reset with confirmation).

All admin_post_* handlers verify per-action nonces and require
manage_options; PRG redirects back to the referring tab with a transient
admin notice.

Authored-by: Psyern <info@kaiser-studios.de>
EOF
)"
```

---

## 7 — Packaging: readme.txt, POT, docs, test payload

**Type.** `chore`
**Subject.** `chore(auctionhouse): add packaging docs and test payload`

**Files.**
* WP-Plugin_Psyerns_AuctionHouse/readme.txt
* WP-Plugin_Psyerns_AuctionHouse/languages/psyerns-auctionhouse.pot
* WP-Plugin_Psyerns_AuctionHouse/KNOWN_ISSUES.md
* WP-Plugin_Psyerns_AuctionHouse/DEPLOY_SMOKETEST.md
* WP-Plugin_Psyerns_AuctionHouse/COMMIT_PLAN.md
* WP-Plugin_Psyerns_AuctionHouse/test-payload.json

**Stage + commit.**
```bash
git add WP-Plugin_Psyerns_AuctionHouse/readme.txt \
        WP-Plugin_Psyerns_AuctionHouse/languages/psyerns-auctionhouse.pot \
        WP-Plugin_Psyerns_AuctionHouse/KNOWN_ISSUES.md \
        WP-Plugin_Psyerns_AuctionHouse/DEPLOY_SMOKETEST.md \
        WP-Plugin_Psyerns_AuctionHouse/COMMIT_PLAN.md \
        WP-Plugin_Psyerns_AuctionHouse/test-payload.json

git commit -m "$(cat <<'EOF'
chore(auctionhouse): add packaging docs and test payload

Ship WordPress.org-format readme.txt, the 283-entry gettext POT file, a
KNOWN_ISSUES.md describing the four documented v1 limits (Steam-UID helper
redundancy, outbid-history not tracked, admin_post-vs-REST design choice,
non-zero-filled price buckets), a 10-step deploy smoke-test, the commit
plan itself, and a minimal test-payload.json covering all three upload
sections for the smoke-test step 4 curl.

Authored-by: Psyern <info@kaiser-studios.de>
EOF
)"
```

---

## 8 — (Separate repo) PF_AH_Sync mod module

This commit lives in a **different** repository: the `Psyerns_Framework` mod tree
(its own `.git`). Change directory first:

```bash
cd C:\Users\Administrator\Desktop\Psyerns_Framework\Psyerns_Framework
```

**Type.** `feat`
**Subject.** `feat(framework): add PF_AH_Sync module for WP bridge`

**Files.**
* Psyerns_Framework/scripts/3_Game/Psyerns_Framework/Integrations/AuctionHouse/PF_AH_Sync.c
* Psyerns_Framework/scripts/3_Game/Psyerns_Framework/Integrations/AuctionHouse/PF_AH_Uploader.c
* Psyerns_Framework/scripts/3_Game/Psyerns_Framework/Integrations/AuctionHouse/PF_AH_PendingPoller.c
* Psyerns_Framework/scripts/3_Game/Psyerns_Framework/Integrations/AuctionHouse/PF_AH_ActionExecutor.c
* Psyerns_Framework/scripts/3_Game/Psyerns_Framework/Integrations/AuctionHouse/PF_AH_BalanceReader.c
* Psyerns_Framework/scripts/3_Game/Psyerns_Framework/Integrations/AuctionHouse/PF_AH_Config.c
* Psyerns_Framework/scripts/5_Mission/Psyerns_Framework/PF_RestInit.c (modified)

**Stage + commit.**
```bash
git add scripts/3_Game/Psyerns_Framework/Integrations/AuctionHouse/ \
        scripts/5_Mission/Psyerns_Framework/PF_RestInit.c

git commit -m "$(cat <<'EOF'
feat(framework): add PF_AH_Sync module for WP bridge

Implement the DayZ-side half of the Psyerns AuctionHouse integration as a
new Integrations/AuctionHouse module. Timer-based orchestrator pushes
listings + transactions + balances every 30s via PF_WebClient and polls
for queued web actions every 10s, dispatching them through
DME_AH_AuctionManager and reporting results back via POST with _method=PATCH
marker (Enforce-Script RestContext limitation).

Authored-by: Psyern <info@kaiser-studios.de>
EOF
)"
```

---

## Notes

* **Author line only.** The user requested NO `Co-Authored-By: Claude` line; author
  attribution lives in the trailer (`Authored-by: ...`).
* **Parent repo vs. submodule.** Review `git status` in the parent repo first —
  the `WP-Plugin_Psyerns_AuctionHouse/` tree is currently untracked (per the
  session's initial git status), so all seven commits will be fresh adds.
* **Ordering.** Commits 1-2 are strict pre-requisites for 3-7 (REST registry
  needs Phase-2 service classes). Commits 3, 4, 5, 6 are independent-ish but
  ordered for review ergonomics. Commit 7 always last in the plugin scope.
  Commit 8 is temporally independent but the WP plugin must exist before any
  end-to-end test runs.
* **Skip `_build_pot.py`.** The POT extractor was a scratch script and has
  already been removed.
