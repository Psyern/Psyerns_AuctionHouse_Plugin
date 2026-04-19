# Psyerns AuctionHouse — WordPress Plugin

Brücke zwischen der DayZ-Mod **DME_Auction_House** und einer WordPress-Seite. Spieler sehen aktive Auktionen, Historie und Statistiken im Web und können eingeloggt (Steam) **direkt vom Browser aus kaufen und bieten**.

> **Status:** Design-Phase. Dieses Dokument ist die lebende Spec während der Planung. Sobald die Spec freigegeben ist, wird daraus ein Implementierungs-Plan in `docs/superpowers/specs/`.

---

## 1. Projekt-Identität

| Feld | Wert |
|---|---|
| Plugin-Name | Psyerns AuctionHouse |
| Plugin-Slug | `psyerns-auctionhouse` |
| Text-Domain | `psyerns-auctionhouse` |
| REST-Namespace | `psyern-ah/v1` |
| Klassen-Prefix | `Psyern_AH_` |
| DB-Tabellen-Prefix | `{wp_prefix}psyern_ah_` |
| Autor | Psyern / Deadmans Echo |
| Lizenz | MIT |
| WP min | 5.8 |
| PHP min | 7.4 |
| Pfad | `C:\Users\Administrator\Desktop\Psyerns_Framework\WP-Plugin_Psyerns_AuctionHouse` |

---

## 2. Datenquellen (DayZ-Server-Seite)

### DME_Auction_House Mod
Pfad: `C:\Users\Administrator\Desktop\DME_Auction_House`

JSON-Storage (auf Server): `$profile:DME_AH\Data\`

| Datei | Inhalt | Klasse |
|---|---|---|
| `ActiveListings.json` | Alle aktiven Listings | `DME_AH_ListingArray` → `array<DME_AH_Listing>` |
| `CompletedListings.json` | Transaktions-Historie | `DME_AH_TransactionArray` → `array<DME_AH_Transaction>` |
| `PlayerData.json` | Internal Balances + Pending Pickups | `DME_AH_PlayerData` |

Config: `$profile:DME_AH\Config\Settings.json`, `Categories.json`, `NPCs.json`

### Listing-Datenstruktur (Auszug)
```
DME_AH_Listing {
    string  ListingID;                 // z.B. "1712233412_84592"
    string  SellerUID, SellerName;
    string  ItemClassName, ItemDisplayName;
    int     CategoryID;
    int     ListingType;               // 0=BuyNow, 1=Auction, 2=AuctionWithBuyNow
    int     StartPrice, BuyNowPrice;
    int     CurrentBid, BidCount;
    string  CurrentBidderUID, CurrentBidderName;
    int     CreatedTimestamp;
    int     ExpiresTimestamp;
    int     Status;                    // 0=Active, 1=Sold, 2=Expired, 3=Cancelled
}
```

### Expansion-ATM (für Internal Currency Mirror, optional)
Pfad: `$profile:ExpansionMod\ATM\{PlayerUID}.json`
```
ExpansionMarketATM_Data { string PlayerID; int MoneyDeposited; }
```

### Currency-Modi der Mod
- `Expansion` — Expansion-Wallet-Integration
- `Item` — physisches Geld-Item im Inventar (z.B. `MoneyRuble100`)
- `Internal` — Mod-eigene Balance in `PlayerData.json` (die einzige, die für Web-Käufe ohne laufenden DayZ-Client funktioniert)

Web-Käufe/Gebote funktionieren mit **Expansion** (ATM-Balance wird gelesen) und **Internal**. **Nicht** mit `Item`.

---

## 3. Entscheidungen aus dem Brainstorming

| # | Frage | Entscheidung |
|---|---|---|
| 1 | Datentransport Mod → WP | **A** — HTTP-Push via Psyerns_Framework |
| 2 | Scope / Phasierung | **A** — View + BuyNow + Bidding komplett in einem Rutsch |
| 3 | Steam-Login | **B** — Steam OpenID im Plugin selbst implementieren |
| 4 | Mod-Code-Organisation | **C** — Als Modul im `Psyerns_Framework` (`PF_AH_Sync`) |
| 5 | Naming | **C** — Klassen `Psyern_AH_*`, REST `psyern-ah/v1`, DB `psyern_ah_*` |
| 6 | Theming | **B** — Framework-Plugin als Soft-Dependency, dessen Themes wiederverwenden, Fallback-CSS im Plugin |
| 7 | Shortcodes | **B** — 5 Einzel-Shortcodes (marketplace, listing, my, history, stats) |
| 8 | Filter/Sort | **B** — Standard (Kategorie, Typ, Preis-Range, Suche + Sort + Pagination 20/S.) |
| 9 | Admin-Panel | **B** — Standard (API-Key, Listings-Viewer, History, Balance-Viewer, Pending-Actions-Log, Settings) |
| 10 | Public Visibility | **A** — Alles öffentlich sichtbar (Kaufen/Bieten nur mit Login) |

---

## 4. Architektur & Datenfluss

```
┌──────────────────────────────────────────────┐
│  DayZ Server                                 │
│  ┌─────────────────────┐  ┌────────────────┐ │
│  │ DME_Auction_House   │  │ Expansion      │ │
│  │ $profile:DME_AH\    │  │ $profile:      │ │
│  │   ActiveListings    │  │   ExpansionMod\│ │
│  │   CompletedListings │  │   ATM\{UID}.json│ │
│  └────────┬────────────┘  └────────┬────────┘ │
│           └──────┬─────────────────┘          │
│                  ▼                            │
│  ┌──────────────────────────────────────────┐ │
│  │ Psyerns_Framework / Integrations / AH   │ │
│  │ PF_AH_Sync                               │ │
│  │  • Timer 30s: PUSH /upload               │ │
│  │  • Timer 10s: GET /internal/pending      │ │
│  │  • on action: execute via DME_AH_Module  │ │
│  │  • on result: PATCH /internal/pending/ID │ │
│  └──────────────────┬───────────────────────┘ │
└─────────────────────┼─────────────────────────┘
                      │  HTTPS + API-Key Header
                      ▼
