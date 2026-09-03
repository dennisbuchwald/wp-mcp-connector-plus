# Changelog

Alle wesentlichen Aenderungen am WP MCP Connector Plus werden hier dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/)
und dieses Projekt verwendet [Semantic Versioning](https://semver.org/lang/de/).

---

## [0.9.1] - 2026-09-03

Nachtrag zu 0.9.0: Auf dbw-media.de blieb der Fehler stehen, obwohl "Dynamic data: Allowed" gesetzt war.

### Geaendert

- **Der Konnektor prueft, ob die Berechtigung ueberhaupt greift**, statt sie zu vergeben und zu hoffen. `unfiltered_html` ist eine *Meta*-Capability: Steht `DISALLOW_UNFILTERED_HTML` in der `wp-config.php`, macht WordPress daraus ein `do_not_allow` - fuer jedes Konto, Administratoren eingeschlossen. Keine Vergabe ueber `user_has_cap` kommt daran vorbei. Dasselbe gilt auf Multisite fuer alle ausser Super-Admins. In beiden Faellen wird der Save jetzt gar nicht erst versucht, sondern mit einer Meldung abgelehnt, die die Ursache benennt.
- **Die Einstellungsseite sagt es dazu.** Steht die Einstellung auf *Erlaubt*, waehrend eine der beiden Sperren greift, steht das rot darunter - statt eine Einstellung anzuzeigen, die nichts bewirkt.
- **`site-info` meldet `capabilities.dynamicData`** mit `allowed`, `effective` und einem Satz Klartext. Damit ist es eine Auskunft statt etwas, das aus einem gescheiterten Schreibvorgang zu erraten waere.

### Was nicht die Ursache war

Vermutet wurden Hook-Zeitpunkt und -Prioritaet. Beides scheidet aus: `user_has_cap` wird bei *jedem* `current_user_can()` durchlaufen, also auch mitten im `wp_update_post`, egal an welchem Save-Hook die Block-Bibliothek haengt. Ein Prioritaetsproblem gibt es dort nicht.

Bewusst nicht gebaut: ein Weg um `DISALLOW_UNFILTERED_HTML` herum. Die Konstante ist eine ausdrueckliche Entscheidung derjenigen, die die Seite aufgesetzt haben. Sie im Plugin zu unterlaufen waere genau die Art von Hintertuer, gegen die es sonst ueberall absichert.

---

## [0.9.0] - 2026-09-03

Aus dem Befund, dass fast jede Leistungs- und Branchenseite auf dbw-media.de nicht speicherbar war - also genau die Seiten, fuer die der Konnektor gebaut ist.

### Hinzugefuegt

- **Dritte Achse in den Einstellungen: "Dynamic data"**, Standard *Gesperrt*. Manche Block-Bibliotheken verweigern das Speichern einer Seite mit Dynamic Data, solange das Konto kein `unfiltered_html` hat. Auf *Erlaubt* gesetzt, vergibt der Konnektor die Capability **fuer die Dauer eines einzigen `wp_update_post`** und nimmt sie in einem `finally` wieder weg - ein Fatal mitten im Speichern kann sie also nicht stehen lassen. An der Rolle haengt sie nie.
- **Ein Guard, der ersetzt, was WordPress dabei nicht mehr tut.** Mit `unfiltered_html` faellt die kses-Filterung fuer diesen Save weg. Abgelehnt wird deshalb jeder Schreibvorgang, der **neu** einbringt: `<script>`, ein Inline-Eventhandler (`onclick=`, `onerror=` ...), eine `javascript:`- oder `data:text/html`-URL, ein iframe/object/embed. Die Meldung nennt Art, konkretes Element und den Blockpfad. Nur Neues zaehlt - eine Seite mit bestehendem Video-Embed bleibt normal bearbeitbar.
- Jeder Save mit erhoehter Berechtigung sagt das in der Antwort (`elevated`) und im Aktivitaetsprotokoll.

### Geaendert

- **Die Capability-Ablehnung erklaert sich selbst.** Bisher ging der rohe 403 durch. Drei Sprints in Folge wurde daraus "das Plugin filtert" geschlossen und auf WP-CLI ausgewichen - beim Telefonnummer-Sprint gingen so 13 von 20 Seiten an der API vorbei. Die Meldung nennt jetzt die fehlende Capability, den betroffenen Benutzer, die Einstellung, die es behebt, und sagt ausdruecklich, dass WP-CLI daran vorbeigeht, weil es ohne Benutzer laeuft - dass dort also gar nicht geprueft wird.

### Nicht so geloest

`unfiltered_html` dauerhaft an die Rolle: Das waere die weiteste Berechtigung im ganzen Satz, in einer Rolle, die bewusst nicht veroeffentlichen, loeschen, hochladen oder Einstellungen aendern kann. Und keine Sammel-Einstellung "Zugriff auf alle Plugins" - so einen Schalter gibt es nicht. Heute haengt es an `unfiltered_html`, morgen an Feldgruppen-Rechten oder eigenen Produkt-Capabilities; ein Sammel-Label verspraeche eine Abdeckung, die dahinter nicht existiert.

---

## [0.8.0] - 2026-09-03

Drei Befunde aus der Arbeit an dbw-media.de: SEO endete immer im Browser, die Mediathek war ein blinder Fleck, und eine Suche fand einen Custom-Post-Type nicht.

### Hinzugefuegt

- **`content-write` schreibt SEO-Felder.** Neuer Parameter `meta` mit Titel, Description, Focus-Keyword und Canonical (Rank Math und Yoast). Whitelist, keine offene Tuer zu `post_meta` - Bloecke, Page-Builder und Lizenzpruefungen legen dort auch Dinge ab, die niemanden etwas angehen. Ein unbekannter Schluessel ist ein Fehler und nennt die erlaubten. Canonical wird als URL geprueft, Text von Markup und Zeilenumbruechen befreit.
  - `meta` steht fuer sich: Eine Canonical zu korrigieren ist kein Grund, den Blockbaum anzufassen. Ohne `ops` und `tree` bleiben Inhalt und Aenderungsdatum unberuehrt, und es wird keine Revision verbraucht.
  - **Wichtig:** WordPress versioniert `post_meta` nicht. Fuer Meta gibt es also kein Zurueck per Klick. Der Probelauf zeigt deshalb alten und neuen Wert jedes Feldes, die Antwort sagt es ausdruecklich, und die Protokollzeile traegt den alten Wert - sonst waere er weg.
- **Drei Medien-Werkzeuge.** `media-list` und `media-read` (Lesestufe) zeigen Alt-Text, Titel, Bildunterschrift, URL, MIME-Typ und - der eigentliche Punkt - **jede Seite, die das Bild einbindet**. `media-update` (Schreibstufen) setzt Alt-Text oder Titel. Sonst nichts: kein Upload, kein Loeschen, kein Dateitausch.
  - Anlass: Ein Audit fand 76 Bilder mit leerem Alt-Attribut im Markup und konnte zu keinem davon etwas sagen. Alt-Text haengt meist am Anhang, nicht am Block - ein leeres Attribut kann also trotzdem korrekt ausgeliefert werden, oder eben nicht. Ohne Blick in die Mediathek ist die Zahl wertlos.
  - `usedIn` zaehlt Markup-Referenzen (`wp-image-{id}` und den Datei-Pfad, damit auch Seiten aus der Zeit einer alten Domain treffen) **und** Beitragsbilder. Sonst saehe ein Bild, das nur als Beitragsbild dient, unbenutzt aus.
  - `missing_alt: true` filtert direkt auf die ohne Alt-Text.

### Behoben

- **Custom Post Types waren unsichtbar.** Die Liste der erlaubten Post-Types verlangte `public` **und** `show_ui`. Ein Post-Type, den ein Plugin im Code ohne Admin-Oberflaeche registriert, fiel damit heraus - eine Suche ueber die ganze Seite meldete nichts und sah dabei richtig aus. Jetzt zaehlt nur noch `public`, `page` kommt wie bisher immer dazu, Anhaenge bleiben draussen (die haben eigene Werkzeuge).

---

## [0.7.1] - 2026-09-03

Aus einem Lauf ueber dbw-media.de: 7 Seiten geaendert, 13 abgelehnt mit "This content contains dynamic data". Betroffen war jede Seite mit den Legacy-Containern eines bestimmten Block-Plugins.

### Geaendert

- **Eine Ablehnung von aussen sagt jetzt, woher sie kommt.** Bisher kam die fremde Fehlermeldung nackt zurueck - direkt nach einem Probelauf, der `ok` gemeldet hatte. Das liest sich wie ein Fehler des Konnektors. Die Meldung nennt jetzt: dass die eigene Pruefung durchlief, dass die Ablehnung beim Speichern von WordPress oder einem anderen Plugin kam, und - wenn die Aenderung selbst nichts Gefiltertes einbringt - dass es um bereits gespeicherten Inhalt geht.
- **Die Meldung nennt die Plugins, die am Speichern mitschreiben.** Ein Blick in die Hook-Registry (`wp_insert_post_data`, `wp_insert_post_empty_content`, `content_save_pre`), Callback zu Datei zu Plugin-Ordner aufgeloest, nur im Fehlerfall. Aus "irgendwas hat abgelehnt" wird eine Liste mit ein bis drei Namen.

### Warum nicht der vorgeschlagene Fix

Vorgeschlagen war wieder, dem KI-Benutzer `unfiltered_html` zu geben. Das haette nicht geholfen: `wp_kses` lehnt keinen Speichervorgang ab, es schreibt Inhalt still um - genau deshalb gibt es die Impact-Pruefung. Die Meldung "This content contains dynamic data" steht weder in WordPress (geprueft in kses.php, post.php, blocks.php, Block Bindings, REST-Posts-Controller der 6.9) noch in diesem Plugin. Sie kommt aus einem Drittplugin, das Speichervorgaenge filtert. Die Loesung ist, dessen Einstellung zu finden - oder die Aenderung im Editor zu machen, wo sie als dein eigener Benutzer laeuft.

---

## [0.7.0] - 2026-09-03

Aus dem Einsatz auf dbw-media.de: eine Telefonnummer in einem 60-KB-Rechtstext aendern.

### Hinzugefuegt

- **Operation `patch_html`.** Aendert einen Textausschnitt *in* einem Block, statt den Block als Ganzes zu ersetzen: `{"op":"patch_html","path":"4.2","find":"07131 123456","replace":"+49 7131 123456"}`. Bisher verlangte `replace` das komplette Markup des Blocks zurueck - auf einer Datenschutzseite zehntausende Zeichen abtippen, um zwoelf zu korrigieren, und jedes abgetippte Zeichen kann falsch zurueckkommen.
  - Der Suchtext muss **genau einmal** in diesem Block vorkommen. Keinmal heisst, der Aufrufer arbeitet mit einem veralteten Stand; mehrmals heisst, er kann nicht wissen, welche Stelle er gerade aendert. Beides wird abgelehnt statt geraten. `content-search` liefert den Umgebungstext woertlich - damit ist ein eindeutiger Anker leicht zu finden.
  - Nur das eigene Markup des Blocks wird angefasst. Kinder eines Containers haben eigene Pfade und werden dort geaendert.

### Geaendert

- **Lange Ausgaben werden gefenstert statt still gekappt.** `content-preview` und `content-fetch-live` schnitten bei 60.000 bzw. 200.000 Zeichen ab und hinterliessen nur einen HTML-Kommentar mitten im Markup - leicht zu uebersehen, und es gab keinen Weg zum Rest. Eine Rechtsseite wurde gelesen, halbiert und nach der Haelfte beurteilt. Beide melden jetzt `bytes`, `offset`, `truncated` und `nextOffset` und nehmen `offset` entgegen.

### Nicht gebaut

Vorgeschlagen war ein Parameter `blocks_file: "/tmp/blocks.json"`, der den Inhalt serverseitig aus einer Datei liest. Zwei Gruende dagegen: Der MCP-Server laeuft auf dem WordPress-Host, die Datei liegt auf dem Rechner des Aufrufers - der Pfad existiert dort gar nicht. Und wenn es funktionierte, waere es ein Datei-Lesegeraet: `blocks_file: "../wp-config.php"` schreibt die Datenbank-Zugangsdaten als Text auf eine Seite. `patch_html` loest dasselbe Problem, ohne eine Datei zu beruehren.

---

## [0.6.1] - 2026-09-02

Beim ersten echten Einsatz der Reparatur-Tuer aufgefallen: Sie ging auf, aber nur halb.

### Behoben

- **Eine erlaubte Reparatur erreicht jetzt auch die Datenbank.** 0.6.0 oeffnete `wpmcp_allow_filtered_markup` fuer die Pruefung - der Probelauf meldete `ok`, das Speichern lief danach aber weiter durch den normalen Weg, und WordPress schnitt das Script wieder ab. Ergebnis: dieselbe JSON-Textwand wie vorher, nur mit gruener Meldung davor. Die Entscheidung, ob am Inhaltsfilter vorbei gespeichert wird, steht jetzt an einer Stelle (`wpmcp_should_preserve_markup`) und beruecksichtigt beide Gruende: Bestehendes erhalten und eine ausdruecklich geoeffnete Reparatur.

### Nicht gemacht

Der naheliegende Weg waere gewesen, dem KI-Benutzer `unfiltered_html` zu geben, solange repariert wird. Das ist deutlich weiter aufgemacht als noetig: Die Capability gilt dann fuer alles, was im selben Request laeuft, und bleibt haengen, wenn das Zuruecknehmen ausfaellt. Der Konnektor nimmt stattdessen fuer die Dauer *eines* Speichervorgangs den Filter heraus und setzt ihn danach zurueck.

---

## [0.6.0] - 2026-09-02

Aus der zweiten Runde desselben Fehlerberichts. Der gemeldete Zusammenhang stimmte wieder nicht - die Seiten waren Altlasten aus 0.5.0, dupliziert bevor der Fix da war. Beim Nachsehen kam aber ein echtes Loch zum Vorschein.

### Sicherheit

- **Neues Markup wird an seinem Inhalt erkannt, nicht an der Anzahl.** Bisher verglich die Pruefung, wie viele `<script>` vor und nach der Aenderung im Inhalt stehen. Wer in einem Schreibvorgang das JSON-LD der Seite entfernt und ein eigenes Script einsetzt, blieb bei derselben Zahl - die Pruefung meldete "nichts Neues" und der Konnektor speicherte es ungefiltert. Verglichen werden jetzt die Fragmente selbst: Was vorher im Inhalt stand, darf bleiben, alles andere ist neu. Verschieben und Entfernen bleiben erlaubt, ein geaendertes Script zaehlt als neu.
- Die Fehlermeldung nennt jetzt nur noch das tatsaechlich Neue statt alles Vorhandene.

### Hinzugefuegt

- **Verwaistes JSON-LD wird gemeldet.** Strukturierte Daten ohne `<script>` drumherum rendern als Textwand auf der Seite. Das ist der Fingerabdruck eines frueher abgeschnittenen Scripts - und war bisher nur zu bemerken, indem jemand die Seite ansah. Die Warnung haengt am Blockpfad, greift auch bei Entities und typografischen Anfuehrungszeichen, und laeuft vor der Registrierungspruefung: Ein Befund bleibt ein Befund, auch wenn der Block auf dieser Seite gar nicht registriert ist.
- **Filter `wpmcp_allow_filtered_markup`** (Standard: aus). Der Konnektor lehnt Scripts konsequent ab - und kann deshalb auch keine reparieren, die er selbst verloren hat. Wer aufraeumen muss, oeffnet die Tuer im Code fuer die Dauer der Arbeit und schliesst sie wieder. Bewusst kein Haken im Backend: Ein Haken wird angelassen. Solange offen, sagt jeder betroffene Schreibvorgang das im Ergebnis.

---

## [0.5.2] - 2026-09-02

Aus einem Fehlerbericht: Auf weinbruderschaft-brackenheim.de liess sich die Datenschutzseite (ID 75) nicht bearbeiten, das Impressum daneben schon. Die Meldung war ein nichtssagendes "No permission to edit post 75".

### Behoben

- **Die Datenschutzseite laesst sich auf der Vollstufe bearbeiten.** WordPress bewacht genau die Seite, die unter Einstellungen > Datenschutz hinterlegt ist, zusaetzlich mit `manage_privacy_options`.
- **Die Meldung sagt jetzt, woran es liegt** - Seite, Einstellungsort, Stufe und Abschalter - statt den Aufrufer in den Rolleneinstellungen suchen zu lassen.

### Warum nicht so, wie im Bericht vorgeschlagen

Der Bericht schlug vor, der Rolle `manage_privacy_options` zu geben. Das haette nichts bewirkt: Die Capability ist eine *Meta*-Capability, niemand prueft sie direkt. WordPress loest sie in `manage_options` auf (auf Multisite `manage_network`) - also volle Administration der Seite. Die ehrliche Fassung des Vorschlags waere gewesen, dem Agenten die ganze Website zu geben, damit er einen Absatz aendern kann. Genau das schliesst der Konnektor auf jeder Stufe aus, und ein Test haelt das fest.

Stattdessen faellt die Admin-Anforderung fuer **eine einzige Pruefung** weg: Bearbeiten (nie Loeschen) genau dieser einen Seite, durch den Agenten, auf der Stufe, die Veroeffentlichtes ohnehin freigibt. Alle uebrigen Anforderungen der Pruefung bleiben stehen, die Rolle bekommt kein einziges Recht dazu. Wer die Seite ganz aus der Reichweite halten will: `add_filter( 'wpmcp_allow_privacy_policy_edit', '__return_false' );`

*(Nebenbei: Der Workaround im Bericht nannte die Rolle `wpmcp_agent`, sie heisst `wpmcp_ai_editor` - er waere doppelt wirkungslos geblieben.)*

---

## [0.5.1] - 2026-09-02

Aus einem Fehlerbericht: In einer duplizierten Seite fehlte das JSON-LD-Schema, das rohe JSON stand als Text im Block. Gemeldet als Folge der `replace`-Operationen.

### Behoben

- **Duplizieren erhaelt den Inhalt unveraendert.** `content-duplicate` legte die Kopie mit `wp_insert_post()` an, und WordPress filtert dabei fuer Konten ohne `unfiltered_html`. Das `<script type="application/ld+json">` der Vorlage war damit schon weg, bevor ueberhaupt eine Bearbeitung stattfand. Die Kopie geht jetzt denselben erhaltenden Weg wie jede Aenderung an einer bestehenden Seite.
- Der gemeldete Zusammenhang war nicht der richtige: Die Art der Operation spielt keine Rolle. Ein Test haelt das fest - eine reine Attributaenderung, eine Blockersetzung und viele Operationen in einer Transaktion erhalten das Script gleichermassen. Auch die Rueckmeldung war korrekt: Ohne Script im Ausgangszustand gibt es nichts zu erhalten, also gab es auch nichts zu warnen.

### Warum das nicht schon in 0.3.0 mitkam

0.3.0 formulierte die Regel als Zaehlung: Markup, das vorher da war, darf nicht verschwinden. Eine Kopie hat kein Vorher - nach dieser Regel sieht ihr gesamter Inhalt wie neu eingebrachtes Markup aus. Der Duplizier-Pfad braucht deshalb die einfachere Zusage: Eine Kopie ist eine Kopie.

---

## [0.5.0] - 2026-09-02

Aus einer echten Aufgabe: eine Telefonnummer ueber 18 Seiten vereinheitlichen, 31 Fundstellen.

### Hinzugefuegt

- **`content-search`** - findet einen String oder regulaeren Ausdruck in einem Aufruf ueber die ganze Seite. Bisher musste dafuer jede Seite einzeln gelesen und von Hand gezaehlt werden.
  - Zu jedem Treffer: Post, Blockpfad, Blocktyp, Instanz-ID, ob er im Markup oder in einem Attribut sitzt, und der **rohe Text davor und danach**. Ohne Trimmen, ohne Entity-Umwandlung.
  - Der Kontext ist der eigentliche Zweck. In der Aufgabe hatten fuenf Seiten ein `<br>` vor einem leeren tel-Anker und eine nicht; eine aus den fuenf hochgerechnete Aenderung haette die sechste stillschweigend uebersprungen und Erfolg gemeldet.
- **`content-fetch-live`** - ruft die oeffentliche URL mit Cache-Buster ab und liefert das ausgelieferte HTML samt Cache-Headern. Die einzige ehrliche Abnahmepruefung: Bei aktivem Page-Cache kann die Datenbank stimmen, waehrend Besucher noch die alte Seite sehen.
- **Cache leeren nach dem Schreiben** - Objekt-Cache und Page-Cache fuer die betroffene Seite, mit Rueckmeldung im Ergebnis. Laesst sich der Page-Cache nicht ansteuern, steht das da, statt uebergangen zu werden.

### Geaendert

- **`site-info` meldet die tatsaechlich vorhandenen Werkzeuge** statt eines nackten `liveEdit`-Schalters. Ein Flag ohne Werkzeug dahinter ist irrefuehrend: Auf der Lesestufe sind die Schreib-Werkzeuge nicht abgeschaltet, sondern gar nicht registriert. Die Antwort listet jetzt Lese- und Schreibwerkzeuge einzeln, nennt die Stufe und sagt in einem Satz, was das bedeutet.
- **Jede Lese-Beschreibung nennt ihre Quelle** - Datenbank, gerenderte Ausgabe oder oeffentliche URL. `content-preview` sagt jetzt ausdruecklich, dass es die gespeicherten Inhalte rendert und nicht das, was ein Besucher bekommt.
- **`blocks-describe` stellt klar, dass es Blocktypen beschreibt**, nicht den Inhalt einer Seite. Im Testlauf wurde es benutzt, um Markup einer konkreten Seite zu ermitteln; dafuer ist `content-read` zustaendig, das `innerHTML` unveraendert liefert.

---

## [0.4.0] - 2026-09-02

Aus Befunden der laufenden Nutzung.

### Hinzugefuegt

- **Rueckgaengig** - zwei neue Faehigkeiten: `content-revisions` listet die Historie einer Seite mit Zeitstempel, Autor und Blockzahl, `content-restore` setzt sie auf eine dieser Revisionen zurueck. Bisher musste ein Mensch in den Editor, wenn ein Schreibvorgang schiefging.
  - Das Wiederherstellen darf Markup zurueckbringen, das `content-write` verweigert. Eine Revision ist kein vom Agenten verfasster Inhalt, sondern ein Zustand, in dem die Seite schon war - er kann ihn nicht erfinden, nur wieder herstellen. Genau der Fall, in dem der Agent seinen eigenen Fehler bisher nicht beheben konnte.
  - Testlauf als Standard, und der aktuelle Stand wird vorher selbst zur Revision. Wiederherstellen ist damit ebenfalls umkehrbar.
  - Eine Revision, die zu einer anderen Seite gehoert, wird abgelehnt.
  - `content-revisions` ist auch ohne Schreibrecht verfuegbar, `content-restore` nicht.
- **SEO-Felder lesbar** - `content-read` liefert mit `include_meta` die Felder von Rank Math und Yoast, dazu Beitragsbild, Textauszug und Template. Bei einem QS-Audit waren sechs von sieben SEO-Pruefpunkten vorher nicht pruefbar.
  - Eine Erlaubnisliste, nicht alles: In Post-Meta liegen Lizenzschluessel, Tokens und interner Plugin-Zustand, und nichts davon gehoert in den Kontext eines Modells. Erweiterbar per Filter `wpmcp_readable_meta_keys`.
  - Nur lesend. Schreiben waere eine eigene Entscheidung, siehe unten.
- **`slug` und `parent` in jeder Leseantwort** - Bei einem Entwurf lautet die Permalink-URL nur `?page_id=1689`, der Slug liess sich daraus nicht ableiten. Bisher brauchte es dafuer einen zweiten Aufruf.
- **Hinweis bei leeren Containern** - Ein Container mit deklarierten erlaubten Kindern, der keine enthaelt, rendert als leere Sektion. Auf einer echten Seite waren das Vorlagenreste mit Titel, aber ohne Inhalt.

### Nicht umgesetzt

- **Schreibzugriff auf Meta-Felder** (Slug, Beitragsbild, SEO-Titel). Der Slug ist der Teil, an dem eine URL haengt, und dieses Plugin verspricht ausdruecklich, Slug, Status und Post-Type nie anzufassen. Das aufzuweichen waere eine eigene Entscheidung mit eigener Absicherung, kein Nebeneffekt eines Komfort-Features.
- **Simulation aller Seiteneffekte im Testlauf** war bereits in 0.3.0 der Punkt: Was WordPress beim Speichern veraendern wuerde, wird vorher gemeldet.

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
- Kein Rueckgaengig durch den Agenten (seit 0.4.0 moeglich); jede Aenderung erzeugt eine Revision.
- Erfordert WordPress 6.9 oder neuer und Inhalte aus Gutenberg-Bloecken.
