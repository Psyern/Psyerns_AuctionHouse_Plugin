# Psyerns AuctionHouse — Deployment Smoke Test (v1.0.0)

Ten-step checklist to validate a fresh deployment. Each step has: command/click,
expected result, and the typical failure mode. Work top-to-bottom; stop on the first
failure and diagnose before proceeding.

Total runtime: ~15 minutes on a fresh sandbox, ~5 minutes on a warm install.

---

## Step 1 — Fresh WordPress install

**Command.** Provision a new WordPress instance:

```bash
# Example with WP-CLI + MySQL
wp core download --locale=de_DE
wp config create --dbname=psyern_smoke --dbuser=root --dbpass=secret
wp core install --url=http://localhost --title="Smoke Test" \
    --admin_user=admin --admin_password=admin --admin_email=smoke@test.local
```

**Expected.** WordPress dashboard reachable at `http://localhost/wp-admin`. PHP 8.1
and WP 6.x confirmed via `wp cli info`.

**Failure mode.** Database connection errors -> check `wp-config.php` credentials.

---

## Step 2 — Upload and activate the plugin

**Click.** `Plugins` -> `Add New` -> `Upload Plugin` -> select
`psyerns-auctionhouse.zip` -> `Install Now` -> `Activate`.

**Expected.** No fatal error. A new top-level menu entry `AuctionHouse` appears in
the admin sidebar. In `Plugins` list, the entry `Psyerns AuctionHouse 1.0.0` shows
`Aktiv`.

**Failure mode.** PHP fatal -> check WordPress debug log
(`wp-content/debug.log`) for missing class, syntax error, or mismatched
`require_once` path.

---

## Step 3 — Generate the API key

**Click.** `AuctionHouse` -> `Settings` -> locate `API-Key` field -> click `Rotate`
-> copy the displayed 48-char hex key.

**Expected.** Admin notice `API-Key rotiert`. The key field shows the new value.
Database: `SELECT option_value FROM wp_options WHERE option_name =
'psyern_ah_api_key'` returns the same hex string.

**Failure mode.** `Nonce-Pruefung fehlgeschlagen` -> your session expired; reload and
retry. No change visible -> confirm `current_user_can('manage_options')` for your
user.

---

## Step 4 — Internal upload (mod -> WP)

**Command.** Using the file `test-payload.json` in this directory (shipped alongside
this README):

```bash
curl -X POST "http://localhost/wp-json/psyern-ah/v1/internal/upload" \
    -H "Authorization: Bearer <PASTE_KEY_FROM_STEP_3>" \
    -H "Content-Type: application/json" \
    --data-binary @test-payload.json
```

**Expected.** HTTP 200 with body:

```json
{ "ok": true, "listings": {"upserted":1,"removed":0},
  "transactions": {"inserted":1,"skipped":0,"failed":0},
  "balances": {"upserted":2,"failed":0},
  "errors": [] }
```

The DB now contains 1 row in `*_psyern_ah_listings`, 1 in `*_psyern_ah_transactions`,
and 2 in `*_psyern_ah_balances`.

**Failure mode.** HTTP 401 -> key mismatch; verify Bearer token. HTTP 400
`invalid_payload` -> JSON syntax error; validate with `jq . test-payload.json`.

---

## Step 5 — Render the marketplace shortcode

**Click.** `Pages` -> `Add New` -> Title `Marketplace` -> content body contains
only `[psyerns_auctionhouse_marketplace]` -> `Publish` -> `View`.

**Expected.** The one listing from Step 4 renders as a card:
`TestSeller` selling `M4-A1 Sturmgewehr` for `5,000 €` (or whatever
`psyern_ah_currency_format` evaluates to). Filter UI visible in the left column.

**Failure mode.** Empty grid -> `AuctionHouse` -> `Listings` tab should still show
the row; if it does, the template is failing to fetch — check the browser console
for `wp-json` 500 errors on `/public/listings`.

---

## Step 6 — Steam OpenID login

**Click.** In an incognito window, navigate to:

```
http://localhost/wp-json/psyern-ah/v1/auth/steam/login?redirect=/marketplace
```

**Expected.** 302 redirect to `steamcommunity.com/openid/login`. After authorizing,
Steam bounces back to `/wp-json/psyern-ah/v1/auth/steam/callback?...`; plugin
verifies signature, upserts a row in `*_psyern_ah_users` and `wp_users` (login
prefix `steam_`), and redirects to `/marketplace`. The top-right WP admin bar shows
`Howdy, Steam <xxxxxx>`.

**Failure mode.** Generic Steam error page -> verify `home_url()` is publicly
reachable (OpenID realm check). `invalid_claimed_id` query arg after redirect ->
the regex didn't match; Steam returned a non-standard claimed_id. Contact support.

