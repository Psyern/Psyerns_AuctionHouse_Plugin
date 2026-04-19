# Psyerns AuctionHouse — Multi-Agent Orchestrator Prompt

> **Verwendung:** Diesen gesamten Prompt in ein frisches Claude-Code-Terminal im Arbeitsverzeichnis `C:\Users\Administrator\Desktop\Psyerns_Framework` einfügen. Der Orchestrator-Agent spawnt die unten definierten Spezialisten-Agenten in den vorgegebenen Phasen.

---

## 0. Kontext für den Orchestrator

Lies in dieser Reihenfolge, **bevor** du Agenten spawnst:

1. `C:\Users\Administrator\Desktop\Psyerns_Framework\.claude\CLAUDE.md` — Projekt-Regeln
2. `C:\Users\Administrator\Desktop\Psyerns_Framework\.claude\rules\wordpress-plugin.md` — WP-Coding-Standards
3. `C:\Users\Administrator\Desktop\Psyerns_Framework\.claude\rules\coding-rules.md` — EnforceScript-Rules (für Phase 4, Mod-Side)
4. `C:\Users\Administrator\Desktop\Psyerns_Framework\WP-Plugin_Psyerns_AuctionHouse\README.md` — **Die vollständige Spec mit 16 Entscheidungen**
5. `C:\Users\Administrator\Desktop\Psyerns_Framework\WP-Plugin_Psyerns-Framework\` — Referenz-Plugin (gleiche Architektur!)
6. `C:\Users\Administrator\Desktop\DME_Auction_House\scripts\3_Game\DME_AH\Data\` — Datenquellen-Klassen der Mod

**Oberste Regel:** Die README.md im Plugin-Ordner ist die **Single Source of Truth** für alle Design-Entscheidungen. Bei Unklarheiten immer dort nachschauen, nicht improvisieren.

---

## 1. Projekt-Identität

| Feld | Wert |
|---|---|
| Plugin-Name | Psyerns AuctionHouse |
| Plugin-Slug | `psyerns-auctionhouse` |
| Text-Domain | `psyerns-auctionhouse` |
| REST-Namespace | `psyern-ah/v1` |
| Klassen-Prefix | `Psyern_AH_` |
| DB-Prefix | `{wp_prefix}psyern_ah_` |
| WP-Plugin-Pfad | `C:\Users\Administrator\Desktop\Psyerns_Framework\WP-Plugin_Psyerns_AuctionHouse` |
| Mod-Side-Pfad | `C:\Users\Administrator\Desktop\Psyerns_Framework\Psyerns_Framework\scripts\3_Game\Psyerns_Framework\Integrations\AuctionHouse` |
| Daten-Quelle | `C:\Users\Administrator\Desktop\DME_Auction_House` (read-only) |

---

## 2. Mission

Spieler sehen aktive Auktionen aus der DayZ-Mod `DME_Auction_House` im Browser, inkl. Preis-Entwicklungs-Chart, und können nach **Steam-OpenID-Login** direkt per Web kaufen und bieten — alles mit Balance-Reservierung beim DayZ-Server als **Single Source of Truth**.

---

## 3. Architektur-Überblick (aus README §4/§5/§6)

```
DayZ Server:
  DME_Auction_House (JSON-Files: ActiveListings, CompletedListings, PlayerData)
  Expansion Market  (JSON-Files: ATM\{UID}.json)
        ↓ gelesen von
  Psyerns_Framework → Integrations/AuctionHouse (PF_AH_Sync)
        ↓ HTTPS + Bearer API-Key
WordPress Plugin (dieses Verzeichnis):
  REST psyern-ah/v1 → 5 DB-Tabellen → 5 Shortcodes + Admin-Panel
        ↑
  Browser (Steam-OpenID-Login, AJAX-Filter, Chart.js-Preisgraph)
```

**Kernprinzipien** (siehe README §4):
1. Mod = Source of Truth für Balances und Listing-Status
2. Push (30 s) + Poll (10 s) — asynchrones Command-Pattern
3. Atomare Balance-Reservierung verhindert Double-Spending
4. Full-Sync Listings, Delta-Sync Transaktionen

---

## 4. Geplante Verzeichnis-Struktur (aus README §11)

Siehe README.md Abschnitt 11 für die komplette Struktur. Kurzform:

```
WP-Plugin_Psyerns_AuctionHouse/
├── psyerns-auctionhouse.php          # Bootstrap
├── uninstall.php
├── includes/ (10 Klassen)
├── admin/ (1 Klasse + CSS/JS/5 views)
├── public/ (1 Klasse + CSS/JS/Chart.js + 6 templates)
└── languages/psyerns-auctionhouse.pot

