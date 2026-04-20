=== Psyerns AuctionHouse ===
Contributors: psyern
Tags: dayz, auction, marketplace, gaming, webhook, steam, rest-api
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Brücke zwischen der DayZ-Mod DME_Auction_House und WordPress. Marketplace, Preis-Charts, Steam-Login, Kaufen und Bieten aus dem Browser.

== Description ==

Psyerns AuctionHouse verbindet das DayZ-Auktionshaus (Mod: DME_Auction_House) mit einer WordPress-Seite. Spieler sehen alle aktiven Listings, Transaktionshistorie und Statistiken im Web und können mit Steam-Login direkt aus dem Browser Sofort-Käufe tätigen und Auktionen bieten.

= Architektur (Kurz) =

* Der DayZ-Server pusht alle 30 Sekunden Listings, Transaktionen und Balances in die REST-API des Plugins.
* Offene Web-Aufträge (Kauf, Gebot, Cancel) werden in einer Pending-Queue gehalten und alle 10 Sekunden vom Server abgeholt, ausgeführt und das Ergebnis zurückgemeldet.
* Doppel-Spending wird durch mod-seitige Balance-Reservierung verhindert.
* Website ist reiner Mirror — einzige Quelle der Wahrheit bleibt der DayZ-Server.

= Features =