---

## Step 7 — Place a Buy-Now purchase

**Click.** Navigate to the listing detail page (from Step 5, click the first card).
Click `Sofort kaufen` button -> confirmation modal appears -> click `Kauf bestaetigen`.

**Expected.** HTTP 202 response from `POST /wp-json/psyern-ah/v1/user/purchase`
with body `{ "action_uuid": "...", "status": "queued" }`. UI shows `In Warteschlange`
badge. The `*_psyern_ah_pending_actions` table now has 1 row with
`action_type='purchase'`, `status='queued'`.

**Failure mode.** HTTP 403 `not_linked` -> Steam account wasn't linked in Step 6.
HTTP 409 `price_mismatch` -> the listing changed between page load and click
(expected behavior; retry). HTTP 429 -> rate limit hit (expected with >10 clicks
in 60s).

---

## Step 8 — Simulate mod dispatch + completion

**Command.** Two sequential curls simulating the PF_AH_Sync polling loop:

```bash
# 8a — dispatch (GET; atomically moves queued -> dispatched)
curl "http://localhost/wp-json/psyern-ah/v1/internal/pending?limit=10" \
    -H "Authorization: Bearer <API_KEY>"
# Copy the action_uuid from the response

# 8b — complete (POST with _method=PATCH marker; DayZ engine limitation)
curl -X POST "http://localhost/wp-json/psyern-ah/v1/internal/pending/<UUID>" \
    -H "Authorization: Bearer <API_KEY>" \
    -H "Content-Type: application/json" \
    -d '{"_method":"PATCH","status":"success","result_code":"0","result_message":"ok"}'
```

**Expected.**
* 8a returns `{ "actions": [ { "action_uuid": "...", "type": "purchase", ... } ] }`
  with the row from Step 7. DB: that row's `status` is now `dispatched`,
  `dispatched_at` is populated.
* 8b returns `{ "ok": true, "uuid": "..." }` with HTTP 200. DB: row status is
  `success`, `completed_at` is populated.

**Failure mode.** 8a returns empty `actions` -> the dispatch UPDATE didn't match;
check row `status` in the table. 8b returns 400
`missing_method_override` -> the `_method` field is not `PATCH`.

---

## Step 9 — Render the price chart shortcode

**Click.** Create page `Price Chart` with body:

```
[psyerns_auctionhouse_price_chart item_class="M4A1_AssaultRifle" period="30d"]
```

Navigate to the page.

**Expected.** Chart.js canvas renders. With only one transaction (Step 4), the line
may be a single point or empty-state card `Keine Verkaufshistorie fuer diesen
Zeitraum.`.

**Failure mode.** `Chart is not defined` in browser console -> the
`public/vendor/chart.min.js` asset failed to load; check the Network tab for a 404.

---

## Step 10 — Deactivate, reactivate, then uninstall

**Click.**
1. `Plugins` -> find `Psyerns AuctionHouse` -> `Deaktivieren`. Verify that
   `AuctionHouse` menu disappears but `wp_options` still has `psyern_ah_api_key` and
   `wp_psyern_ah_listings` table still has the row from Step 4.
2. `Plugins` -> `Aktivieren`. Verify menu returns, data intact, API key unchanged.
3. `Plugins` -> `Loeschen` -> confirm.

**Expected.** After deletion:
* `uninstall.php` runs.
* All 5 `wp_psyern_ah_*` tables are dropped (verify via
  `SHOW TABLES LIKE '%psyern_ah%'`, should return empty).
* All `psyern_ah_*` options are gone (verify via
  `SELECT option_name FROM wp_options WHERE option_name LIKE 'psyern_ah_%'`).
* Transients with the `psyern_ah_` prefix are cleaned.

**Failure mode.** Tables still present after delete -> check that `uninstall.php`
was actually executed; WP only runs it if the plugin is deleted via the WP admin
(not via filesystem `rm -rf`). Options still present -> `uninstall.php` short-circuited
before reaching the `delete_option` loop; inspect PHP error log.

---

## Smoke-Test Result Template

| Step | Status | Notes |
|------|--------|-------|
| 1. WP install            |   |   |
| 2. Plugin activation     |   |   |
| 3. API-Key rotation      |   |   |
| 4. Internal upload       |   |   |
| 5. Marketplace shortcode |   |   |
| 6. Steam login           |   |   |
| 7. Buy-Now purchase      |   |   |
| 8. Mod dispatch/complete |   |   |
| 9. Price chart shortcode |   |   |
| 10. Uninstall cleanup    |   |   |

Fill with `PASS`, `FAIL` + issue summary, or `SKIP` + reason.