Psyerns_Framework/scripts/3_Game/Psyerns_Framework/Integrations/AuctionHouse/
├── PF_AH_Sync.c
├── PF_AH_Uploader.c
├── PF_AH_PendingPoller.c
├── PF_AH_ActionExecutor.c
├── PF_AH_BalanceReader.c
└── PF_AH_Config.c
```

---

## 5. Agent-Plan (12 Agenten in 5 Phasen)

### PHASE 1 — Foundation (3 parallel)

#### Agent 1: Database Layer
**Scope:** Alle DB-Tabellen, Schema, CRUD-Wrapper, Uninstall-Cleanup.

**Dateien:**
- `includes/class-psyern-ah-database.php`
- `uninstall.php`

**Aufgaben:**
1. Klasse `Psyern_AH_Database` mit statischen Methoden:
   - `create_tables()` — via `dbDelta()`, 5 Tabellen (`listings`, `transactions`, `balances`, `pending_actions`, `users`)
   - `get_table_name( $suffix )` — z.B. `{wp_prefix}psyern_ah_listings`
   - `drop_tables()` — für Uninstall
   - Alle Schemas aus README §6 übernehmen (genau die dort angegebenen Felder und Indexe)
2. DB-Versionierung: Option `psyern_ah_db_version`, Schema-Upgrade bei Versions-Mismatch
3. `uninstall.php` — WP_UNINSTALL_PLUGIN Check, `drop_tables()`, Options löschen

**Design-Regeln:**
- Prepared Statements durchgehend (kein rohes SQL)
- UTF8MB4 für alle Tabellen
- `dbDelta()` exakt nach WP-Konvention formatiert
- Kein Emoji im Code, kein unnötiger Kommentar

**Quality Gate:** Aktivierung auf einer frischen WP-Installation muss alle Tabellen anlegen, Deaktivierung darf nichts löschen, Uninstall muss alles entfernen.

---

#### Agent 2: Auth Layer (API-Key + Steam OpenID)
**Scope:** Beide Auth-Flows: Server-to-Server via API-Key, Browser-Login via Steam OpenID 2.0.

**Dateien:**
- `includes/class-psyern-ah-auth.php`
- `includes/class-psyern-ah-steam-auth.php`

**Aufgaben:**

1. `Psyern_AH_Auth`:
   - `validate_api_key( WP_REST_Request )` — liest `Authorization: Bearer <key>`, vergleicht mit Option `psyern_ah_api_key` via `hash_equals()`
   - `generate_api_key()` — cryptographisch random, 48 Zeichen
   - Wird als `permission_callback` für alle `/internal/*` Routes eingesetzt

2. `Psyern_AH_Steam_Auth`:
   - `handle_login_redirect()` — Baut Steam-OpenID-URL (`https://steamcommunity.com/openid/login`), redirect
   - `handle_callback()` — Validiert OpenID-Response via `check_authentication` POST an Steam, extrahiert Steam-UID (64-bit)
   - `get_or_create_wp_user( $steam_uid )` — Sucht in `users`-Tabelle (Mapping WP-User ↔ Steam-UID), erstellt WP-User wenn nicht vorhanden (Username: `steam_{uid}`), loggt ihn ein via `wp_set_auth_cookie()`
   - `fetch_steam_profile( $steam_uid )` — Optional: Steam-Name + Avatar via Steam Web API (wenn API-Key konfiguriert), cached
   - Alle Redirect-URLs sicher whitelisten (gegen Open-Redirect-Attacke)

**Design-Regeln:**
- OpenID-Signatur MUSS verifiziert werden — nie `claimed_id` blind trusten
- `nonce`-Parameter bei Login-Redirect gegen Replay-Attacken
- Steam-UID regex: `^7656119\d{10}$`

**Quality Gate:** Manueller Test: Login-Button → Steam-Auth-Seite → Callback → eingeloggter WP-User mit Steam-UID im Meta.

---

#### Agent 3: Plugin Bootstrap + REST Registry
**Scope:** Plugin-Header, Main-File, Route-Registrierung (Stubs), Activation/Deactivation, Textdomain.

**Dateien:**
- `psyerns-auctionhouse.php` (Hauptdatei)
- `includes/class-psyern-ah-api.php`

**Aufgaben:**

1. `psyerns-auctionhouse.php`:
   - WordPress-Plugin-Header (Name, URI, Description, Version=1.0.0, Author=Psyern, TextDomain=psyerns-auctionhouse, Requires at least: 5.8, Requires PHP: 7.4, License: MIT)
   - `ABSPATH`-Check oben
   - Konstanten: `PSYERN_AH_VERSION`, `PSYERN_AH_DB_VERSION`, `PSYERN_AH_PLUGIN_DIR`, `PSYERN_AH_PLUGIN_URL`, `PSYERN_AH_PLUGIN_BASENAME`
   - `require_once` für ALLE Klassen aus `includes/`, `admin/`, `public/`
   - `register_activation_hook` → `Psyern_AH_Database::create_tables()`, default Options setzen
   - `register_deactivation_hook` → nur Transients löschen
   - `plugins_loaded` Hook → DB-Version prüfen, bei Mismatch `create_tables()` erneut
   - `init` Hook → `load_plugin_textdomain( 'psyerns-auctionhouse', false, dirname( PSYERN_AH_PLUGIN_BASENAME ) . '/languages' )`
   - `rest_api_init` → `(new Psyern_AH_Api())->register_routes()`

2. `Psyern_AH_Api`:
   - Konstante `NS = 'psyern-ah/v1'`
   - `register_routes()` — ALLE 17+ Routes aus README §7 registriert
   - Callbacks als Stub: `function($r) { return new WP_REST_Response(['todo'=>'agent-X'],501); }` — Spätere Agenten ersetzen die Stubs durch echte Callbacks via `remove_action` nicht nötig, da diese Routen dann im jeweiligen Service direkt registriert werden. Alternative: hier nur ein zentraler `register_routes()` und die Callbacks verweisen auf Methoden aus den Service-Klassen (bevorzugt!). **→ Bevorzugt: Service-Klassen werden per DI in Api-Klasse injiziert, Callbacks verweisen auf `array($service,'handle_xyz')`. Da die Services erst in Phase 2 entstehen, generiert Agent 3 Stub-Services im gleichen Stil mit allen Methoden die später befüllt werden.**
   - `permission_callback` korrekt gesetzt:
     - `/internal/*` → `array('Psyern_AH_Auth','validate_api_key')`
     - `/user/*` → `function() { return is_user_logged_in(); }` (+ Nonce-Check im Callback)
     - `/public/*` und `/auth/*` → `__return_true`

**Design-Regeln:**
- WordPress-Plugin-Header exakt nach Standard
- Keine Hooks im Konstruktor, alle in expliziter `init()`-Methode
- Keine Ausgabe (`echo`, `print`) außerhalb von Templates

**Quality Gate:** Plugin aktivierbar ohne Fatal-Errors. `curl /wp-json/psyern-ah/v1/internal/ping` mit gültigem API-Key gibt 200. Ohne Key gibt 401.

---

### PHASE 2 — Business Logic (3 parallel, startet nach Phase 1)

#### Agent 4: Listings Service + Public Endpoints
**Scope:** Listings-CRUD + Filter/Sort/Pagination + öffentliche Endpoints.

**Dateien:**
- `includes/class-psyern-ah-listings.php`

**Aufgaben:**
1. `Psyern_AH_Listings`:
   - `upsert_listing( $data )` — Wird von Upload-Endpoint aufgerufen
   - `get_listing_by_id( $listing_id )` — einzelnes Listing inkl. Category-Join
   - `get_listings( $args )` — mit Filter: `category_id`, `listing_type`, `price_min`, `price_max`, `search` (LIKE auf `item_display`), Sort (`price_asc|price_desc|time_asc|time_desc|newest|bid_count`), `page`, `per_page` (default 20, max 100)
   - `full_sync( $listings_array )` — löscht alle nicht im Array enthaltenen Listing-IDs und upsert den Rest (Full-Sync-Logik aus README §13)
   - `handle_get_public()` — GET `/public/listings`
   - `handle_get_single()` — GET `/public/listings/{id}`
   - `handle_get_categories()` — GET `/public/categories` (aus Option `psyern_ah_categories`)

**Design-Regeln:**
- Preis als BIGINT (Integer, keine Floats) — Konsistenz mit Mod
- `sanitize_text_field`, `absint`, etc. durchgehend
- Filter-Hook: `apply_filters( 'psyerns_ah/listings_query_args', $args )` für Erweiterbarkeit

---

#### Agent 5: Transactions + Stats + Price History
**Scope:** Historie, Aggregationen, Preis-Zeitreihen für Charts.

**Dateien:**
- `includes/class-psyern-ah-transactions.php`
- `includes/class-psyern-ah-stats.php`

**Aufgaben:**

1. `Psyern_AH_Transactions`:
   - `add_transactions( $array )` — Idempotent via `transaction_id` UNIQUE, ignoriert Duplikate
   - `get_recent( $limit, $offset )` — für History-Shortcode
   - `get_last_timestamp()` — für Delta-Sync (WP → Mod: „ich habe bis TS X")
   - `handle_get_history()` — GET `/public/history`

2. `Psyern_AH_Stats`:
   - `get_top_sellers( $period, $limit )` — GROUP BY seller_uid, SUM(final_price), über Zeitraum
   - `get_popular_items( $period, $limit )` — GROUP BY item_class, COUNT(*)
   - `get_avg_prices( $period, $limit )` — GROUP BY item_class, AVG(final_price)
   - `get_price_history( $item_class, $period )` — Zeitreihe mit Buckets (README §16):
     - `24h` → 24 Stunden-Buckets
     - `7d`  → 168 Stunden-Buckets
     - `30d` → 30 Tages-Buckets
     - `all` → wöchentliche Buckets, max 52
   - Response-Shape: `[{bucket_ts, avg_price, min_price, max_price, sale_count}, ...]`
   - **Transient-Cache:** Key `psyern_ah_ph_{item_class}_{period}`, TTL 300 s
   - `invalidate_price_history_cache()` — wird von `add_transactions()` aufgerufen (nur für neu beteiligte `item_class`es)
   - `handle_get_stats()` — GET `/public/stats`
   - `handle_get_price_history()` — GET `/public/price-history?item_class=...&period=...`

**Design-Regeln:**
- Alle `$period`-Werte gegen Whitelist validieren (`24h|7d|30d|all`), sonst 400
- SQL-Injection via prepared statements — **niemals** `$period` direkt in SQL interpolieren, sondern in ein INTERVAL umwandeln
- Zeitzonen-bewusst: alle Timestamps UTC, Darstellung im Frontend lokalisiert

---

#### Agent 6: Balances + Pending-Actions + Upload + User-Write-Endpoints
**Scope:** Schreib-Endpoints (`/user/*`), Internal-Endpoints (`/internal/*`), State-Machine für Aufträge.

**Dateien:**
- `includes/class-psyern-ah-balances.php`
- `includes/class-psyern-ah-pending-actions.php`
- `includes/class-psyern-ah-upload.php`

**Aufgaben:**

1. `Psyern_AH_Balances`:
   - `upsert_balance( $uid, $source, $balance )` — UNIQUE KEY (uid, source)
   - `get_balance( $uid, $source )`
   - `get_all_balances()` — Admin-Viewer

2. `Psyern_AH_Pending_Actions` — **Herzstück der Command-Pipeline**:
   - `enqueue( $type, $player_uid, $listing_id, $amount, $nonce )` → erzeugt Row mit `action_uuid = wp_generate_uuid4()`, status=`queued`. Return: action_uuid
   - `dispatch_batch( $limit )` — Atomarer UPDATE: setzt bis zu `$limit` `queued`-Einträge auf `dispatched` mit `dispatched_at=NOW()`, Return: die aktualisierten Rows. **Wichtig:** SELECT ... FOR UPDATE SKIP LOCKED nicht verfügbar in MyISAM — verwende stattdessen Transaction + SELECT mit `id IN (...)` + UPDATE im selben Schritt.
   - `complete( $action_uuid, $status, $result_code, $result_message )` — setzt `success|failed_*`, `completed_at=NOW()`
   - `get_by_player( $uid )` — für `[psyerns_auctionhouse_my]`
   - `handle_enqueue_purchase( WP_REST_Request )` — POST `/user/purchase`:
     - `check_ajax_referer('psyern-ah-action')` (WP-Nonce)
     - `is_user_logged_in()` + Steam-UID aus User-Meta
     - Rate-Limit: max 10 pro User/Minute (Transient `psyern_ah_rl_{uid}`)
     - Validate Listing exists + is active + has BuyNow + expected_price matches
     - `enqueue('purchase', ...)` → Response mit `action_uuid + status='queued'`
   - `handle_enqueue_bid()` — POST `/user/bid` (gleiche Muster + Mindest-Gebots-Check)
   - `handle_enqueue_cancel()` — POST `/user/cancel` (nur eigene Listings)
   - `handle_dispatch()` — GET `/internal/pending` (Mod ruft auf)
   - `handle_complete()` — PATCH `/internal/pending/{uuid}`

3. `Psyern_AH_Upload`:
   - `handle_upload()` — POST `/internal/upload`:
     - Delegiert an Listings → `full_sync()`, Transactions → `add_transactions()`, Balances → `upsert_balance()`
     - In EINER WP-DB-Transaktion wenn möglich (ansonsten sequenziell)
     - Meta in Transient `psyern_ah_upload_meta` (TTL 600 s): `generated_at`, `currency_mode`, `server_time_epoch`

**Design-Regeln:**
- Jede User-Action produziert einen Audit-Log-Eintrag in `pending_actions`, auch wenn sie fehlschlägt
- `action_uuid` ist immer die Response — Frontend pollt mit dieser UUID den Status
- Nonce-Actions: `psyern-ah-purchase-{listing_id}`, `psyern-ah-bid-{listing_id}`, `psyern-ah-cancel-{listing_id}`
- Rate-Limit-Response: HTTP 429 mit Retry-After-Header

**Quality Gate:** End-to-End-Test (manuell): Web → enqueue purchase → GET /internal/pending → PATCH complete → Status in DB aktualisiert.

---

### PHASE 3 — Frontend (3 parallel, startet nach Phase 2)

#### Agent 7: Shortcodes + Templates
**Scope:** 5 Shortcodes + alle PHP-Templates.

**Dateien:**
- `public/class-psyern-ah-shortcodes.php`
- `public/templates/marketplace.php`
- `public/templates/listing-detail.php`
- `public/templates/listing-card.php`
- `public/templates/my.php`
- `public/templates/history.php`
- `public/templates/stats.php`
- `public/templates/price-chart.php`

**Aufgaben:**

1. `Psyern_AH_Shortcodes`:
   - `register()` — alle 5 Shortcodes registrieren
   - Jede Shortcode-Methode: Attribute validieren, Theme-Klasse bestimmen (via `Psyern_AH_Theme::get_body_class()` aus Agent 9), Template per `include` laden mit Output-Buffering, Result zurückgeben (nie echo!)
   - Shortcodes (Details in README §8):
     - `[psyerns_auctionhouse_marketplace]` — Attribute: `theme`, `per_page`
     - `[psyerns_auctionhouse_listing]` — Attribute: `id` (Pflicht)
     - `[psyerns_auctionhouse_my]` — zeigt nur für eingeloggte User, sonst Login-Button
     - `[psyerns_auctionhouse_history]` — Attribute: `limit`
     - `[psyerns_auctionhouse_stats]` — Tabs (Top-Seller / Popular / Price-Trends)
     - `[psyerns_auctionhouse_price_chart]` — Attribute: `item_class` (Pflicht), `period` (Default 30d), `height` (Default 300)

2. Templates:
   - Alle Ausgaben **escaped** (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` wenn HTML)
   - Item-Icons über Remote-URL aus Item-Map (siehe README §13 #12) — Fallback wenn URL fehlt: `default-item.png`
   - Keine PHP-Logik in Templates — nur Variablen-Ausgabe (Logic ist in Shortcodes-Klasse)
   - Pagination-Links mit `add_query_arg` bauen

**Design-Regeln:**
- BEM-artige CSS-Klassen: `psyern-ah-marketplace`, `psyern-ah-marketplace__filter`, `psyern-ah-marketplace__listing-card`, etc. (Konsistent mit Framework-Plugin)
- Serverseitig initial gerenderte Liste, JS hydriert nur Filter/Pagination

---

#### Agent 8: Frontend JS + Chart.js
**Scope:** AJAX-Filter, Buy/Bid-Buttons, Live-Countdown, Preis-Chart.

**Dateien:**
- `public/js/psyern-ah-marketplace.js`
- `public/js/psyern-ah-listing.js`
- `public/js/psyern-ah-price-chart.js`
- `public/js/psyern-ah-my.js`
- `public/vendor/chart.min.js` — Chart.js v4.x, lokal gebundlet (~80 KB)

**Aufgaben:**

1. `psyern-ah-marketplace.js`:
   - `DOMContentLoaded` → Event-Listener auf Filter-Inputs
   - Debounce 300 ms bei Text-Suche, sofort bei Dropdowns
   - `fetch('/wp-json/psyern-ah/v1/public/listings?...')` → Rendert Card-Grid
   - URL-Params synchronisieren (`history.pushState`) — Bookmarkbare Filter
   - Pagination-Buttons, Skeleton-Loader während Request

2. `psyern-ah-listing.js`:
   - Live-Countdown (Sekunden-Tick, formatiert `Xh Ym Zs`)
   - Buy-Now-Button: Modal „Wirklich kaufen für X€?" → POST /user/purchase (mit Nonce aus `window.psyern_ah.nonces.purchase`) → zeigt Pending-Status → pollt `/user/me` alle 3 s bis Result
   - Bid-Input: Mindestbetrag vorbelegt, Validation, POST /user/bid, gleiche Polling-Logik
   - Fehler-Toasts (eigene kleine Toast-Komponente, kein externes Toast-Lib)

3. `psyern-ah-price-chart.js`:
   - Initialisiert Chart.js mit Line + Band + Bars (Dataset-Konfig siehe README §16 Metriken)
   - Zeitraum-Buttons (24h/7d/30d/all) → Refetch
   - `window.psyernAHRenderPriceChart(container, itemClass, period)` — Public-API für Wiederverwendung

4. `psyern-ah-my.js`:
   - Offene-Aufträge-Panel: pollt `/user/me` alle 5 s
   - Cancel-Buttons für eigene aktive Listings

**Enqueue-Pattern** (Agent 7 oder 10 registriert, aber Agent 8 muss wissen:):
- `wp_register_script`, `wp_enqueue_script` nur auf Seiten, die den Shortcode enthalten (Check in Shortcode-Callback via `wp_enqueue_script` direkt vor Template-Include)
- `wp_localize_script` für `window.psyern_ah`: `{ api_base, nonces, current_user_uid, translations }`
- Chart.js wird **nur** auf Seiten mit Listing-Detail oder Stats oder Price-Chart-Shortcode geladen

**Design-Regeln:**
- Vanilla JS (kein jQuery-Zwang außer `jQuery` ist bereits durch WP verfügbar; bevorzugt native `fetch`)
- Keine Script-Tags im HTML, nur via `wp_enqueue_script`
- ES6+ OK, aber transpilation nicht nötig (WP-Admin unterstützt moderne Browser)

---

#### Agent 9: Frontend CSS + Theme-Integration
**Scope:** Fallback-CSS, Framework-Theme-Detection, Item-Icons-Fallback.

**Dateien:**
- `public/css/psyern-ah-public.css` (Fallback)
- `includes/class-psyern-ah-theme.php` (Detection-Logic)
- `public/assets/img/default-item.png` (Placeholder für fehlende Item-Icons)

**Aufgaben:**

1. `Psyern_AH_Theme`:
   - `is_framework_active()` — prüft ob `Psyerns_Framework`-Plugin aktiv (`is_plugin_active('psyerns-framework/psyerns-framework.php')`)
   - `get_theme_css_url( $theme_slug )` — Wenn Framework aktiv: `plugins_url('public/css/psyern-theme-{slug}.css', PSYERNS_FRAMEWORK_PLUGIN_FILE)`; Fallback: eigene CSS-Datei
   - `enqueue_theme( $theme_slug )` — wird von Shortcodes aufgerufen, enqueued richtige CSS-Datei + `psyern-ah-public.css` als Base
   - `get_body_class( $theme_slug )` — Return: `psyern-theme-stalker psyern-ah-ui`

2. `psyern-ah-public.css`:
   - Base-Layout: Grid für Marketplace, Card-Styles, Modal-Styles, Toast-Styles, Form-Inputs
   - Neutrale Farbpalette (CSS-Variablen mit Defaults)
   - Wenn Framework-Theme geladen ist, überschreibt dessen CSS die Variablen → konsistentes Theming
   - Responsive: Breakpoints 768/1024 px, Mobile-First
   - BEM-Klassen (siehe Agent 7)

**Design-Regeln:**
- Keine Inline-Styles
- Keine IDs für Styling (nur Klassen)
- CSS-Variablen so benennen dass Framework-Themes sie setzen können (`--pf-accent`, `--pf-bg`, etc.) — README §6 „Soft-Dependency"

---

### PHASE 4 — Admin Panel + Mod-Side (2 parallel, nach Phase 1+2 — Agent 11 parallel zu allem)

#### Agent 10: Admin-Panel
**Scope:** WP-Admin-Oberfläche mit allen Viewern + Settings.

**Dateien:**
- `admin/class-psyern-ah-admin.php`
- `admin/css/psyern-ah-admin.css`
- `admin/js/psyern-ah-admin-tabs.js`
- `admin/views/settings-page.php`
- `admin/views/listings-page.php`
- `admin/views/history-page.php`
- `admin/views/balances-page.php`
- `admin/views/pending-page.php`
- `admin/views/tools-page.php`

**Aufgaben:**

1. `Psyern_AH_Admin`:
   - `init()` — Hooks registrieren (admin_menu, admin_init, admin_enqueue_scripts)
   - `add_menu()` — `add_options_page( 'Psyerns AuctionHouse', ... )` unter Settings
   - Tabs: Settings, Listings, History, Balances, Pending, Tools (JS-basiertes Tab-Switching, URL-Param `tab=xyz`)
   - `register_settings()` — via Settings API:
     - `psyern_ah_api_key` (text, mit Regenerate-Button)
     - `psyern_ah_currency_format` (text, Default `{amount} €`)
     - `psyern_ah_item_map` (textarea JSON, validiert)
     - `psyern_ah_push_interval_seconds` (int 10-3600)
     - `psyern_ah_poll_interval_seconds` (int 3-300)
     - `psyern_ah_public_visibility` (array of toggles)
   - Listings/History/Balances-Viewer: `WP_List_Table`-Subklasse mit Sort + Search + Pagination
   - Pending-Page: Log mit Filter (Status, Action-Type, Zeitraum)
   - Tools: „Force Re-Sync"-Button (setzt Transient `psyern_ah_force_resync`, Mod liest beim nächsten Push), „Clear Price-History Cache", „Reset Plugin Data" (mit 2-Stufen-Bestätigung)
   - Admin-Cancel-Button (aus Listings-View) → `Psyern_AH_Pending_Actions::enqueue('admin_cancel', admin-uid, listing_id, 0, '')` (siehe README #14: Items zurück an Seller)

**Design-Regeln:**
- Capability-Check: `manage_options` für alle Admin-Actions
- Nonces auf allen Forms
- Settings-API exakt nach WP-Konvention
- Keine externen Admin-CSS-Frameworks (WP-native Classes: `.wrap`, `.form-table`, `.button`, etc.)

---

#### Agent 11: PF_AH_Sync — Psyerns_Framework Mod-Modul
**Arbeitsverzeichnis:** `C:\Users\Administrator\Desktop\Psyerns_Framework\Psyerns_Framework`

**Scope:** DayZ-EnforceScript-Modul, das aus DME_Auction_House und Expansion-ATM-Daten liest und mit dem WP-Plugin kommuniziert.

**Dateien** (neuer Ordner `scripts/3_Game/Psyerns_Framework/Integrations/AuctionHouse/`):
- `PF_AH_Sync.c` — Module-Orchestrator
- `PF_AH_Uploader.c` — Baut Payload + POST via `PF_WebClient`
- `PF_AH_PendingPoller.c` — GET /internal/pending + Handoff
- `PF_AH_ActionExecutor.c` — Führt Purchase/Bid/Cancel über `DME_AH_AuctionManager` aus
- `PF_AH_BalanceReader.c` — Liest Expansion `$profile:ExpansionMod\ATM\*.json` + DME_AH `PlayerData.json`
- `PF_AH_Config.c` — AuctionHouse-Block in `PsyernsFrameworkConfig.json`
- Update `scripts/config.cpp` um neue Dateien einzubinden

**Aufgaben:**

1. `PF_AH_Config` — JSON-Block `"AuctionHouse"` in bestehender Config:
   ```json
   {
     "Enabled": false,
     "WpUrl": "https://...",
     "ApiKey": "",
     "PushIntervalSeconds": 30,
     "PollIntervalSeconds": 10,
     "CurrencyMode": "Expansion"
   }
   ```

2. `PF_AH_Sync`:
   - `OnMissionStart()` — Lese Config, wenn `Enabled=true`: initialize Timer
   - Zwei Timer via `GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(...)`:
     - Push-Timer (`PushIntervalSeconds`) → `PF_AH_Uploader.Upload()`
     - Poll-Timer (`PollIntervalSeconds`) → `PF_AH_PendingPoller.Poll()`
   - Alle Logs via existierender `PF_Logger`

3. `PF_AH_Uploader`:
   - `Upload()` — liest direkt via `JsonFileLoader<DME_AH_ListingArray>` aus `$profile:DME_AH\Data\ActiveListings.json` (siehe DME_AH_Constants.c)
   - Baut JSON-Payload analog README §7 Upload-Shape
   - Delta-Logik für Transactions: speichert `lastUploadedTransactionTs` in `$profile:Psyerns_Framework\AHState.json`
   - Balances-Collection: via `PF_AH_BalanceReader`
   - Sendet via `PF_WebClient.CreateRequest(WpUrl).SetEndpoint('/wp-json/psyern-ah/v1/internal/upload').SetHeader('Authorization: Bearer ' + ApiKey).SetBody(json).Post()`

4. `PF_AH_PendingPoller`:
   - `Poll()` — GET /internal/pending mit Bearer-Auth
   - Parsed Response, iteriert Actions, delegiert an `PF_AH_ActionExecutor.Execute(action)`
   - Nach Execution: PATCH `/internal/pending/{uuid}` mit Result

5. `PF_AH_ActionExecutor`:
   - `Execute(action)` — Switch auf `action.type`:
     - `purchase` → `DME_AH_AuctionManager.TryBuyNow( listing_id, buyer_uid )` — gibt Result-Code zurück (0=Success, 1=FailedNotEnoughMoney, etc. aus `EDME_AH_ResultCode`)
     - `bid` → `DME_AH_AuctionManager.TryPlaceBid( listing_id, bidder_uid, amount )`
     - `cancel` oder `admin_cancel` → `DME_AH_AuctionManager.TryCancelListing(...)`
   - Mapped Result-Code in Status-String für WP
   - **Wichtig:** Die `DME_AH_AuctionManager`-Methoden existieren aktuell nur für Player-RPCs. Du musst entweder öffentliche Wrapper in der DME_AH Mod hinzufügen ODER die Logik hier reimplementieren (via `DataStore` + `Currency`-Adapter). **Bevorzugt: Wrapper in DME_AH_Module.c hinzufügen**, z.B. `TryBuyNowAsOfflineUser(uid, listing_id)`.

6. `PF_AH_BalanceReader`:
   - `GetAllBalances()` — liest alle JSON-Files aus `$profile:ExpansionMod\ATM\` (via `FindFiles`), baut Array
   - Wenn `CurrencyMode=="Internal"`: stattdessen aus `DME_AH_PlayerData.Balances` Map

**Design-Regeln** (aus `.claude/rules/coding-rules.md`):
- EnforceScript-Pitfalls beachten: keine Zeilenumbrüche in String-Concatenation, keine mehrzeiligen Method-Chains, `ref` nur als Member, #ifdef case-sensitive
- Alle neuen Dateien in `scripts/3_Game/Psyerns_Framework/Integrations/AuctionHouse/` — muss in `scripts/config.cpp` als scriptModule gelistet sein
- Keine Breaking Changes an bestehenden PF-APIs
- Kein Zugriff auf DME_AH-Files, wenn DME_AH nicht geladen ist (guarded via `#ifdef DME_Auction_House` wenn möglich, sonst Runtime-Check)

**Quality Gate:** Start DayZ-Server mit Psyerns_Framework + DME_Auction_House → WP-Admin-Pending-Tab zeigt eingehende Uploads im Log.

---

### PHASE 5 — Finalization (Sequential, nach allem)

#### Agent 12: Packaging + Integration-Review
**Scope:** Dokumentation, i18n, finale Durchsicht, smoke-test.

**Aufgaben:**

1. `readme.txt` (WordPress.org-Format):
   - `=== Psyerns AuctionHouse ===`
   - Header: Contributors, Tags, Requires/Tested/Stable, License
   - Description / Installation / FAQ / Screenshots / Changelog Sections

2. `languages/psyerns-auctionhouse.pot`:
   - Alle `__()`/`_e()`/`esc_html__()`-Strings extrahieren (per WP-CLI `wp i18n make-pot . languages/psyerns-auctionhouse.pot`)

3. **Integration-Review:**
   - Prüfe: Alle 17+ REST-Routes haben echte Implementierungen (keine 501-Stubs mehr)
   - Prüfe: Shortcode auf Testseite rendert ohne Fehler (leere DB = leere States sichtbar, kein Crash)
   - Prüfe: Plugin kann sauber deaktiviert + wieder aktiviert werden ohne Daten-Loss
   - Prüfe: Uninstall-Test (in separater WP-Instanz) löscht alle Tabellen und Options
   - Prüfe: Kein WP_DEBUG-Notice, keine PHP-Warnings bei aktivem `WP_DEBUG=true`

4. **Finaler Commit-Vorschlag:** 1 Commit pro Agent-Output (nicht alles in einem), dann PR-Beschreibung generieren (falls gewünscht).

---

## 6. Cross-Agent Dependencies

```
Phase 1 (parallel):
  Agent 1  — Database
  Agent 2  — Auth (API-Key + Steam)
  Agent 3  — Bootstrap + REST Registry (Stubs)
                │
                │ alle fertig?
                ▼
Phase 2 (parallel, depend on Phase 1):
  Agent 4  — Listings + Public GET
  Agent 5  — Transactions + Stats + PriceHistory
  Agent 6  — Balances + Pending + Upload + User-Writes
                │
                │ alle fertig?
                ▼
Phase 3 (parallel, depend on Phase 2):
  Agent 7  — Shortcodes + Templates
  Agent 8  — Frontend JS + Chart.js
  Agent 9  — Frontend CSS + Theme

Phase 4 (parallel):
  Agent 10 — Admin-Panel (depends on Phase 2)
  Agent 11 — PF_AH_Sync Mod-Modul (INDEPENDENT — kann ab Phase 1 starten, parallel zu allem)

Phase 5 (sequential, after all):
  Agent 12 — Packaging + Integration-Review
```

---

## 7. Naming-Conventions (Pflicht)

| Artefakt | Pattern | Beispiel |
|---|---|---|
| PHP-Klasse | `Psyern_AH_PascalSnake` | `Psyern_AH_Listings` |
| PHP-Datei | `class-psyern-ah-kebab.php` | `class-psyern-ah-listings.php` |
| PHP-Funktion (außerhalb Klassen) | `psyern_ah_snake_case` | `psyern_ah_activate` |
| CSS-Klasse | `psyern-ah-{block}__{element}--{modifier}` | `psyern-ah-listing-card__price--highlighted` |
| JS-Global | `window.psyern_ah` + Methoden `psyernAHCamelCase` | `window.psyernAHRenderPriceChart` |
| REST-Route | `psyern-ah/v1/{resource}/{sub}` | `psyern-ah/v1/public/listings` |
| DB-Tabelle | `{wp_prefix}psyern_ah_{resource}` | `wp_psyern_ah_listings` |
| Option | `psyern_ah_{snake}` | `psyern_ah_api_key` |
| Nonce-Action | `psyern-ah-{verb}` | `psyern-ah-purchase` |
| Hook | `psyerns_ah/{event}` | `psyerns_ah/before_upsert_listing` |
| Transient | `psyern_ah_{purpose}_{key}` | `psyern_ah_ph_M4A1_30d` |

---

## 8. Quality Gates (alle Agenten)

Bevor ein Agent als fertig gilt:

- [ ] `ABSPATH`-Check oben in jeder PHP-Datei
- [ ] Keine direkten SQL-Queries (immer via `$wpdb->prepare()`)
- [ ] Sanitizing aller Input (User/REST), Escaping aller Output (Templates)
- [ ] Nonces auf allen Formular-/AJAX-Actions
- [ ] Capability-Checks vor Admin-Aktionen
- [ ] Alle Strings i18n-ready (`__()` / `esc_html__()` etc. mit Text-Domain `psyerns-auctionhouse`)
- [ ] Keine `echo` außerhalb von Templates
- [ ] Keine Emojis, keine überflüssigen Kommentare (WORKS-Prinzip: Code > Kommentar)
- [ ] WordPress Coding Standards (Tabs für Einrückung, Yoda-Conditions, etc.)
- [ ] Keine deprecated WP-Funktionen
- [ ] Bei JS: keine `jQuery`-Abhängigkeit außer explizit begründet
- [ ] Konsistentes Naming (siehe §7)

---

## 9. Ausführungs-Strategie

```
# Der Orchestrator führt aus:

Schritt 1: Lies README.md + CLAUDE.md + wordpress-plugin.md  (Kontext)
Schritt 2: Starte Agent 1, 2, 3 parallel (Phase 1)
Schritt 3: Starte Agent 11 im Hintergrund (Phase 4-Mod, unabhängig)
Schritt 4: Warte auf Phase 1 Completion
Schritt 5: Starte Agent 4, 5, 6 parallel (Phase 2)
Schritt 6: Warte auf Phase 2 Completion
Schritt 7: Starte Agent 7, 8, 9, 10 parallel (Phase 3 + 4-Admin)
Schritt 8: Warte auf Phase 3 + 4 Completion
Schritt 9: Starte Agent 12 (Finalization)
Schritt 10: Integration-Smoketest + Commit-Plan
```

**Parallele Agent-Dispatches** MÜSSEN in einer einzigen Message mit mehreren `Agent`-Tool-Calls gesendet werden (nicht hintereinander), um maximalen Durchsatz zu erreichen.

**Jeder Agent-Aufruf** enthält:
- Vollständige Scope-Beschreibung (von hier)
- Pfad zur README.md (als Spec-Referenz)
- Pfad zu den CLAUDE.md-Regeln
- Explizite Liste der zu erstellenden Dateien

---

## 10. Integration-Test-Plan (Post-Agent-12)

1. WordPress lokal (WP Studio oder LocalWP) auf PHP 8.1 mit aktuellem WP
2. Plugin installieren + aktivieren → keine Fatals, alle Tabellen angelegt
3. Admin → Settings → API-Key generieren
4. Test-Upload via curl:
   ```bash
   curl -X POST http://localhost/wp-json/psyern-ah/v1/internal/upload \
     -H "Authorization: Bearer <KEY>" \
     -H "Content-Type: application/json" \
     -d @test-payload.json
   ```
5. Seite anlegen mit `[psyerns_auctionhouse_marketplace]` → Listings sichtbar
6. Steam-Login testen → User eingeloggt, Mapping in `users`-Tabelle
7. Listing-Detail-Seite → Buy-Now-Button → Pending-Action in DB, Admin-Pending-Tab zeigt Eintrag
8. Simuliertes Mod-GET `/internal/pending` → Action dispatched, PATCH mit `status=success` → UI zeigt „erfolgreich"
9. Shortcode `[psyerns_auctionhouse_price_chart item_class="M4A1"]` → Chart rendert (auch bei leerer Historie = Leer-State)

---

## 11. Absolute Restriktionen (siehe CLAUDE.md)

- Kein Refactoring außerhalb Scope
- Keine neuen Features jenseits README §3 + §13
- Keine externen Libraries außer Chart.js (bereits autorisiert)
- Keine Dateien in Core-WP oder in `DME_Auction_House` ändern, **außer** Agent 11 darf Public-Wrapper in `DME_AH_Module.c` hinzufügen (siehe dort)
- Nie Mocks für echte DB-Operationen in Produktions-Code

---

**Orchestrator-Agent: Starte mit Schritt 1 aus §9. Geh systematisch vor. Frag nach wenn Entscheidungen unklar — die README.md ist deine Spec, nicht dein Vorwissen.**
