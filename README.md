# dbw Connector

KI-Konnektor für dbw-media-Kundenseiten. Stellt den Gutenberg-Blockbaum
als WordPress-Abilities bereit und exponiert sie über einen kuratierten
MCP-Server, damit Claude eine Website bedienen kann wie ein Redakteur:
anschauen, verstehen, ändern, neu bauen, prüfen.

**Status:** v0.1.0, noch nicht auf einer Kundenseite verifiziert.

## Was das ist (und was nicht)

Der Konnektor ist die **Inhalts- und Verständnisschicht**. Protokoll und
Transport kommen fertig vom offiziellen
[WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter)
(Composer-Abhängigkeit, Version gepinnt); die Fähigkeits-Registrierung
kommt von der WordPress Abilities API (Core seit 6.9). Wir bauen weder
MCP-Protokoll noch Auth selbst.

Der Wert liegt darin, was der Adapter nicht kennt: dass eine Seite ein
Baum aus Blöcken mit typisierten Attributen ist, welcher Block in welchen
gehört, welche Farben das Design-System erlaubt, und wie man verhindert,
dass eine KI eine Kundenseite zerschreibt.

## Voraussetzungen

- WordPress ≥ 6.9 (Abilities API in Core)
- PHP ≥ 8.1
- Theme auf Basis von `dbw-base-theme` + `dbw-base-core`

## Installation

```bash
composer install --no-dev
```

Plugin aktivieren. Bei der Aktivierung entstehen:

- die Rolle **dbw KI-Redakteur** (`dbw_ai_editor`)
- die Protokoll-Tabelle `{prefix}_dbw_connector_log`

Danach unter **Werkzeuge → dbw Connector**:

1. Benutzer anlegen, Rolle *dbw KI-Redakteur* zuweisen
2. Im Profil dieses Benutzers ein Anwendungspasswort erzeugen
3. MCP-Endpunkt und Zugangsdaten im Client eintragen

Endpunkt: `https://<domain>/wp-json/dbw-connector/v1/mcp`

### Claude Code verbinden

```bash
claude mcp add --transport http dbw https://<domain>/wp-json/dbw-connector/v1/mcp \
  --header "Authorization: Basic $(printf '%s' 'dbw-ai:APP PASSWORT' | base64)"
```

## Sicherheitsmodell

- **Kein Veröffentlichen.** Die Rolle hat keine `publish_*`-Capability.
  Neue Seiten und Duplikate sind Entwürfe, bis ein Mensch sie freigibt.
  Das erzwingt WordPress, nicht der Konnektor-Code.
- **Kein Löschen, kein Upload, keine Einstellungen.**
- **Live-Edit standardmäßig aus.** Veröffentlichte Seiten sind für die KI
  schreibgeschützt, bis der Schalter (oder `DBW_CONNECTOR_LIVE_EDIT`)
  gesetzt wird.
- **Anwendungspasswörter nur für diese Rolle.** Das Hardening im
  `dbw-base-core` deaktiviert sie global; der Konnektor öffnet sie
  ausschließlich für KI-Benutzer wieder. Für Menschen bleiben sie aus.
- **Dry-Run als Default.** Schreiben passiert nur mit `dry_run: false`.
- **Revision bei jedem Schreibvorgang** → Rollback ist ein Klick.
- **Slug, Status und Post-Type werden nie angefasst** → URLs bleiben.
- **Vollständiges Protokoll** unter Werkzeuge → dbw Connector.
- **Not-Aus:** `define( 'DBW_CONNECTOR_DISABLE', true );` in der
  `wp-config.php` legt den Konnektor still, ohne das Plugin zu
  deaktivieren.

## Performance

Die Inhaltslogik lädt ausschließlich im REST- und WP-CLI-Kontext
(`wp_abilities_api_init`). Der einzige Frontend-Codepfad ist ein
`isset()` auf einen Query-Parameter für signierte Vorschau-Links.
Seitenaufrufe zahlen nichts für den Konnektor.

## Die acht Fähigkeiten

| Ability | Zweck |
|---|---|
| `dbw/site-info` | Versionen, Module, Post-Types, Design-Tokens. Erster Aufruf jeder Session. |
| `dbw/blocks-catalog` | Der Baukasten: alle Blöcke mit Rolle, Zweck, Verschachtelungsregeln. |
| `dbw/blocks-describe` | Volles Attribut-Schema für einzelne Blöcke, gruppiert, Legacy markiert. |
| `dbw/content-list` | Seiten finden, auch gefiltert nach verwendetem Block. |
| `dbw/content-read` | Seite als Blockbaum: `outline`, `subtree` oder `full`. |
| `dbw/content-write` | Schreiben per Patch-Ops oder Volltausch, mit Validierung und Dry-Run. |
| `dbw/content-duplicate` | Seite als Entwurf duplizieren. Bevorzugter Start für neue Seiten. |
| `dbw/content-preview` | Gerendertes HTML, Überschriften-Gliederung, signierte Vorschau-URL. |

## Validierung

Jeder Schreibvorgang - auch der Dry-Run - durchläuft fünf Stufen:

1. **Existenz** – ist der Block auf dieser Seite registriert?
2. **Schema** – Typen, Enums, keine erfundenen Attribute
3. **Struktur** – `parent`, `ancestor`, `allowedBlocks`
4. **Design-Vertrag** – keine freien Farben, wo theme.json sie sperrt
5. **Roundtrip + Render** – serialisieren, neu parsen, vergleichen, dann
   `do_blocks()` mit Fehler-Handler als Smoke-Test

Fehler nennen immer den Blockpfad und den Grund, damit gezielt korrigiert
werden kann statt geraten.

## Tests

```bash
tests/fetch-shim.sh   # einmalig: echten WP-Blockparser holen
php tests/run-tests.php
```

Die Tests laufen ohne WordPress-Installation, benutzen aber den echten
WordPress-Parser und -Serializer - Roundtrip-Ergebnisse bedeuten hier
dasselbe wie auf einer Live-Seite.

## Wartungspunkt

`dbw_connector_open_containers()` in `includes/catalog.php` listet
Container, die beliebige Kinder aufnehmen. Geschlossene Container
brauchen keinen Eintrag - sie deklarieren `allowedBlocks` in ihrer
`block.json`. Kommt im Core ein neuer offener Container dazu, gehört er
in diese Liste (oder per Filter `dbw_connector_open_containers` ins
Projekt).

## Langfristig

Wenn der Konnektor stabil ist, wandert er als Feature-Modul
(`inc/features/connector/`) in den `dbw-base-core` und wird dort pro
Kunde per Settings-Toggle aktiviert. Bis dahin bleibt er ein eigenes
Plugin: schnellere Iteration, Composer-Abhängigkeiten, und "gar nicht
installiert" ist bei schreibendem Remote-Zugang die stärkere
Sicherheitsaussage als "installiert, aber deaktiviert".