┌──────────────────────────────────────────────┐
│  WordPress Server                            │
│  Plugin: psyerns-auctionhouse                │
│   REST psyern-ah/v1                          │
│   DB: psyern_ah_listings, _transactions,     │
│       _balances, _pending_actions, _users    │
│   Shortcodes: 5                              │
└──────────────────────────────────────────────┘
                      ▲
                      │ Browser
              ┌───────┴───────┐
              │ Spieler / Web │
              └───────────────┘
```

### Kernprinzipien
1. **Mod = Single Source of Truth** für Balances und Listing-Status. Website ist stets Mirror.
2. **Zwei Transport-Richtungen**:
   - **Push** (Mod → WP, 30 s): Listings, Transaktionen, Balances hochladen
   - **Poll** (Mod → WP, 10 s): offene Web-Aufträge abholen, ausführen, Ergebnis zurückmelden
3. **Asynchrones Command-Pattern**: Web-Käufe/Gebote sind keine synchronen Calls sondern Aufträge mit State-Machine:
   ```
   queued → dispatched → executing → (success | failed_{reason})
   ```
4. **Atomare Balance-Reservierung**: Beim Platzieren eines Gebots wird die Summe mod-seitig reserviert (abgezogen + als `reserved` markiert). Bei Outbid: zurück. Bei Auktions-Gewinn: endgültig abgezogen. Verhindert Doppel-Spending.

### Trade-offs
- Web-Aktion zeigt Ergebnis nach nächstem Mod-Poll (max. 10 s, Schnitt 5 s) — für AH OK.
- DayZ-Server offline → Website read-only, neue Aufträge bleiben `queued` bis Server zurück ist.

---

## 5. Komponenten-Überblick

### WordPress-Plugin (dieses Verzeichnis)
- **Datenbank-Layer** (`class-psyern-ah-database.php`) — 5 Tabellen
- **REST-API** (`class-psyern-ah-api.php`) — 3 Bereiche: `/public/*`, `/user/*` (auth), `/internal/*` (API-Key)
- **Steam OpenID** (`class-psyern-ah-steam-auth.php`) — Redirect, Callback, UID ↔ WP-User-Mapping
- **Auth** (`class-psyern-ah-auth.php`) — API-Key Validation für Mod-Endpoints
- **Data-Services** (`class-psyern-ah-listings.php`, `-transactions.php`, `-balances.php`, `-pending-actions.php`)
- **Shortcodes** (`class-psyern-ah-shortcodes.php`) — 5 Shortcodes
- **Frontend Assets** — JS für Filter/AJAX + Fallback-CSS, nutzt Framework-Themes via Soft-Dependency
- **Admin** (`class-psyern-ah-admin.php`) — Settings-Page + Viewer-Tabs

### DayZ-Mod (im `Psyerns_Framework`)
Neuer Ordner: `scripts/3_Game/Psyerns_Framework/Integrations/AuctionHouse/`
- `PF_AH_Sync.c` — Main orchestrator, Timer-Logik
- `PF_AH_Uploader.c` — Builds Payloads, POST via `PF_WebClient`
- `PF_AH_PendingPoller.c` — GET /internal/pending, Handoff an Executor
- `PF_AH_ActionExecutor.c` — Führt Purchase/Bid/Cancel via `DME_AH_AuctionManager` aus, reportet Ergebnis
- `PF_AH_BalanceReader.c` — Liest Expansion ATM-Files / DME_AH PlayerData
- `PF_AH_Config.c` — WP-URL + API-Key + Intervalle (in `PsyernsFrameworkConfig.json` als Block `AuctionHouse`)

---

## 6. Datenbank-Schema (Planung)

Tabellen (alle mit Prefix `{wp_prefix}psyern_ah_`):

### `listings` — Mirror aktiver Listings
```sql
id               BIGINT UNSIGNED PK AUTO_INCREMENT
listing_id       VARCHAR(64) UNIQUE KEY         -- mod-seitig generiert
seller_uid       VARCHAR(32)     INDEX
seller_name      VARCHAR(128)
item_class       VARCHAR(128)    INDEX
item_display     VARCHAR(255)
category_id      SMALLINT        INDEX
listing_type     TINYINT          -- 0/1/2
start_price      BIGINT
buy_now_price    BIGINT
current_bid      BIGINT
current_bidder_uid   VARCHAR(32)
current_bidder_name  VARCHAR(128)
bid_count        INT
created_ts       BIGINT
expires_ts       BIGINT           INDEX
status           TINYINT          INDEX  -- 0 Active
last_sync        DATETIME
```

### `transactions` — abgeschlossene Käufe
```sql
id               BIGINT UNSIGNED PK
transaction_id   VARCHAR(64) UNIQUE
listing_id       VARCHAR(64)     INDEX
seller_uid, seller_name, buyer_uid, buyer_name
item_class, item_display
final_price      BIGINT
fee              BIGINT
type             TINYINT          -- 0 BuyNow, 1 AuctionWon, 2 Expired, 3 Cancelled
timestamp        BIGINT           INDEX
```

### `balances` — Balance-Mirror pro Spieler + Currency-Source
```sql
id               BIGINT UNSIGNED PK
player_uid       VARCHAR(32)      INDEX
currency_source  VARCHAR(16)      -- "Expansion" | "Internal"
balance          BIGINT
updated_at       DATETIME
UNIQUE KEY (player_uid, currency_source)
```

### `pending_actions` — Web-Aufträge (Purchase/Bid/Cancel)
```sql
id               BIGINT UNSIGNED PK
action_uuid      VARCHAR(36) UNIQUE      -- für Idempotenz
action_type      VARCHAR(16)             -- "purchase" | "bid" | "cancel"
player_uid       VARCHAR(32)    INDEX
listing_id       VARCHAR(64)    INDEX
amount           BIGINT                  -- bei bid: Gebot; bei purchase: BuyNowPrice-Snapshot
nonce            VARCHAR(64)
status           VARCHAR(16)    INDEX    -- queued/dispatched/executing/success/failed_*
result_code      VARCHAR(32)
result_message   TEXT
created_at       DATETIME
dispatched_at    DATETIME
completed_at     DATETIME
```

### `users` — Mapping WordPress-User ↔ Steam-UID
```sql
id               BIGINT UNSIGNED PK
wp_user_id       BIGINT UNSIGNED UNIQUE
steam_uid        VARCHAR(32) UNIQUE
steam_name       VARCHAR(128)
avatar_url       VARCHAR(512)
linked_at        DATETIME
last_login       DATETIME
```

---

## 7. REST-API (Planung)

| Method | Route | Auth | Zweck |
|---|---|---|---|
| GET  | `/public/listings` | none | Marketplace (Paginierung, Filter, Sort) |
| GET  | `/public/listings/{id}` | none | Detail |
| GET  | `/public/history` | none | Letzte Transaktionen |
| GET  | `/public/stats` | none | Top-Seller, beliebteste Items, Ø-Preise |
| GET  | `/public/price-history` | none | Preis-Zeitreihe pro `item_class` (Query: `item_class`, `period=24h\|7d\|30d\|all`) |
| GET  | `/public/categories` | none | Kategorien-Liste |
| GET  | `/auth/steam/login` | none | Redirect zu Steam OpenID |
| GET  | `/auth/steam/callback` | none | OpenID Callback, WP-Login setzen |
| POST | `/auth/logout` | session | Logout |
| GET  | `/user/me` | session | Eigene Info (UID, Balance-Mirror, offene Aufträge) |
| GET  | `/user/listings` | session | Eigene Listings |
| GET  | `/user/bids` | session | Eigene Gebote |
| POST | `/user/purchase` | session + nonce | BuyNow-Auftrag anlegen |
| POST | `/user/bid` | session + nonce | Gebot-Auftrag anlegen |
| POST | `/user/cancel` | session + nonce | Eigenes Listing canceln |
| POST | `/internal/upload` | API-Key | Mod lädt Listings/Transaktionen/Balances hoch |
| GET  | `/internal/pending` | API-Key | Mod holt offene Aufträge (mit Dispatch-Lock) |
| PATCH| `/internal/pending/{uuid}` | API-Key | Mod meldet Ergebnis zurück |
| GET  | `/internal/ping` | API-Key | Health-Check |

### Payload-Beispiele

**POST `/internal/upload`** (Mod → WP):
```json
{
  "generatedAt": "2026-04-20T14:00:00Z",
  "serverTimeEpoch": 1713621600,
  "currencyMode": "Expansion",
  "listings": [ { /* DME_AH_Listing Shape */ } ],
  "recentTransactions": [ { /* DME_AH_Transaction Shape, nur neue seit letzter Sync */ } ],
  "balances": [ { "uid": "...", "source": "Expansion", "balance": 12340 } ]
}
```

**POST `/user/purchase`** (Web → WP):
```json
{
  "nonce": "wp-nonce-token",
  "listing_id": "1712233412_84592",
  "expected_price": 500
}
```
→ `{ "action_uuid": "...", "status": "queued" }`

**GET `/internal/pending`** (Mod → WP) — liefert bis zu N offene Aufträge und setzt sie atomar auf `dispatched`:
```json
{
  "actions": [
    { "action_uuid": "...", "type": "purchase", "player_uid": "...",
      "listing_id": "...", "amount": 500, "created_at": "..." }
  ]
}
```

---

## 8. Shortcodes (Frontend)

| Shortcode | Zweck |
|---|---|
| `[psyerns_auctionhouse_marketplace theme="stalker" per_page="20"]` | Marketplace mit Filter, Sort, Pagination |
| `[psyerns_auctionhouse_listing id="..."]` | Einzel-Detail, Buy/Bid-Buttons, Gebots-Historie |
| `[psyerns_auctionhouse_my]` | Eingeloggter User: Balance, Listings, Gebote, offene Aufträge |
| `[psyerns_auctionhouse_history limit="50"]` | Letzte verkaufte Items |
| `[psyerns_auctionhouse_stats]` | Top-Seller, beliebteste Items, Ø-Preise, **Tab „Preis-Trends" mit Item-Dropdown + Chart** |
| `[psyerns_auctionhouse_price_chart item_class="..." period="30d" height="300"]` | Standalone Preis-Chart für ein `item_class`. Parameter: `item_class` (Pflicht), `period=24h\|7d\|30d\|all` (Default `30d`), `height` in px |

**Marketplace-Filter** (via AJAX ohne Page-Reload):
- Kategorie (Dropdown aus `/public/categories`)
- Listing-Typ (Radio: Alle / BuyNow / Auktion)
- Preis-Range (Min/Max Inputs)
- Suche (Item-Name, Debounce 300 ms)
- Sortierung (Dropdown: Preis ↑↓, Restzeit ↑↓, Neueste, Meiste Gebote)
- Pagination (20 pro Seite)

---

## 9. Admin-Panel

Menü: **Einstellungen → Psyerns AuctionHouse** (Tab-Interface analog bestehendes Framework-Plugin).

| Tab | Inhalt |
|---|---|
| Settings | API-Key generieren/rotieren, Polling-Intervalle, Currency-Format (z.B. `{amount} €`), Public-Visibility Toggles |
| Listings | Tabelle aller aktiven Listings (Sort, Search), Admin-Cancel-Button → erzeugt `admin_cancel` Pending-Action |
| History | Transaktionen mit Filter (Datum, Spieler, Item) |
| Balances | Read-only Balance-Mirror aller Spieler |
| Pending | Log offener/fertiger Web-Aufträge mit Statuscode + Fehlermeldung |
| Tools | „Force Re-Sync anfordern" (setzt Flag, nächster Push ist Full-Sync), Tabellen-Reset (gefährlich, mit Bestätigung) |

---

## 10. Sicherheits-Prinzipien

- **API-Key** (Bearer) für alle `/internal/*` Routen — zufällig generiert, im Admin neu setzbar
- **WP-Nonces** auf allen `/user/*` POST-Routen (CSRF)
- **Rate-Limit** pro Session auf `/user/purchase` und `/user/bid` (z.B. max. 10 pro Minute)
- **Idempotenz** via `action_uuid` — Mod kann denselben Auftrag sicher mehrfach abfragen ohne doppelte Ausführung
- **Expected-Price-Check**: Web sendet erwarteten Preis mit, Mod validiert; wenn Listing in der Zwischenzeit geändert wurde → `failed_price_mismatch`
- **Balance-Validation** server-seitig (in Mod) — Client sieht nur Mirror, kann nicht manipulieren
- **Double-Spend-Protection** durch mod-seitige Reservierung beim Bid/Purchase
- **Steam OpenID Signatur-Verifikation** auf Callback (kein Fake-UID-Inject)

---

## 11. Geplante Verzeichnis-Struktur

```
WP-Plugin_Psyerns_AuctionHouse/
├── psyerns-auctionhouse.php              # Bootstrap, Plugin-Header, Hooks
├── uninstall.php                          # Tabellen + Options droppen
├── README.md                              # ← dieses Dokument
├── readme.txt                             # WordPress.org-Format
├── includes/
│   ├── class-psyern-ah-database.php       # Tabellen-Setup via dbDelta
│   ├── class-psyern-ah-auth.php           # API-Key Validation
│   ├── class-psyern-ah-steam-auth.php     # OpenID Login
│   ├── class-psyern-ah-api.php            # REST-Route-Registry
│   ├── class-psyern-ah-listings.php       # CRUD + Filter Listings
│   ├── class-psyern-ah-transactions.php   # CRUD + History
│   ├── class-psyern-ah-balances.php       # Balance-Mirror
│   ├── class-psyern-ah-pending-actions.php# Auftrags-State-Machine
│   ├── class-psyern-ah-stats.php          # Aggregations-Queries (inkl. Preis-Zeitreihen)
│   └── class-psyern-ah-theme.php          # Framework-Theme Detection/Fallback
├── admin/
│   ├── class-psyern-ah-admin.php          # Menü + Settings API
│   ├── css/psyern-ah-admin.css
│   ├── js/psyern-ah-admin-tabs.js
│   └── views/
│       ├── settings-page.php
│       ├── listings-page.php
│       ├── history-page.php
│       ├── balances-page.php
│       └── pending-page.php
├── public/
│   ├── class-psyern-ah-shortcodes.php
│   ├── css/psyern-ah-public.css           # Fallback wenn Framework nicht aktiv
│   ├── js/psyern-ah-marketplace.js        # AJAX-Filter, Sort, Pagination
│   ├── js/psyern-ah-listing.js            # Buy/Bid-Buttons, Live-Countdown
│   ├── js/psyern-ah-price-chart.js        # Chart.js-Wrapper für Preis-Historie
│   ├── vendor/chart.min.js                # Chart.js (lokal, keine CDN-Dependency)
│   └── templates/
│       ├── marketplace.php
│       ├── listing-detail.php
│       ├── listing-card.php
│       ├── my.php
│       ├── history.php
│       ├── stats.php
│       └── price-chart.php
└── languages/
    └── psyerns-auctionhouse.pot
```

---

## 12. Polling-Intervalle (Default, admin-konfigurierbar)

| Direction | Intervall | Inhalt |
|---|---|---|
| Mod → WP (Push) | 30 s | Full-Upload (Listings, Transaktionen delta, Balances) |
| Mod → WP (Poll) | 10 s | Offene Pending-Actions abholen |
| Web → WP (Browser-Refresh) | 15 s | Auto-Refresh Marketplace / offene eigene Aufträge via fetch |

---

## 13. Zusatz-Entscheidungen (aus Klärungsrunde)

| # | Frage | Entscheidung |
|---|---|---|
| 11 | Outbid-Benachrichtigung | **Nein** — keine E-Mails. Nur in-site Badge/Liste unter `[psyerns_auctionhouse_my]` (Spalte „Status" zeigt „Überboten" / „Führend" / „Gewonnen"). |
| 12 | Item-Icons | **Remote-URLs** — aus einer Item-Map-JSON auf dem WordPress (admin-pflegbar). Keine Asset-Kopien im Plugin. |
| 13 | Statistik-Zeiträume | **Alle: 24h + 7d + 30d + all-time** — Tab-Umschaltung auf `[psyerns_auctionhouse_stats]`. |
| 14 | Admin-Cancel | **Items zurück an Seller** — `admin_cancel` Pending-Action führt mod-seitig zu Pending-Pickup für den Seller. |
| 15 | Sync-Modus | **Full-Sync** — Mod sendet bei jedem Push alle aktiven Listings. WP upsert-ersetzt. Transaktionen nur delta (seit `last_transaction_ts`). |
| 16 | Preis-Graph | **C** — auf Listing-Detail automatisch + Stats-Tab „Preis-Trends" mit Item-Dropdown + freier Shortcode `[psyerns_auctionhouse_price_chart]`. Rendering via Chart.js. Zeiträume: 24h/7d/30d/all. Metriken: Ø-Preis (Linie) + Min/Max (Band) + Verkaufsanzahl (Bars). |

## 14. Offene Technik-Details (können im Plan gelöst werden)

- [ ] Paket-Größe des Pushes bei sehr vielen Listings (>500) — ggf. Pagination im Upload oder `gzip`-Request-Body.
- [ ] Item-Map-JSON Struktur (`{ "item_class": { "icon_url": "...", "rarity": "..." } }`) — finales Schema im Implementierungs-Plan.

---

## 15. Nächste Schritte

1. ✅ Brainstorming abgeschlossen
2. ⏳ Spec-Review mit User
3. ⏳ Design-Doc in `docs/superpowers/specs/2026-04-20-psyerns-auctionhouse-design.md` schreiben + committen
4. ⏳ Übergabe an `writing-plans`-Skill für Implementierungs-Plan
5. ⏳ Umsetzung: WP-Plugin + `PF_AH_Sync` im Framework