* 17 REST-Routen in den Bereichen /public/*, /user/* (Session), /internal/* (API-Key)
* 6 Shortcodes: Marketplace, Listing-Detail, Mein Bereich, History, Stats, Preis-Chart
* Admin-Panel mit 6 Tabs: Settings, Listings, History, Balances, Pending, Tools
* Steam OpenID 2.0 Login (keine eigene Registrierung nötig)
* Preis-Zeitreihen mit Chart.js (24h, 7d, 30d, all-time)
* Themes: Framework-Themes via Soft-Dependency oder Fallback-CSS
* Item-Icon-Map im Admin konfigurierbar (Remote-URLs, Rarity-Tagging)
* CSRF-Schutz per WP-Nonce auf allen Write-Routen
* Rate-Limiting pro User pro Aktionstyp (10/min)
* Idempotenz über action_uuid fuer die Mod-Polling-Phase

= Shortcodes =

* [psyerns_auctionhouse_marketplace theme="stalker" per_page="20"]
* [psyerns_auctionhouse_listing id="..."]
* [psyerns_auctionhouse_my]
* [psyerns_auctionhouse_history limit="50"]
* [psyerns_auctionhouse_stats]
* [psyerns_auctionhouse_price_chart item_class="M4A1_AssaultRifle" period="30d" height="300"]

= Integrations-Punkte =

* DME_Auction_House Mod (DayZ Server-Mod)
* Psyerns_Framework Mod (PF_AH_Sync Modul, HTTP-Transport)
* Expansion ATM (optional, fuer Balance-Mirror)

Siehe KNOWN_ISSUES.md fuer dokumentierte v1-Limits und DEPLOY_SMOKETEST.md fuer eine 10-Schritt-Deployment-Checkliste.

== Installation ==

1. Upload des Ordners `psyerns-auctionhouse` in `/wp-content/plugins/`.
2. Aktivierung ueber das WordPress Admin-Menue `Plugins`.
3. Navigation zu `AuctionHouse` -> `Settings`; API-Key generieren und fuer die PF_AH_Sync-Mod-Konfiguration kopieren.
4. Optional: Steam Web-API-Key hinterlegen (fuer Avatar/Display-Name-Cache).
5. Optional: Default-Theme waehlen, Item-Map JSON einpflegen, Listing-Detail-Seiten-URL setzen.
6. In der DayZ-Mod PF_AH_Sync (im Psyerns_Framework) die WP-URL und den API-Key eintragen.
7. Eine WordPress-Seite mit `[psyerns_auctionhouse_marketplace]` anlegen und testen.

== Frequently Asked Questions ==

= Wo wird der API-Key gespeichert und wie rotiere ich ihn? =

Der API-Key liegt als WordPress-Option `psyern_ah_api_key`. Rotation: `AuctionHouse` -> `Settings` -> Button `Rotate`. Nach der Rotation muss der neue Key sofort in der PF_AH_Sync-Mod-Konfiguration eingetragen werden, sonst schlagen Uploads fehl (401).

= Ist Steam-Login Pflicht? =

Nur fuer Kaufen, Bieten und Cancel. Marketplace, History und Stats sind komplett oeffentlich (kein Login noetig). Die Steam-UID wird automatisch an den WP-User gebunden; jeder Spieler kann sich selbst verlinken.

= Funktioniert es mit Expansion-ATM? =

Ja. Der CurrencyMode im DME_AH-Config bestimmt, welche Balance die Mod mirror-ed: `Expansion` (ATM-Wallet) oder `Internal` (mod-eigene Balance). `Item` (physisches Geld-Item) wird NICHT unterstuetzt, weil Web-Kaeufe ohne laufenden DayZ-Client kein Geld aus dem Inventar entnehmen koennen.

= Gibt es Rate-Limits? =

Ja. Pro Session/User max. 10 Anfragen pro 60 Sekunden pro Aktionstyp (purchase, bid, cancel). Ueberschreitung liefert HTTP 429 mit `Retry-After`-Header. Admin-Tools sind nicht rate-limited.

= Wann werden Caches invalidiert? =

Stats- und Preis-Historie-Responses werden 5 Minuten per Transient gecacht. Nach einem erfolgreichen Transactions-Upload wird der Preis-Historie-Cache pro betroffenem item_class automatisch invalidiert. Clear-Caches im `Tools`-Tab leert alles manuell.

= Was passiert, wenn der DayZ-Server offline ist? =

Die Website wird effektiv read-only. Neue Web-Auftraege bleiben in der Pending-Queue mit Status `queued` und werden abgearbeitet, sobald die Mod wieder pollt. Kein Datenverlust, aber Kauf-Bestaetigungen koennen sich verzoegern.

= Wie werden Ueberbote-Benachrichtigungen gezeigt? =

Keine E-Mails. Der User sieht im Shortcode `[psyerns_auctionhouse_my]` pro Gebot ein Status-Label: `Fuehrend`, `Ueberboten`, `Gewonnen`, `Verloren`. Siehe KNOWN_ISSUES.md #2 fuer einen bekannten Edge-Case bei Drei-Wege-Ueberbieten.

= Was ist mit Backup / Datenexport? =

Die 5 Plugin-Tabellen (`*_listings`, `*_transactions`, `*_balances`, `*_pending_actions`, `*_users`) koennen per Standard WP-Backup-Tool gesichert werden. Sie sind Mirror-Daten; die Mod ist die einzige Quelle der Wahrheit.

== Screenshots ==

1. Marketplace-View mit Filter, Sort und Pagination (Theme: stalker).
2. Listing-Detail mit Buy-Now-Button, Gebotshistorie und Live-Countdown.
3. Preis-Chart (Chart.js) fuer ein item_class ueber 30 Tage.
4. Admin-Panel: Settings-Tab mit API-Key-Feld und Rotate-Button.
5. Admin-Panel: Pending-Tab mit Auftrags-Log und Status-Filter.

== Changelog ==

= 1.0.0 =
* Initial release.
* 17 REST-Routen, 6 Shortcodes, 6 Admin-Tabs.
* Steam OpenID 2.0 Login mit Signatur-Verifikation.
* Preis-Zeitreihen via Chart.js mit 4 Zeitraeumen (24h/7d/30d/all).
* Full-Sync Listings, Delta-Sync Transactions, Upsert-Balances.
* CSRF via WP-Nonces, Rate-Limiting via Transients, Idempotenz via action_uuid.
* Bekannte v1-Limits dokumentiert in KNOWN_ISSUES.md (Outbid-History, Steam-UID-Helper-Redundanz, admin_post-vs-REST-Trennung, Zero-Fill in Preis-Buckets).

== Upgrade Notice ==

= 1.0.0 =
First public release. Review KNOWN_ISSUES.md before deploying to production.
