# Changelog

Alle wesentlichen Aenderungen am WP MCP Connector Plus werden hier dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/)
und dieses Projekt verwendet [Semantic Versioning](https://semver.org/lang/de/).

---

## [0.3.0] - 2026-09-02

### Behoben

- **Eine Aenderung an einem Block zerstoerte Markup in einem anderen** - Auf einer Live-Seite loeschte das Einfuegen eines CTA-Banners das JSON-LD-Schema, das weiter unten in einem unberuehrten `core/html`-Block lag. Ursache: WordPress speichert bei jedem Schreibvorgang die ganze Seite und filtert sie fuer Konten ohne `unfiltered_html`. Seit 0.2.0 wurde der Verlust wenigstens gemeldet - aber erst, nachdem er passiert war.
  - **Bestehendes Markup wird jetzt erhalten.** Bringt eine Aenderung nichts von der gefilterten Art neu ein, speichert der Konnektor ohne den Filter. Der Agent kann so nichts einschleusen, er kann nur nichts mehr zerstoeren, was schon da war.
  - **Neu geschriebene Scripts oder iframes bleiben ein Fehler** und werden bereits im Testlauf abgelehnt, nicht erst beim Speichern. Gezaehlt wie bei den geerbten Fehlern: ein zweites Script neben einem bestehenden gilt als neu.
  - Verweigern waere die falsche Antwort gewesen. Der Agent koennte eine solche Seite dann nie wieder anfassen - dasselbe Aussperr-Problem wie in 0.2.3, nur mit anderer Ursache.

---

## [0.2.3] - 2026-09-02

### Behoben

- **Bestehende Inhalte blockierten jeden Schreibvorgang** - Die Validierung beurteilte die ganze Seite nach der Aenderung, nicht die Aenderung selbst. Eine Seite mit fuenf ueber den Editor gespeicherten Hex-Farben liess sich damit gar nicht mehr beschreiben, obwohl der Einschub des Agenten sauber war: `ok: false`, fuenf Fehler, alle in unberuehrten Bloecken. In der Praxis trifft das viele Seiten, weil der Block-Editor Werte zulaesst, die dieser Validator strenger prueft.
  - Der Zustand **vor** der Aenderung wird jetzt mitgeprueft. Was es vorher schon gab, wird weiterhin gemeldet, verliert aber sein Vetorecht; nur was die Aenderung neu einbringt, blockiert.
  - **Gezaehlt statt verglichen:** Eine sechste Verletzung einer Art, die vorher fuenfmal vorkam, wird erkannt. Pfade verschieben sich beim Einfuegen und Loeschen, deshalb zaehlt die Art des Problems, nicht seine Position.
  - Gilt fuer `ops` und `tree` gleichermassen. Auch ein vollstaendiger Ersatz traegt bestehende Bloecke mit sich, die der Agent nur gelesen und unveraendert zurueckgeschrieben hat.

---

## [0.2.2] - 2026-09-02

### Behoben

- **Die Zugriffsstufe vergab nicht, was sie versprach** - Wer "Drafts and published pages" waehlte, bekam die Einstellung gespeichert und sonst nichts: Schreibversuche auf veroeffentlichte Seiten scheiterten weiter mit "No permission to edit post". Der Abgleich der Rollen-Rechte haengte nur an `update_option_wpmcp_access_level`, und WordPress feuert diesen Hook ausschliesslich, wenn die Option bereits existierte. Auf jeder Seite, die von einer Version ohne diese Einstellung kam, war das erste Speichern ein `add_option` - der Abgleich lief also genau in dem Fall nicht, fuer den er geschrieben war.
  - Zusaetzlich am `add_option`-Hook.
  - **Neuer Abgleich bei jedem Admin-Aufruf:** vergleicht die vergebenen Rechte mit der eingestellten Stufe und repariert Abweichungen, egal woher sie kommen. Vergleichen ist billig, geschrieben wird nur bei echter Abweichung.
  - **Neue Statuszeile "Permissions in step"** - laufen Stufe und Rechte auseinander, steht es rot in der Uebersicht statt unbemerkt zu bleiben.

### Hinweis

- Ein Rechteproblem bitte **nicht** dadurch umgehen, dass der Agent-Benutzer eine eingebaute Rolle wie Redakteur bekommt. Ein Redakteur darf veroeffentlichen und loeschen - genau das haelt jede Stufe hier bewusst zurueck.

---

## [0.2.1] - 2026-09-02

Beide Punkte kamen aus einer unabhaengigen Pruefung des ersten echten Schreibvorgangs.

### Behoben

- **Als Objekt deklarierte Attribute wurden als leeres Array gespeichert** - JSON-Dekodierung erzeugt PHP-Arrays, und ein leeres PHP-Array kodiert als `[]` zurueck, nie als `{}`. Ein Block, der ein Objekt erwartet, bekam ein Array; Block-Bibliotheken, die daraus CSS bauen, erzeugten stillschweigend nichts. Die Typangabe aus der `block.json` entscheidet jetzt, und der Wert wird vor dem Serialisieren wiederhergestellt. Betrifft jeden Block mit objekt-typisierten Attributen, nicht eine einzelne Bibliothek.

### Hinzugefuegt

- **Warnung bei fehlender Instanz-ID** - Mehrere Block-Bibliotheken (GenerateBlocks, Kadence, Stackable) binden ihr generiertes CSS an eine `uniqueId` pro Instanz. Fehlt sie, erzeugt der Editor beim Oeffnen eine neue: Die Seite gilt dann als geaendert, ohne dass jemand sie angefasst hat, und an die fehlende ID gebundenes Styling geht verloren. Das war schema-gueltig und trotzdem falsch, kommt jetzt als Warnung mit Nennung der Folge.
- **Hinweis im Schreib-Werkzeug**, bei einem unbekannten Blocktyp erst eine bestehende Instanz zu lesen und deren Form zu spiegeln. ID, generiertes CSS und Markup-Klassen muessen zusammenpassen; ein Block kann validieren und trotzdem subtil kaputt sein.

---

## [0.2.0] - 2026-09-02

Alle drei Punkte kamen aus dem Einsatz auf echten Kundenseiten.

### Hinzugefuegt

- **Pruefung, was WordPress tatsaechlich gespeichert hat** - Konten ohne `unfiltered_html`, und das ist der Agent bewusst, bekommen ihre Inhalte beim Speichern durch `wp_kses_post` gefiltert. Das entfernt Script-Tags, iframes und einzelne Attribute. Beim Duplizieren einer Seite verschwand so ihr JSON-LD-Schema, ohne einen Eintrag in irgendeinem Log - und die Validierung konnte es nicht sehen, weil sie prueft, was abgeschickt wird, nicht was ankommt. Jeder Schreibvorgang und jede Duplizierung vergleicht jetzt den gespeicherten Inhalt mit dem gesendeten und benennt die Abweichung samt Ursache.
- **`expected_modified` gegen gleichzeitiges Bearbeiten** - `content-read` liefert den Aenderungszeitstempel; wird er beim Schreiben zurueckgegeben, scheitert der Vorgang, statt die Arbeit eines Menschen zu verwerfen, der die Seite zwischenzeitlich gespeichert hat.
- **Mehrere Pfade pro `content-read`** - Eine echte QS-Runde brauchte fuenfzehn Einzelaufrufe fuer fuenfzehn Sektionen. `paths` holt sie in einem.

### Geaendert

- **`content-preview`** las sich, als sei es nur zum Pruefen der eigenen Schreibvorgaenge da. Es ist genauso nuetzlich, um eine bestehende Seite anzusehen.
- **Die Lesestufe sagt jetzt, dass sie keine Entwuerfe sieht.** WordPress kennt kein Recht, Entwuerfe zu lesen ohne sie bearbeiten zu duerfen; die Einschraenkung bleibt, wird aber nicht mehr verschwiegen.

---

## [0.1.0] - 2026-09-01

Erste Version.

### Hinzugefuegt

- **Acht Faehigkeiten** ueber die WordPress Abilities API, als MCP-Server bereitgestellt durch den offiziellen `WordPress/mcp-adapter`: Site-Fingerabdruck, Block-Katalog, Block-Details, Inhalte auflisten, Seite als Blockbaum lesen, Blockbaum schreiben, Seite duplizieren, Vorschau.
- **Blockbaum als Austauschformat.** Das Modell schreibt nie serialisiertes Block-Markup; die Serialisierung passiert serverseitig nach der Validierung. Das ist die haeufigste Fehlerquelle KI-erzeugter Gutenberg-Inhalte, und sie entfaellt damit.
- **Fuenfstufige Validierung** vor jedem Speichern: Existenz des Blocks, Attribut-Schema, Verschachtelung, Design-Vertrag aus theme.json, Roundtrip mit Render-Test. Testlauf ist Standard.
- **Drei Zugriffsstufen** (nur lesen, Entwuerfe, Entwuerfe und Veroeffentlichtes). Was eine Stufe nicht erlaubt, wird gar nicht erst registriert - ein Werkzeug, das nicht existiert, ist eine staerkere Zusage als eines, das prueft. Die Rollen-Rechte folgen derselben Einstellung, WordPress erzwingt die Grenze also ein zweites Mal.
- **Veroeffentlichen ist auf keiner Stufe moeglich**, ebenso wenig Loeschen, Datei-Upload oder Einstellungen. Neue Seiten bleiben Entwuerfe, bis ein Mensch sie freigibt.
- **Eigene Rolle "AI Editor"** und Anwendungspassword-Freigabe ausschliesslich fuer diese Rolle; fuer alle anderen Benutzer bleibt die Konfiguration der Seite unangetastet.
- **Gefuehrtes Setup** unter Werkzeuge, das zugleich Diagnose ist: prueft Abilities-API, tatsaechlich registrierte Faehigkeiten, nutzbaren MCP-Transport und den Agent-Benutzer. Ein Knopf erzeugt Benutzer, Anwendungspasswort und ein fertiges Rezept fuer die Verbindung.
- **Protokoll** jedes Aufrufs im Backend, mit Diff-Zusammenfassung und Link zur erzeugten Revision.
- **Signierte Vorschau-Links**, die ohne Login funktionieren und nach 15 Minuten verfallen.
- **Umgang mit synchronisierten Mustern** als eigene Einstellung, weil eine Aenderung daran jede einbettende Seite gleichzeitig trifft. Der Testlauf nennt die Zahl der betroffenen Inhalte.
- **Updates ueber GitHub**, privates Repository per Token unterstuetzt.
- **Vertraeglichkeit mit fremden mcp-adapter-Kopien.** Andere Plugins bringen die Bibliothek mit (Rank Math etwa), und wer zuerst laedt, gewinnt. Entschieden wird nach der Schnittstelle der geladenen Kopie, nicht nach ihrer Versionsnummer - geprueft gegen 0.4.1, 0.5.0 und 0.6.1.

### Bekannte Einschraenkungen

- Kein Medien-Upload, kein Beitragsbild, keine Taxonomien, kein Titel nach dem Anlegen, keine SEO-Metadaten.
- Strukturierte Daten (JSON-LD in `core/html`) koennen nicht neu geschrieben werden; bestehende bleiben seit 0.3.0 erhalten.
- Kein Rueckgaengig durch den Agenten; jede Aenderung erzeugt aber eine Revision.
- Erfordert WordPress 6.9 oder neuer und Inhalte aus Gutenberg-Bloecken.
