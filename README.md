# Shop Cleanup – Pre-Launch Tool für WooCommerce + Germanized

> WordPress-Plugin zum sauberen Aufräumen von Test-Daten vor dem Go-Live eines WooCommerce-Shops mit **WooCommerce Germanized** (inkl. Pro / Storeabill).

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B.svg)]()
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg)]()
[![WooCommerce](https://img.shields.io/badge/WooCommerce-HPOS--ready-96588A.svg)]()
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)]()

---

## Warum das Plugin?

Wer einen WooCommerce-Shop mit Germanized aufbaut, produziert während der Entwicklungsphase zwangsläufig Testbestellungen, Testrechnungen und Testkunden. Vor dem Go-Live müssen diese Daten vollständig weg – sonst startet der Shop mit Rechnungsnummer `#147` statt `#1`, die Analytics zeigen Fantasieumsätze, und der Action Scheduler ist mit Altlasten verstopft.

Germanized bietet aus GoB-Gründen **bewusst keine GUI-Funktion** zum Zurücksetzen der Buchhaltungsdaten. Der offizielle Weg ist Handarbeit via Datenbank und FTP. Dieses Plugin automatisiert diesen Prozess sicher, HPOS-kompatibel und Shared-Hosting-tauglich.

## Features

- **Bestellungen & Refunds** löschen via `wc_get_orders()` → HPOS-kompatibel und nicht nur SQL-Brute-Force
- **Germanized Buchhaltungsdaten** leeren (alle `storeabill_*`-Tabellen nach offizieller [Vendidero-Doku](https://vendidero.de/doc/woocommerce-germanized/buchhaltungsdaten-zuruecksetzen))
- **PDF-Archive** im Filesystem bereinigen (`uploads/storeabill-*`, schützt `fonts` und `tmp`)
- **Action Scheduler** leeren (aufgeblähte AS-Tabellen sind nach Testphasen normal)
- **WooCommerce Sessions** und **Transients** leeren
- **Test-Kunden** löschen (nur User mit ausschließlich Rolle `customer`, Admins und Shop-Manager bleiben unangetastet)
- **Statistik-Preview** vor dem Ausführen (zeigt genaue Zahlen pro Kategorie)
- **AJAX-Batching** für Shared-Hosting ohne PHP-Timeout-Probleme
- **Bestätigungs-Phrase** `ALLES-LOESCHEN` plus JS-Confirm gegen versehentliches Auslösen
- **Live-Log** im Admin-UI mit Batch-Fortschritt

## Screenshots

*(Screenshots der Admin-Seite ergänzen)*

## Voraussetzungen

| Komponente | Version |
|------------|---------|
| WordPress  | 6.0+    |
| PHP        | 7.4+    |
| WooCommerce | 7.0+ (HPOS oder klassisch) |
| Germanized (Pro) | optional – Storeabill-Tabellen werden nur bereinigt, wenn vorhanden |

## Installation

### Variante A: Als klassisches Plugin

```bash
wp-content/plugins/wbp-shop-cleanup/wbp-shop-cleanup.php
```

Im WordPress-Backend unter **Plugins** aktivieren.

### Variante B: Als Must-Use Plugin (empfohlen für Dev-Umgebungen)

```bash
wp-content/mu-plugins/wbp-shop-cleanup.php
```

Muss nicht aktiviert werden, ist sofort aktiv. Vorteil: Wird beim üblichen Production-Deploy nicht mitkopiert, wenn MU-Plugins ausgeschlossen sind.

Nach der Installation erscheint unter **Werkzeuge → Shop Cleanup** eine neue Admin-Seite.

## Nutzung

1. **Backup anlegen.** Immer. Egal wie klein der Shop ist.
2. Seite öffnen: **Werkzeuge → Shop Cleanup**
3. In der Statistik prüfen, was aktuell im Shop ist
4. Haken setzen bei den Kategorien, die bereinigt werden sollen
5. In das Bestätigungsfeld `ALLES-LOESCHEN` eingeben
6. Button **„Jetzt bereinigen"** klicken → zweiten Bestätigungs-Dialog bestätigen
7. Das Live-Log zeigt den Fortschritt. Bei vielen Bestellungen laufen mehrere Batches automatisch durch.

### Empfohlener Workflow

```
Dev-Umgebung    →    Staging    →    Produktion
    ↓                   ↓                ↓
  Plugin            Plugin           Plugin
  testen            verifizieren     ausführen + löschen
```

Das Plugin nach dem Go-Live **deaktivieren und vom Server löschen**.

## Was genau wird bereinigt?

### Bestellungen & Refunds
- Bei HPOS: `wp_wc_orders`, `wp_wc_order_addresses`, `wp_wc_order_operational_data`, `wp_wc_orders_meta`
- Klassisch: `wp_posts` (Type `shop_order` / `shop_order_refund`) + zugehörige Meta
- Immer: `wp_woocommerce_order_items`, `wp_woocommerce_order_itemmeta`, Analytics-Lookups
- Abwicklung über `$order->delete( true )` → saubere Hooks, saubere Lookups

### Germanized Buchhaltung (Storeabill)
Alle acht offiziellen Storeabill-Tabellen werden via `TRUNCATE` geleert:

```
wp_storeabill_documents
wp_storeabill_documentmeta
wp_storeabill_document_items
wp_storeabill_document_itemmeta
wp_storeabill_document_notices
wp_storeabill_document_noticemeta
wp_storeabill_document_attachments
wp_storeabill_document_attachmentmeta
```

### PDF-Archive
`wp-content/uploads/storeabill-{suffix}/` wird geleert – außer den Unterordnern `fonts` und `tmp`, die für Vorlagen und Zwischenspeicher benötigt werden.

### Weitere Caches
- `wp_actionscheduler_actions`, `_logs`, `_claims`, `_groups` – jeweils `TRUNCATE`
- `wp_woocommerce_sessions` – `TRUNCATE`
- Transients aus `wp_options` – selektiver `DELETE` auf `_transient_%` und `_site_transient_%`

## Nach dem Cleanup: Manuelle Schritte

Das Plugin lässt zwei Dinge bewusst unangetastet:

1. **Germanized Rechnungs-Counter** zurücksetzen: In *WooCommerce → Einstellungen → Germanized → Rechnungen & Lieferscheine* die Nummerierung bei Rechnungen, Stornos und Lieferscheinen auf `0` setzen und speichern. Die Option-Keys variieren je nach GZD-Version, daher ist der GUI-Weg sicherer.
2. **WooCommerce Analytics** neu aufbauen: *WooCommerce → Status → Tools → „Analytics-Daten neu erstellen"*, falls Dashboards noch alte Zahlen zeigen.

## Sicherheit

- Zugriff nur mit Capability `manage_options` (Admin)
- WordPress-Nonces für alle AJAX-Requests
- Bestätigungs-Phrase `ALLES-LOESCHEN` muss exakt eingegeben werden
- Zusätzlicher JS-Confirm-Dialog vor dem finalen Ausführen
- Kunden-Löschung mit Doppel-Check: Nur Users mit **ausschließlich** der Rolle `customer` werden entfernt

Trotzdem: **Dieses Plugin ist destruktiv.** Immer Backup vor Ausführung.

## HPOS-Kompatibilität

Das Plugin erkennt automatisch, ob [High-Performance Order Storage](https://developer.woocommerce.com/docs/high-performance-order-storage/) aktiv ist, und verwendet ausschließlich die WooCommerce-CRUD-API (`wc_get_orders()` + `$order->delete()`). Damit funktioniert es sowohl auf modernen HPOS-Installationen als auch auf älteren Shops mit klassischer `wp_posts`-Speicherung.

## Batch-Größe anpassen

Default ist `BATCH_SIZE = 25`. Bei sehr schwachem Hosting auf `10` reduzieren, bei dickem VPS auf `100` hochsetzen. Konstante oben in der Datei:

```php
const BATCH_SIZE = 25;
```

## Kompatibilität

| Plattform / Setup              | Status |
|--------------------------------|--------|
| WooCommerce mit HPOS           | ✅     |
| WooCommerce klassisch (wp_posts) | ✅   |
| Germanized (Free)              | ✅ (Storeabill-Tabellen werden übersprungen, wenn nicht vorhanden) |
| Germanized Pro 3.0+            | ✅     |
| CloudPanel / Debian / Ubuntu   | ✅     |
| Shared Hosting (World4You, All-Inkl, Hetzner Konsoleh, …) | ✅ |
| WPML / Polylang                | ⚠️ ungetestet |
| Multisite                      | ⚠️ ungetestet, pro Site ausführbar |

## FAQ

**Muss ich das Plugin auf Produktion aktivieren?**
Ja, damit die Admin-Seite erscheint – aber nur kurz, für den Cleanup-Vorgang. Danach sofort deaktivieren und löschen.

**Gehen meine Produkte und Kategorien verloren?**
Nein. Das Plugin fasst weder Produkte (`post_type = product`) noch Kategorien an.

**Kann ich das auf einer Live-Seite mit echten Bestellungen ausführen?**
**Nein.** Das Tool löscht unwiderruflich alles. Nur vor dem Go-Live verwenden.

**Was ist mit Germanized E-Rechnungen (ZUGFeRD, XRechnung)?**
Die Metadaten liegen ebenfalls in den `storeabill_*`-Tabellen und werden mitgeleert. Bei ZUGFeRD-relevanten Archivierungspflichten nach Go-Live gilt GoBD – das Plugin ist **nicht** dafür gedacht, produktive Buchhaltungsdaten zu löschen.

**Funktioniert es auch ohne Germanized?**
Ja. Die Storeabill-Schritte werden übersprungen, wenn die Tabellen nicht existieren. Als reines WooCommerce-Cleanup-Tool nutzbar.

## Lizenz

[GPL-2.0-or-later](LICENSE) – Pull Requests willkommen.

## Autor

**Web-Beratung & Entwicklung Purin e.U.**
Christoph Purin · Bartholomäberg, Vorarlberg (AT)
🌐 [purin.at](https://purin.at)

## Changelog

### 1.0.0
- Initialer Release
- HPOS-kompatible Bestell-Löschung
- Storeabill-Cleanup nach offizieller Vendidero-Doku
- AJAX-Batching
- Live-Log
