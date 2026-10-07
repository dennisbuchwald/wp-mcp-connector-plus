# Changelog

Alle wesentlichen Aenderungen am WP MCP Connector Plus werden hier dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/)
und dieses Projekt verwendet [Semantic Versioning](https://semver.org/lang/de/).

---

## [0.20.0] - 2026-10-07

Elementor. Der erste Kunde auf Elementor (staging.maxport.ch: Elementor 4.3.4, Pro 4.3.1, hello-elementor, Rank Math) hat 26 Seiten, jede aus Containern mit je einem HTML-Widget, in dem der ganze Abschnitt als rohes Markup steht, teils mit Skripten fuer Navigation, Bewertungen und Slider. Fuer eine redaktionelle SEO-Ueberarbeitung dort konnte der Connector bisher nichts tun: `content-read` las die Klartext-Kopie in `post_content`, und was `content-write` dort schrieb, sah nie jemand und ueberschrieb Elementor beim naechsten Speichern.

### Neu

- **`elementor-read`** (Lesestufe, nur wo Elementor laeuft): die Seite als Gliederung ihrer Elemente mit Id, Pfad und Typ. Ein HTML-Widget bringt mit, was es sagt: Ueberschriften mit Ebene, die ersten Worte jedes Absatzes, Links, Bilder mit Alt-Text (`null` ohne Attribut, `""` bei bewusst leerem), Anzahl Skripte, Styles, iframes und JSON-LD, Groesse in Bytes. Mit `element_id` ein Element mit allen Einstellungen und das Markup eines HTML-Widgets woertlich, in Fenstern von 60000 Bytes (`offset`, `nextOffset`). Liefert `modified`.
- **`elementor-write`** (Schreibstufen, nur wo Elementor laeuft): Operationen nach Element-Id, der Reihe nach, standardmaessig als Probelauf. `patch_html` (Text im Markup ersetzen, genau ein Treffer oder `all`, mit `setting` auch in einer anderen Text-Einstellung), `set_html`, `set_settings` (zusammenfuehren, `null` entfernt; jeder geaenderte Schluessel muss ein registriertes Control des Elements sein, Typ und Select-Optionen werden geprueft), `insert` (vor, nach, in einem Element oder `"root"`; Ids erzeugt der Server, sieben Hexziffern wie im Editor), `duplicate` (neue Ids fuer alles darin), `move`, `remove`. Verschachtelung wie im Editor, `isInner` wie der Editor es setzt. Die Antwort bestaetigt jede Operation (mit dem Text um eine Ersetzung, Ids und Pfaden des Neuen) und liefert nach dem Speichern `revisionId`, `modified` und den Cache-Bericht.
- **Gespeichert wird ueber Elementors eigenes Dokument** (`Document::save()`, wie beim Klick auf "Aktualisieren" im Editor): `_elementor_data`, die Klartext-Kopie, eine Revision mit den Elementdaten darauf, Version, und Elementor loescht die erzeugte CSS-Datei und den Element-Cache der Seite, die beim naechsten Aufruf neu entstehen. Danach leert der Connector die Seiten-Caches wie nach jedem Schreiben. `expected_modified`, die Bearbeitungssperre (`wpmcp_locked`), Zugriffsstufen, Arbeitssitzung, Protokoll und REST-Zaun gelten unveraendert.
- **`content-list`** markiert Elementor-Seiten mit `builtWith: "elementor"`, **`content-read`** liefert fuer sie Felder und Meta, aber keine Gliederung der Klartext-Kopie, sondern den Hinweis auf die Elementor-Werkzeuge, **`content-preview`** rendert sie durch Elementor, **`content-search`** durchsucht ihre Elemente (Treffer mit `elementId`, `setting`, `in: "elementor"`; Link-Ziele und Attribute stehen nur dort), **`site-info`** nennt Elementor, Version, Pro und die Werkzeuge.

### Sicherheit

- **Elementors eigener Schutz haette jedes Skript der Seite entfernt.** Fuer ein Konto ohne `unfiltered_html` laesst `Document::save()` kses ueber jede Zeichenkette der ganzen Seite laufen (`Utils::kses_post_deep()`). Eine Ueberschrift im einen Widget zu aendern haette den Slider im anderen zerstoert, gesehen hatte das niemand (Test `test_elementor_alone_would_have_stripped_the_script`). Deshalb laeuft das Speichern mit `unfiltered_html` fuer genau diesen Aufruf, nie an der Rolle, und an die Stelle von kses tritt der Waechter, Zeichenkette fuer Zeichenkette: Jede Einstellung, die die Aenderung beruehrt, muss kses unveraendert ueberstehen oder darf nur behalten, was die gespeicherte Seite schon mindestens so oft enthaelt (die Regel aus 0.19.2). Text neben einem vorhandenen Skript aendern geht; ein neues oder geaendertes Skript, ein Event-Handler, ein iframe oder eine `javascript:`-URL wird mit dem zitierten Fundstueck abgelehnt. Ein Skript zaehlt nach seinem Ort, also wird auch das Verdoppeln eines Abschnitts mit Skript abgelehnt. JSON-LD bleibt erlaubt. Klartext-Einstellungen laufen nicht durch kses (dort wuerde es nur `&` in einer URL zerstoeren), aber `javascript:`, `vbscript:` und `data:` werden in jeder Einstellung abgelehnt, ebenso jede Aenderung an dynamischen Tags (`__dynamic__`). Wo die Seite `unfiltered_html` unmoeglich macht (`DISALLOW_UNFILTERED_HTML`, Multisite), wird ein Speichern abgelehnt, bei dem Elementor etwas entfernen wuerde (`wpmcp_unfiltered_html_unavailable`).
- **Elementors Vorlagen-Bibliothek ist nicht mehr automatisch im Bereich.** `elementor_library` (Header, Footer, Popups des Theme Builders) ist fuer WordPress oeffentlich und war damit fuer die Block-Werkzeuge lesbar. Diese Vorlagen landen auf jeder Seite zugleich wie die Elemente eines Themes; sie stehen jetzt unter "Zusaetzliche Post-Types" zum Ankreuzen ("My Templates"), eine Arbeitssitzung nimmt sie fuer ihre Dauer mit.

### Geaendert

- **Block-Schreiben auf Elementor-Seiten wird abgelehnt.** `content-write` mit `ops` oder `tree`, entsprechende `content-batch`-Eintraege und `content-restore` antworten mit `wpmcp_elementor_page` und erklaeren, warum (die Klartext-Kopie wird nie angezeigt und beim naechsten Speichern ersetzt). SEO-Meta, Slug, Eltern und Status gehen weiter ueber `content-write`.

### Behoben

- **`content-duplicate` verlor auf Elementor-Seiten jedes Skript.** Die Kopie von `_elementor_data` lief ueber Elementors REST-Meta-Sanitizer, der fuer ein Konto ohne `unfiltered_html` kses anwendet. Die Elementdaten werden jetzt byte-gleich kopiert (mit der Berechtigung nur fuer diese Kopie: es sind die Daten des Originals, nichts vom Agenten), Elementors Caches des Originals (`_elementor_css`, Assets, Element-Cache, Screenshot) nicht.

### API

- `contractVersion` bleibt 1. Neu sind zwei Werkzeuge, die nur dort registriert werden, wo Elementor laeuft, die Felder `builtWith` (`content-list`, `content-read`), `explains` (`content-read` auf Elementor-Seiten), `elementor` (`site-info`), `elementId`, `elType`, `widgetType` und `setting` in Suchtreffern auf Elementor-Seiten, und die Fehlercodes `wpmcp_elementor_page`, `wpmcp_not_elementor`, `wpmcp_element_not_found`, `wpmcp_bad_element`, `wpmcp_elementor_atomic`, `wpmcp_elementor_data_invalid`. Kein bestehendes Feld aendert Namen oder Form. Auf Elementor-Seiten antworten `content-read`, `content-search` und `content-write` anders als bisher, aber was sie dort bisher lieferten (die Klartext-Kopie, ein Schreiben ohne Wirkung) war falsch, nicht ein Verhalten, auf das sich ein Client stuetzen konnte.

### Tests

- **Neu: `tests/elementor-data.php`** (78 Pruefungen ohne WordPress und Elementor): alle Operationen, Ids, Pfade, `isInner`, die Gliederung mit Umlauten und Skripten, und was der Waechter auf Elementor-Seiten durchlaesst.
- **Neu: `tests/markup-guard-strings.php`**: der Waechter auf einzelnen Zeichenketten (11 Pruefungen). Die bestehenden Pruefungen des Block-Waechters bleiben unveraendert gruen.
- **Die Schicht gegen echtes WordPress hat eine Elementor-Suite** (`tests/wp-real/elementor`, `phpunit-elementor.xml.dist`, eigener PHPUnit-Lauf mit Elementor 4.3.4 aktiv, gepinnt mit SHA-256 in `setup.sh`; Pro gibt es nicht auf wordpress.org und wird nicht gebraucht). Seiten wie auf maxport.ch, der Agent arbeitet ueber die Abilities, gelesen wird zurueck aus Elementor: Gliederung, Fenster, Probelauf, H1 zu H2 neben einem Skript (gespeichert, gerendert, Revision mit Elementdaten, Klartext-Kopie), set_html, FAQ einfuegen, verschieben, verdoppeln, entfernen, veralteter Stempel, Ablehnungen (Skript, onerror, javascript:, iframe, Kopie mit Skript), Controls, unbekannter Widget-Typ, Sperre, Zugriffsstufe, Block-Werkzeuge lehnen ab, Vorschau und Suche, `site-info` und Bereich, CSS-Datei, Duplizieren. Ohne die Berechtigung fuer den einen Aufruf schlug der Skript-Test fehl, ohne den Waechter fuenf Ablehnungstests, ohne die Ablehnung im Block-Schreiben deren Test; der Duplikat-Test schlug gegen den Stand vor seiner Korrektur fehl. Elementor 4.3.4 meldet auf PHP 8.4+ Deprecations aus eigenen Dateien und eine Warnung aus seinem Content-Sanitizer bei jedem Container; die Bootstrap-Datei laesst genau diese durch, nach Ordner und Wortlaut.
- **`tests/load-boundaries.php`** kennt Unterordner von `includes/` und Lader-Funktionen mit verschachtelten Klammern; **`tests/shipped-files.php`** sucht Fehlercodes und Uebersetzungen auch in `includes/elementor/` und misst die Beschreibungen der Elementor-Werkzeuge mit eigenem Budget (2500 Zeichen), weil sie nur auf Elementor-Seiten zur Sitzung gehoeren; das Budget aller anderen bleibt bei 11000.

---

## [0.19.2] - 2026-10-05

Drei Befunde aus dem Einsatz auf dbw-media.de (Bericht in `docs/field-reports/2026-10-05-dbw-media.md`).

### Behoben

- **Container ohne `htmlTemplate` verloren still ihre Huelle.** Ein Knoten mit `innerBlocks`, aber ohne `html` und ohne `htmlTemplate` wurde als reine Folge seiner Kinder gespeichert. Fuer die Bloecke des dbw-base-Kits stimmt das (sie rendern auf dem Server und speichern nur ihre Kinder), fuer jeden Block mit statischer Huelle nicht: auf dbw-media.de ging eine GenerateBlocks-Sektion mit drei Karten als lose Ueberschriften und Absaetze live, auf hhs.hn rutschten die Klassen einer `core/group` aufs erste Kind. Der Probelauf sagte jedes Mal ok. Jetzt entscheidet `includes/wrappers.php` nach Belegen, in dieser Reihenfolge: (1) derselbe Block steht unveraendert schon so auf der Seite, dann bleibt er; (2) fuer `core/group` und `generateblocks/element` (GenerateBlocks 2) wird die Huelle aus den Attributen erzeugt, genau so, wie der Block-Editor sie speichert, und je Pfad in `wrapperGenerated` und als Warnung gemeldet; (3) eine gespeicherte Instanz desselben Blocktyps mit Kindern, auf der Seite selbst oder in einem der drei neuesten veroeffentlichten Beitraege, zeigt, ob um die Kinder Markup steht; (4) ohne Instanz gilt ein Block mit Render-Callback als "nur Kinder" (mit Warnung, nie still), ein Block ohne als statisch mit Huelle. Fehlt eine Huelle, lehnt der Probelauf mit dem neuen Code `wpmcp_wrapper_missing` ab, nennt den Pfad und schlaegt ein konkretes `htmlTemplate` vor: von einer vorhandenen Instanz mit der Klasse des Knotens, sonst aus Tag und Klassen.
- **Was erzeugt wird, ist gegen den Editor selbst geprueft.** Die erwarteten Huellen in `tests/wrappers.php` hat der Block-Editor geschrieben: die JavaScript-Pakete von WordPress 6.9.8 und das Editor-Skript von GenerateBlocks 2.4.1, in jsdom geladen, `createBlock()` und `serialize()`. Dabei gefunden: GenerateBlocks registriert fuer `element` einen Render-Callback, der nur das CSS anhaengt; die Huelle selbst steht im gespeicherten Markup (Klassen aus `globalClasses`, `gb-element-<uniqueId>` nur mit `styles`, dann `className`, danach `htmlAttributes` in ihrer Reihenfolge, keine `wp-block-*`-Klasse, ohne `tagName` gar kein Element). `core/group` speichert `wp-block-group`, dann `align*`, dann `className` und den Anker als `id`; das Layout schreibt nichts ins Markup, seine Klassen kommen erst beim Rendern. Was darueber hinaus die Huelle veraendert (Farben, Abstaende, `ariaLabel` an der Gruppe, Styles ohne `uniqueId`, Boolean- oder SVG-Attribute am Element), wird nicht nachgebaut, sondern abgelehnt. Ein Render-Callback allein beweist also nichts: auch `core/cover`, `core/list` und `core/media-text` haben einen und behalten ihre Huelle im Inhalt.
- **Eine Hervorhebung, die schon im Block stand, blockierte jede Aenderung an ihm.** Die H2s auf dbw-media.de tragen die GenerateBlocks-Inline-Hervorhebung `<mark style="background-color:rgba(0, 0, 0, 0)" ...>`. Ein `patch_html`, das den Text darum aenderte und das `<mark>` unveraendert durchreichte, wurde abgelehnt, als haette der Agent das Style eingeschleust. Warum kses es ueberhaupt entfernt: `safecss_filter_attr()` erlaubt `background-color`, verwirft aber jede Deklaration, deren Wert nach dem Herausnehmen der bekannten Funktionen (`var`, `calc`, `min`, `max`, `minmax`, `clamp`, `repeat`) noch eine Klammer enthaelt. `rgb()` und `rgba()` stehen nicht auf der Liste (nur innerhalb eines Verlaufs), mit oder ohne Leerzeichen. Neue Regel: Ein geaenderter Block darf behalten, was kses entfernen wuerde, wenn jedes dieser Fragmente mit genau diesem Text schon im gespeicherten Beitrag stand und nach der Aenderung nicht oefter vorkommt als vorher, als Multimenge ueber die ganze Seite gezaehlt. Fragmente sind Attribute an ihrem Element (`<mark style="..."` ist ein anderes Fragment als dasselbe Style an einem `<span>`), nicht erlaubte Tags und `script`, `style` und `iframe` samt Inhalt. Ob die Zerlegung alles erfasst, fragt der Waechter wieder kses selbst: Ohne die Fragmente muss der Rest unveraendert durch kses gehen, sonst gilt die alte Regel. Verschieben bleibt erlaubt, Kopieren an eine weitere Stelle, ein geaendertes Zeichen und alles Neue werden abgelehnt. Die Ablehnung zitiert jetzt jedes Fragment woertlich (`style="background-color:rgba(255, 0, 0, 1)" (new: not in the stored page)`) und sagt, ob es schon in diesem Block stand. Revisionen des Agenten zaehlen beim Wiederherstellen weiter nicht als bekannt.

### Geaendert

- **`site-info` sagt, wie die Schreib-Werkzeuge nach dem Hochsetzen der Stufe beim Client ankommen.** Die Seite stand beim Start der Sitzung auf "Nur lesen", wurde dann hochgesetzt, und `site-info` meldete die Schreib-Werkzeuge, waehrend Claude Code weiter nur die Lese-Werkzeuge anbot: Ein Client laedt die Liste einmal beim Verbinden. Die Werkzeugliste folgt weiter nur der eingestellten Stufe. Neu ist der Satz in `capabilities.explains`: Sind Schreib-Werkzeuge da, "If your client loaded its tool list before the access level was raised, reload the MCP connection (Claude Code: /mcp, then reconnect) to get the write tools."; auf der Lesestufe, dass ein verbundener Client sie erst nach dem Neuverbinden bekommt. Der Server kann es dem Client nicht selbst sagen: Der mitgelieferte mcp-adapter 0.6.1 setzt `capabilities.tools.listChanged` im `initialize` bewusst auf `false`, beantwortet GET auf dem Endpunkt (den Stream, ueber den ein Server `notifications/tools/list_changed` schicken wuerde) mit 405, weil SSE nicht umgesetzt ist, und antwortet auf jedes POST mit einfachem JSON. Die Stufe aendert sich ausserdem in einer wp-admin-Anfrage, an der kein Client haengt. `listChanged` ueber den Filter `mcp_adapter_initialize_response` einzuschalten, wuerde eine Benachrichtigung versprechen, die nie kommt; es bleibt daher aus. Ein Test in `RestFenceTest` haelt das fest und schlaegt an, sobald ein neuer Adapter es kann.

### API

- `contractVersion` bleibt 1: Neu sind nur das Feld `wrapperGenerated` in den Antworten von `content-write`, `content-create` und `content-batch` und der Fehlercode `wpmcp_wrapper_missing`. Ein Knoten ohne `htmlTemplate`, den 0.19.1 still ohne Huelle gespeichert hat, wird jetzt erzeugt oder abgelehnt; Knoten mit `html` oder `htmlTemplate` und dynamische Bloecke, die nur ihre Kinder speichern, verhalten sich wie bisher.

### Tests

- **Neu in `tests/markup-guard.php` und `MarkupGuardTest`:** der Fall aus dem Bericht (`patch_html` um die Hervorhebung, gegen echtes kses), ein neues Style und ein neues `onerror` daneben, Verdoppeln im selben Block und Kopieren in einen anderen, dasselbe Attribut an einem anderen Element, Verschieben, ein bestehendes und ein geaendertes iframe, und warum kses `rgba()` entfernt. Gegen 0.19.1 schlugen in `tests/markup-guard.php` 8 Pruefungen und in `MarkupGuardTest` 4 Tests fehl; alle bisherigen Pruefungen des Waechters bleiben unveraendert gruen.
- **Neu: `tests/wrappers.php`** mit allen Entscheidungen und den Editor-Ausgaben als Referenz. Schlug gegen 0.19.1 mit 52 Pruefungen fehl.
- **Die Schicht gegen echtes WordPress laeuft jetzt mit GenerateBlocks 2.4.1** (gepinnt mit SHA-256 in `tests/wp-real/setup.sh`, aktiv wie auf den Kundenseiten). Neu `WrapperTest`: wie GenerateBlocks und WordPress ihre Container registrieren, die Sektion aus dem Bericht ohne Templates (gespeichert und gerendert mit allen Klassen), `core/group`, die Ablehnung von `core/columns` und `core/buttons` mit Vorschlag aus einer veroeffentlichten Seite, und ein dynamischer Block, der weiter ohne Template geht. Fuenf der sechs Tests schlugen gegen 0.19.1 fehl.
- **`tests/create-and-batch.php`:** `content-create` und `content-batch` reichen `wrapperGenerated` und den Code `wpmcp_wrapper_missing` weiter; gegen den Stand vor dieser Korrektur schlugen 3 Pruefungen fehl.
- **`tests/register-abilities.php`** prueft den neuen Satz in `capabilities.explains` auf beiden Seiten der Lesestufe; gegen 0.19.1 schlugen 2 Pruefungen fehl.
- Die Platzhalter der dbw-base-Bloecke in den Tests ohne WordPress haben jetzt einen Render-Callback, wie ihr `render.php` ihn auf einer echten Seite erzeugt.

---

## [0.19.1] - 2026-10-04

### Behoben

- **Der Tab "Access" unter Werkzeuge > MCP Connector endete mit einem fatalen Fehler** ("Call to undefined function wpmcp_allowed_post_types()"), live auf hhs.hn. Die Liste der waehlbaren Post-Types braucht den Bereich des Connectors, und der lag bei den Werkzeugdateien, die nur laden, wenn die Abilities API startet. In 0.18.2 hat die Statustabelle auf derselben Seite die Abilities gezaehlt und die Dateien dabei nebenbei geladen; seit die Einstellungen in 0.19.0 einen eigenen Tab haben, tat das niemand mehr. Die Funktion liegt jetzt in `includes/post-types.php`, die immer mit `access.php` geladen wird. Website und Agent waren nicht betroffen, nur dieser Tab.

### Tests

- **Neu: `tests/load-boundaries.php`.** Liest den Code statt ihn auszufuehren: Welche Dateien laedt das Plugin spaet, welche Funktionen stehen darin, und ruft eine immer geladene Datei eine davon ohne `function_exists` auf. Die anderen Tests laden alle Dateien vorab und konnten diese Fehlerklasse nicht sehen. Der Test schlug gegen 0.19.0 fehl.

---

## [0.19.0] - 2026-10-02

Sicherheits- und Qualitaetsrelease nach einem Komplettaudit. Enthaelt den Hotfix 0.18.3 (der nie einzeln veroeffentlicht wurde) samt zwei Korrekturen daran. Vor dem Update den Abschnitt "Zu beachten" lesen: Der Agent braucht jetzt HTTPS, erreicht nur noch seinen MCP-Endpunkt, und die Werkzeugliste haengt nur noch an der Zugriffsstufe.

### Sicherheit

- **Das Agent-Konto erreicht nur noch seinen MCP-Endpunkt.** Ein Anwendungspasswort gilt fuer die ganze REST-API, nicht fuer eine Route. Mit dem Zugang des Agenten ging deshalb auch `/wp/v2`: das eigene Profil, die eigenen Anwendungspasswoerter, die Medienbibliothek und jede Route, die irgendein anderes Plugin anmeldet, alles an den Pruefungen und am Protokoll des Connectors vorbei. Jetzt bekommt jede REST-Anfrage eines Kontos mit der Rolle "AI Editor" ausser an `/wpmcp/v1/mcp` eine 403 mit Erklaerung. Interne REST-Aufrufe waehrend eines Werkzeugs sind nicht betroffen, ein Administrator mit dem Marker-Recht zum Debuggen ebenfalls nicht.
- **Kein XML-RPC fuer das Agent-Konto.** Anwendungspasswoerter gelten auch dort; die Anmeldung wird jetzt abgelehnt.
- **Das Agent-Konto kann sich selbst nicht mehr verwalten.** WordPress erlaubt jedem Konto, das eigene Profil zu bearbeiten und eigene Anwendungspasswoerter anzulegen, ohne jedes Recht. Ein abgeflossener Zugang haette so weitere Zugaenge erzeugen oder die E-Mail-Adresse (und damit den Passwort-Reset) aendern koennen. `edit_user`, `promote_user`, `create_app_password`, `edit_app_password`, `delete_app_password(s)` und die uebrigen Konto-Rechte sind fuer das Agent-Konto jetzt immer gesperrt, an sich selbst und an anderen. Ein Administrator legt dem Agenten weiter ueber die Einrichtung ein Passwort an.
- **Anwendungspasswoerter nur noch ueber HTTPS.** Das Plugin schaltete sie global ein, auch ohne HTTPS, und ueberging damit die Pruefung von WordPress selbst. Jetzt oeffnet es sie nur dort wieder, wo WordPress sie selbst zulassen wuerde: ueber HTTPS oder in einer als `local` erklaerten Umgebung.
- **Rechte haengen nicht mehr an der gespeicherten Rolle.** Die Stufe und eine Arbeitssitzung wurden als Rechte auf die Rolle geschrieben. Lief eine Sitzung nachts ab, blieb die Rolle weit offen, bis jemand wp-admin oeffnete oder der Agent den MCP-Endpunkt rief; jeder andere Weg (z.B. `/wp/v2`) sah die alten Rechte. Jetzt speichert die Rolle nur noch Lesen und das Marker-Recht, und was eine Stufe oder Sitzung dazugibt, wird bei jeder Rechtepruefung frisch berechnet (`user_has_cap`). Eine abgelaufene Sitzung ist damit in derselben Sekunde zu. Bearbeitungsrechte, die eine Stufe vergibt (`edit_posts`, `edit_pages`, `edit_others_*`, `edit_published_*`) und die eine aeltere Version oder ein Rollen-Editor auf der Rolle abgelegt hat, zaehlen fuer ein Konto mit nur der Agent-Rolle bei keiner Pruefung mehr. Andere Rechte, die ein Rollen-Editor der Rolle gibt, nimmt die Rechtepruefung nicht weg; die setzt der Abgleich der Rolle zurueck, beim naechsten Aufruf von wp-admin oder der REST-API (siehe "Zu beachten").
- **Veroeffentlichte Seiten bleiben ohne Live-Edit gesperrt, egal welche Rechte das Konto hat.** Bisher entschied nur `edit_post`; eine zweite Rolle auf dem Agent-Konto oder ein Rollen-Editor oeffnete so veroeffentlichte Seiten auf der Entwurfsstufe.
- **Deaktivieren laesst nichts offen.** Eine laufende Arbeitssitzung endet, die Rolle behaelt nur Lesen. Anwendungspasswoerter, Einstellungen und Protokoll bleiben, Reaktivieren stellt die Rolle wieder her.
- **Der Notschalter `WPMCP_DISABLE` sperrt auch die Anmeldung des Agenten.** Bisher blieb sein Anwendungspasswort gueltig, und ohne geladenes Plugin begrenzt nichts den Zugang auf den Endpunkt.
- **Was eine Zugriffsstufe nicht erlaubt, ist auch nicht registriert.** Der MCP-Server bot auf der Lesestufe nur die Lese-Werkzeuge an, registriert waren aber alle, und ueber `wp-abilities/v1` liessen sich die Schreib-Abilities fuer jedes Konto mit dem Marker-Recht ausfuehren. Jetzt wird nur registriert, was `wpmcp_ability_names()` fuer die Stufe nennt.
- **`content-list` zeigt nur, was gelesen werden darf.** Post-Type und Status gingen unveraendert an `WP_Query`: `status: "trash"` oder `"any"`, `post_type: "shop_order"` oder `"revision"` lieferten Titel, Slugs und URLs von Inhalten, die der Connector sonst nie zeigt. Jetzt gilt nur, was im Bereich des Connectors liegt (sonst Fehler `wpmcp_forbidden_type`), nur die Status publish, draft, pending, future und private (sonst `wpmcp_bad_status`, mehrere mit Komma), und je Post-Type nur das, was das Konto lesen darf: Entwuerfe nur mit `edit_others_*`, Privates nur mit `edit_private_*`. Das alles steckt in der Datenbankabfrage selbst.
- **Passwortgeschuetzte Seiten gelten nicht mehr als oeffentlich.** Sie sind "veroeffentlicht", ihr Inhalt ist aber genau das, was der Seitenbetreiber nicht allen zeigen wollte. Lesen (`content-read`, `content-list`, `content-fetch-live` und alles, was darauf aufbaut) braucht jetzt das Recht, die Seite zu bearbeiten, wie bei einem Entwurf. Ohne kommt `wpmcp_password_protected`.
- **Eine Arbeitssitzung oeffnet nur noch die Bausteine der Seite.** Sie nahm bisher jeden Post-Type auf, ausser denen, deren Name nach Kundendaten klingt. Ein nicht-oeffentlicher Post-Type ist aber aus einem Grund privat, den der Connector nicht sieht, und eine Namensliste kennt nur die Namen, an die jemand gedacht hat. Jetzt gilt eine Positivliste: oeffentliche Post-Types plus Theme-Elemente (`gp_elements`), Templates, Template-Teile und Navigationsmenues. Weitere ueber den Filter `wpmcp_session_post_types` oder als Haken unter Werkzeuge > MCP Connector.
- **Loeschen des Plugins raeumt auf (`uninstall.php`).** Bisher blieben nach dem Loeschen die Rolle, die Anwendungspasswoerter des Agenten, das Protokoll und alle Einstellungen zurueck: ein Zugang, an den sich niemand mehr erinnert, auf einem Konto, das nichts mehr begrenzt. Jetzt werden zuerst die Anwendungspasswoerter aller Konten mit der Rolle "AI Editor" widerrufen, dann die Rolle entfernt, die Tabelle `wpmcp_log` geloescht und alle Optionen des Plugins (auch die des Update-Pruefers). Auf Multisite fuer jede Seite des Netzwerks. Die Benutzerkonten selbst bleiben bestehen, ohne Rolle und ohne Passwort.
- **Vorschau-Links sind an ihren Zweck gebunden.** Das Token war ein HMAC ueber "Beitrags-ID|Ablaufzeit" mit dem Auth-Salt der Seite, mit dem auch anderer Code signiert. Jede andere Signatur ueber zwei Zahlen mit diesem Salt haette als Vorschau-Link zu einem Entwurf getaugt. Die signierten Daten beginnen jetzt mit `wpmcp-preview|`.
- **`content-fetch-live` ruft nur noch die eigenen oeffentlichen Seiten ab.** Der Abruf laeuft ueber `wp_safe_remote_get` (keine privaten oder internen Adressen ausser der eigenen Seite), Weiterleitungen werden von Hand und nur auf demselben Host verfolgt (hoechstens drei), das Zeitlimit sinkt von 20 auf 10 Sekunden. Entwuerfe, private und passwortgeschuetzte Seiten werden gar nicht erst abgerufen (`wpmcp_not_public` mit Verweis auf `content-preview`). Bei 401/403, 404 und 5xx sagt ein Feld `hint`, was meist dahintersteckt (Passwortschutz der Staging-Seite, Firewall, Wartungsmodus).
- **Bild-Uploads mit zu vielen Pixeln werden abgelehnt.** Ein PNG von wenigen Kilobyte kann 30000 x 30000 Pixel ankuendigen; WordPress dekodiert fuer die Vorschaubilder jedes Pixel und braucht dafuer Gigabytes Speicher. Grenze 40 Megapixel (`wpmcp_upload_too_many_pixels`), einstellbar ueber den Filter `wpmcp_max_upload_pixels`. Geprueft wird nur der Dateikopf, bevor irgendetwas gespeichert wird.
- **`content-restore` stellt keine Revision des Agenten mit gefiltertem Markup wieder her.** Die wiederhergestellte Revision zaehlte als bekannter Stand, egal wer sie gespeichert hatte. Bis 0.18.3 konnte ein Schreibvorgang des Agenten aber Markup an kses vorbei speichern (`onerror`, `javascript:`-Links), und jeder solche Save hinterliess eine Revision damit. Jetzt zaehlt eine Revision nur dann als bekannt, wenn ein Mensch sie gespeichert hat. Eine Revision des Agenten-Kontos oder eines nicht mehr vorhandenen Kontos wird geprueft wie ein neuer Schreibvorgang; die Ablehnung sagt das.
- **`content-restore` prueft dasselbe wie `content-write`.** Ein synchronisiertes Muster liess sich ohne Schreibrecht fuer Muster zurueckspielen, ein GeneratePress-Element mit "Execute PHP" ebenso. Beide Pruefungen stecken jetzt in einer gemeinsamen Funktion (`wpmcp_assert_writable_target`), die Schreiben, Wiederherstellen und Duplizieren nutzen.
- **`content-duplicate` kopiert kein Element mit "Execute PHP".** Die Kopie traegt die Einstellungen mit und fuehrt damit ebenfalls PHP aus. Ein Muster wird nur dupliziert, wenn Muster schreibbar sind.
- **`content-search` sieht nur, was `content-list` sieht.** `post_status` ging ungeprueft an die Abfrage: `"trash"`, `"inherit"` oder `"any"` fanden Text in Beitraegen, die kein anderes Werkzeug zeigt, und passwortgeschuetzte Seiten galten als lesbar. Jetzt gelten dieselben Status und dieselbe Sichtbarkeit je Post-Type wie in `content-list` (sonst `wpmcp_bad_status`), und ein Post-Type ausserhalb des Bereichs gibt `wpmcp_forbidden_type` statt einer Suche ueber Beitraege.

### Neu

- **Kein Speichern, waehrend ein Mensch die Seite im Editor offen hat.** `expected_modified` schuetzt nur, was schon gespeichert ist. Wer gerade im Block-Editor tippt, hat noch nichts gespeichert: der Schreibvorgang des Agenten ging durch, und dann ueberschrieb entweder das naechste Speichern des Menschen die Arbeit des Agenten oder ein Neuladen verwarf, was der Mensch noch nicht gespeichert hatte. Jetzt fragen `content-write`, `content-batch` und `content-restore` vor dem Speichern die Bearbeitungssperre von WordPress ab (`wp_check_post_lock`, dieselbe, die anderen "X bearbeitet gerade" zeigt) und lehnen mit `wpmcp_locked` ab, mit dem Namen der Person. Ein Probelauf warnt nur. Ein Batch mit einer gesperrten Seite speichert gar nichts, auch nicht die Seiten davor.
- **Der Editor sagt, wenn der Agent eine Seite geaendert hat.** Jedes echte Speichern (Schreiben, Batch, Wiederherstellen) merkt sich die Zeit in `_wpmcp_last_write`. Wer die Seite innerhalb von 24 Stunden im Block-Editor oeffnet, sieht oben den Hinweis "The AI agent changed this page 12 mins ago" mit Verweis auf Revisionen und Protokoll. Neue Aktion `wpmcp_saved` fuer eigenen Code. Beim Loeschen des Plugins wird der Stempel entfernt.
- **Werkzeuge > MCP Connector in drei Tabs:** Connection (Status und Einrichtung), Access (Arbeitssitzung und Einstellungen), Activity (Protokoll). Jeder Tab hat einen eigenen Link (`&tab=access`), Standard ist Connection. Alle Inline-Styles stecken in einem Style-Block, das Skript in einem.
- **Protokoll als WordPress-Liste** mit Filtern nach Werkzeug, Ergebnis (gespeichert, Probelauf, abgelehnt), Benutzer und Beitrags-ID, Suche in den Zusammenfassungen und 25 Eintraegen je Seite statt der letzten 100. Zeiten in der Zeitzone der Seite (UTC im Markup), Spalte Benutzer, Titel mit Link in den Editor, und bei jedem Speichern "Compare revisions" in den Revisionsvergleich von WordPress. Nur lesend, keine Sammelaktionen. Darueber steht, wie lange Eintraege aufbewahrt werden (Option `wpmcp_log_retention_days`, Standard 90, 0 = fuer immer).
- **Laufende Arbeitssitzung in der Admin-Leiste**, auf jeder Seite inklusive Frontend: "MCP session: 2 h 14 min" mit Link zu den Einstellungen und dem Unterpunkt "Close session now". Nur fuer Administratoren; kostet eine Rechtepruefung und eine Option je Seitenaufruf.
- **Statuszeile "Last connection"**: wann der Agent zuletzt etwas aufgerufen hat und welches Werkzeug. Bei abweichender Rolle ein Knopf "Repair now", der die Rolle sofort zuruecksetzt, statt zum Deaktivieren und Reaktivieren zu raten.
- **Kopieren-Knoepfe** fuer Befehle, Endpunkt, Passwort und Header (Zwischenablage, sonst Markieren und Kopieren), mit kurzer Bestaetigung "Copied". Passwort und Header liegen hinter "Show"; Kopieren geht, ohne sie anzuzeigen.
- **Der Kasten zur Arbeitssitzung sagt, was sie nicht kann:** sie erweitert, was der Agent mit seinen Werkzeugen darf, fuegt aber keine hinzu. Steht die Stufe auf "Read only", steht neben den Knoepfen, dass die Stufe dafuer auf "Drafts" oder "Drafts and published pages" muss.
- **Deutsche Uebersetzung der Oberflaeche.** Das Plugin laedt seine Uebersetzungen jetzt selbst (`load_plugin_textdomain` auf `init`) und bringt in `languages/` die Vorlage `wp-mcp-connector-plus.pot` und eine vollstaendige deutsche Uebersetzung (`de_DE`) mit: Einstellungsseite, Admin-Leiste, Hinweis im Editor, Rollenname ("KI-Redakteur"). Was der Agent liest (Werkzeugbeschreibungen, Meldungen in Antworten), bleibt englisch. `bash bin/i18n.sh` erneuert Vorlage und Uebersetzungen; ein Test meldet, wenn die Vorlage nicht mehr zum Code passt oder eine Uebersetzung fehlt.

### Behoben

- **Neu laden legt kein zweites Passwort mehr an.** Einrichtung und Arbeitssitzung wurden beim Rendern der Seite verarbeitet. Ein Neuladen mit dem erzeugten Befehl auf dem Bildschirm (oder "Formular erneut senden" nach Zurueck) legte jedes Mal ein weiteres Anwendungspasswort an oder oeffnete eine geschlossene Sitzung wieder. Jetzt gehen beide Formulare an `admin-post.php` (Nonce und `manage_options` geprueft) und leiten danach um. Das neue Passwort wartet 60 Sekunden in einem Transient, der an den Administrator gebunden ist, und wird beim Anzeigen geloescht.
- **Die Einstellungen zeigen waehrend einer Arbeitssitzung die gespeicherte Stufe.** Markiert war die Stufe, die die Sitzung gerade gibt ("Drafts and published pages"); wer in dieser Zeit etwas anderes speicherte, speicherte die weiteste Stufe mit, ohne es zu merken.
- **Menschen behalten ihre Anwendungspasswoerter.** Fuer jedes menschliche Konto lieferte das Plugin `false`, auch auf Seiten, die die Funktion nie abgeschaltet hatten. Das brach die WordPress-App und Automationen (n8n und aehnliche). Jetzt bekommt jedes menschliche Konto genau das, was es ohne das Plugin haette: aus, wo die Haertung des Themes oder ein Sicherheits-Plugin sie fuer alle abgeschaltet hat, sonst das, was WordPress und die anderen Plugins fuer dieses Konto entscheiden.
- **Backslashes in SEO-Feldern, Element-Einstellungen und Alt-Texten bleiben erhalten.** `update_post_meta` und `wp_update_post` entfernen einen Backslash, wenn der Wert nicht vorher mit `wp_slash` geschuetzt wurde. Ein Titel wie `Monitor 27\"` oder eine Display-Regel mit Backslash kam so verkuerzt in der Datenbank an, ohne Warnung. Betroffen waren `content-write` mit `meta`, `media-update` (Alt-Text) und `media-upload` (Alt-Text und Titel).
- **`media-update` meldet einen abgelehnten Titel als Fehler.** Das Ergebnis von `wp_update_post` wurde nicht geprueft: ein Titel, den WordPress nicht gespeichert hat, kam als "Saved." zurueck, und der Alt-Text war daneben schon geschrieben. Jetzt wird der Titel zuerst gespeichert; scheitert er, kommt `wpmcp_save_failed` und nichts ist geaendert.
- **Kein Schnitt mehr mitten in einem Umlaut.** Fenster und Ausschnitte wurden nach Bytes geschnitten: die Fenster von `content-preview` und `content-fetch-live`, der Kontext eines Treffers in `content-search`, die Bestaetigung nach `patch_html` (`patched`) und die Fundstellen von `content-fetch-live` mit `contains`. Traf der Schnitt das zweite Byte eines ae, oe, ue, ss oder eines Emoji, war die Antwort kein gueltiges UTF-8 und liess sich als Ganzes nicht ausliefern. Jetzt endet jedes Fenster vor einem Zeichen, das nicht mehr ganz hineinpasst, und das naechste beginnt damit. Die Offsets bleiben Byte-Offsets; ein Offset mitten in einem Zeichen beginnt bei diesem Zeichen, und `offset` in der Antwort sagt, wo.
- **`content-search` findet Umlaute und URLs in Block-Attributen.** Die Attribute wurden fuer die Suche mit den Standardoptionen als JSON kodiert, ein Name mit Umlaut also als `M\u00fcller` und eine URL als `https:\/\/`. Eine Suche nach einem Namen mit Umlaut oder nach einer URL in einem Attribut fand nichts und meldete das mit Ueberzeugung. Jetzt wird so kodiert, wie WordPress die Attribute speichert.
- **Muster 12 ist nicht Muster 123, Bild 1 nicht Bild 12.** Die Verwendung eines synchronisierten Musters wurde mit `"ref":12` gesucht und fand damit auch Seiten mit Muster 123 oder 1200; die Warnung im Probelauf nannte zu viele Seiten, und nach dem Speichern wurde deren Cache mitgeleert. Genauso zaehlte `wp-image-1` jede Seite mit Bild 12, 100 oder 1999 als Verwendung von Bild 1 (`usedIn` in `media-list` und `media-read`). Jetzt gilt nur die ganze Nummer.
- **Warnung und Cache-Leerung kennen dieselben Seiten.** Die Zahl im Probelauf und die Liste fuer die Cache-Leerung kamen aus zwei Abfragen: die eine zaehlte andere Muster mit und hatte keine Grenze, die andere liess sie weg und hoerte bei 200 auf. Jetzt ist es eine Abfrage (bis 2000 Seiten), und ein Muster in einem anderen Muster zaehlt in beiden mit.
- **Ein Update holt nach, was sonst nur die Aktivierung macht.** WordPress ruft den Aktivierungs-Hook bei einem Update nicht auf, weder beim Hochladen noch ueber den Update-Pruefer. Protokolltabelle und Rolle waren deshalb nur auf Seiten richtig, die die aktuelle Version frisch installiert hatten. Jetzt merkt sich das Plugin eine Schema-Version (Option `wpmcp_db_version`, derzeit 2) und gleicht bei Abweichung einmal Tabelle (`dbDelta`) und Rolle ab. Der Vergleich kostet pro Aufruf eine ohnehin geladene Option. Auf Multisite holt jede Seite das bei ihrem ersten Aufruf nach.
- **Eine geloeschte Rolle "AI Editor" wird wieder angelegt.** Der Abgleich in wp-admin und REST hat eine fehlende Rolle bisher bewusst uebersprungen. Ohne Rolle fehlt dem Agent-Konto das Marker-Recht, und jedes Werkzeug lehnt mit einem Rechtefehler ab, der nirgendwohin zeigt.
- **Ein Datenbankfehler beim Protokollieren landet nicht mehr in der Antwort.** Fehlte die Protokolltabelle und war `WP_DEBUG_DISPLAY` an, gab `wpdb` den Fehler als HTML aus, mitten in die JSON-Antwort des Werkzeugs. Das Protokoll schreibt jetzt mit unterdrueckten Fehlern und stellt die vorherige Einstellung danach wieder her.
- **Der Render-Test laesst keine Ausgabepuffer offen.** Vor dem Speichern wird das neue Markup einmal gerendert, in einem eigenen Puffer. Ein Block, der selbst einen Puffer oeffnet und dann wirft oder ihn einfach offen laesst, hinterliess Puffer, die die Antwort des Werkzeugs verschluckten. Jetzt geht der Test immer auf genau die Ebene zurueck, auf der er angefangen hat.
- **`content-create` legt eine Seite mit Inhalt ganz oder gar nicht an.** Der echte Aufruf legte die Seite zuerst an und pruefte den Inhalt danach. Jeder Baum mit unbekanntem Block, jedes `onerror`, jeder Meta-Schluessel ausserhalb der Liste hinterliess einen leeren Entwurf, und jeder neue Versuch einen weiteren. Jetzt laufen Baum und Meta vor dem Anlegen durch dieselbe Pruefung wie bei `content-write` (`wpmcp_plan_write`), im Probelauf und im echten Aufruf gleich; der Probelauf war bisher eine Handkopie dieser Pruefung. Abgelehnter Inhalt legt nichts an, und die Antwort hat keine `id`. Scheitert das Speichern trotzdem erst nach dem Anlegen (ein Speicherfilter eines anderen Plugins, die Datenbank), wird die gerade angelegte Seite endgueltig geloescht, und die Antwort sagt das. Ein synchronisiertes Muster legt `content-create` nur an, wenn Muster schreibbar sind.
- **`content-restore` leert den Cache und gibt den Stempel zurueck.** Nach dem Wiederherstellen zeigte die Seite fuer Besucher weiter den Stand, den der Restore rueckgaengig machen sollte, bei einem Muster auch auf jeder Seite, die es einbindet. Jetzt endet der Restore wie ein Schreibvorgang: Cache der Seite und der einbindenden Seiten geleert (`cache`), `modified` fuer den naechsten Schreibvorgang, und `savedRevisionId` nennt die Revision, die der Restore selbst hinterlassen hat.
- **`revisionId` nennt die Revision dieses Speicherns.** Gelesen wurde die neueste Revision des Beitrags. WordPress legt aber keine an, wenn sich am Inhalt nichts aendert (ein reiner Status- oder Slug-Wechsel), und dann nannte die Antwort eine aeltere Revision als Weg zurueck. Jetzt wird die Revision im Moment des Speicherns abgefangen; ohne neue Revision ist `revisionId` 0.
- **Das Protokoll sagt, was ein Schreibvorgang war.** Ein abgelehnter echter Schreibvorgang stand als Probelauf im Protokoll; jetzt als `rejected`. Ein reiner Meta-Schreibvorgang stand als `ops` und doppelt darin (einmal als `meta`, einmal als "Saved"); jetzt ist es ein Eintrag je Aufruf, mit `tree`, `ops`, `placement` oder `meta`, und die alten Meta-Werte und Status-, Slug- oder Elternwechsel stehen in diesem einen Eintrag. Die Zusammenfassung von `content-batch` nennt die gespeicherten Beitraege und, falls der Lauf abbrach, den Beitrag, an dem er stoppte.

- **`site-info` sagt nicht mehr "Publishing is never possible", wenn eine Arbeitssitzung laeuft.** Der Satz stand fest im Text, auch waehrend die Sitzung das Veroeffentlichen erlaubte; der Agent glaubt dem Satz eher als dem Feld daneben. Er wird jetzt aus dem Sitzungsstand gebaut. Ebenso in wp-admin (Zugriffsstufen und Einrichtung) und in der README.
- **`site-info` ordnet die Werkzeuge richtig zu.** `content-create`, `content-batch` und `media-update` standen unter `read`, weil die Liste der Schreibwerkzeuge drei Namen kannte. Beide Listen kommen jetzt aus derselben Quelle wie die Registrierung.
- **`content-restore` und jedes Speichern mit neuer Revision brachen mit einem PHP-Fehler ab.** `wp_get_post_revision()` nimmt sein Argument als Referenz, bekam aber einen Ausdruck (`(int) $revision_id`), und PHP 8 bricht dann ab, statt zu warnen. In `content-restore` stand das seit 0.4.0: Wiederherstellen ging auf keiner echten Seite. Seit `revisionId` die Revision des eigenen Speicherns nennt (siehe oben), traf es ausserdem jedes `content-write`, `content-batch` und `content-create`, das eine Revision anlegt, also fast jedes; gespeichert war dann schon, die Antwort aber ein Fehler. Die Nachbau-Tests nahmen das Argument als Wert und sahen nichts. Sie nehmen es jetzt als Referenz, wie WordPress.
- **JSON-LD in einem Custom-HTML-Block wird wieder gespeichert.** Seit 0.18.3 lehnte die Markup-Pruefung jeden geaenderten `core/html`-Block mit strukturierten Daten ab ("only rewritten"), also genau den ueblichen Ort fuer ein FAQ-Schema. Sie nahm das JSON-LD erst heraus, nachdem sie den Block samt Kommentar-Begrenzern zusammengesetzt hatte. Den so geleerten Block gibt kses als `<!-- wp:html /-->` zurueck, und das zaehlte als Aenderung. Frei stehendes JSON-LD war nicht betroffen; nur deshalb fiel es den Nachbau-Tests nicht auf.
- **Ein `&` vor einem Leerzeichen in Block-Attributen wird nicht mehr abgelehnt.** "Mueller & Soehne" in einem Attribut (bei einem Design-System mit Texten in Attributen fast jede Ueberschrift) lehnte die Markup-Pruefung seit 0.18.3 ab: kses filtert jeden Attributwert einzeln und gibt das `&` als `&amp;` zurueck. Im Markup galt dieselbe Umschreibung schon als gleichwertig, in Attributen jetzt auch. Ein `&` direkt vor Buchstaben und ein `<` bleiben abgelehnt.
- **Vorschau-Links zeigen abgemeldeten Besuchern den Entwurf.** Bisher bekamen sie eine 404, also genau die Leute, fuer die der Link gedacht ist. WordPress holt eine einzelne Seite unabhaengig von ihrem Status und wirft einen Entwurf erst danach fuer Abgemeldete wieder hinaus; das Plugin griff nur ein, wenn die Abfrage leer war, und das war sie nie. Jetzt gilt der Entwurf fuer genau diese Abfrage als veroeffentlicht, nur im Speicher. Private Seiten bleiben ausgenommen, abgelaufene oder fremde Tokens zeigen weiter nichts.

### API

Ab hier gibt es eine Vertragsversion: `site-info` meldet `contractVersion` (eine ganze Zahl, jetzt 1). Sie steigt nur, wenn sich ein bestehender Name, ein Feld oder eine Bedeutung so aendert, dass ein Client sich anpassen muss; neue Werkzeuge, Argumente oder Felder erhoehen sie nicht. Jede Erhoehung steht in diesem Abschnitt.

- **Jeder Fehler traegt seinen Code.** Der MCP-Adapter gibt von einem WordPress-Fehler nur die Meldung weiter, der Code kam nie beim Agenten an. Jetzt beginnt jede Meldung mit einem `wpmcp_*`-Code mit `[code] `, genau einmal, z.B. `[wpmcp_stale] Post 12 changed after you read it ...`. Eine Ablehnung mit Bericht (`"ok": false`) hat ein Feld `code`, meist `wpmcp_validation_failed`. Beides geschieht an einer Stelle, die jedes Werkzeug durchlaeuft. Alle Codes stehen in der README unter "Error codes"; ein Test prueft, dass keiner fehlt.
- **`content-batch`: jeder Posten hat dieselbe Form**, im Probelauf wie beim Speichern: `{ index, postId, ok, code, errors, warnings }`, gespeicherte Posten zusaetzlich `revisionId`, `modified` und bei Textaenderungen `patched`. Bisher meldete der Speicherlauf einen Grund als `error` (Text), der `null` blieb, wenn das Speichern mit einem Bericht statt einem Fehler abgelehnt wurde, und die Warnungen fehlten ganz. Bricht der Lauf ab, hat die Antwort den Code `wpmcp_batch_incomplete`. **Das Feld `error` gibt es nicht mehr**, der Grund steht in `errors`.
- **Die Werkzeugliste haengt nur an der eingestellten Stufe, nicht an der Uhr einer Arbeitssitzung.** Clients holen die Liste einmal beim Verbinden, und der Server meldet keine Aenderungen. `media-upload` erschien deshalb fuer einen schon verbundenen Agenten nie und war nach dem Ende einer Sitzung ein toter Name. Jetzt ist es auf jeder Schreibstufe registriert und lehnt ausserhalb einer Sitzung mit `wpmcp_session_required` ab (bisher `wpmcp_upload_needs_session`). Die Sicherheitsgrenze bleibt: hochgeladen wird nur in einer Sitzung, `upload_files` gilt nur fuer den einen Aufruf. Auf der Lesestufe fuegt eine Sitzung keine Werkzeuge mehr hinzu; wer dort arbeiten lassen will, stellt die Stufe auf "Entwuerfe".
- **Jedes Werkzeug traegt alle MCP-Hinweise.** `content-create`, `content-batch`, `media-update` und `media-upload` hatten keine; MCP liest einen fehlenden `destructiveHint` als ja, also galt das Anlegen eines Entwurfs als zerstoererisch. Jetzt sind `readOnlyHint`, `destructiveHint`, `idempotentHint` und `openWorldHint` ueberall gesetzt, aus einer Tabelle (`content-fetch-live` als einziges mit `openWorldHint`), und `show_in_rest` ist ueberall gleich.
- **Labels auf Englisch und uebersetzbar.** Die Namen der Abilities waren deutsch und fest im Code ("Seite anlegen"); jetzt englisch und ueber `__()`.

- **`content-search` blaettert ueber 500 Treffer hinaus.** Neues Argument `offset`; ist eine Antwort abgeschnitten, nennt sie `nextOffset`. Bisher war alles nach dem 500. Treffer unerreichbar. Ausserdem nimmt die Suche `post_type` als einzelnen Text (wie `content-list` und `content-create`) und `post_types` bzw. `post_status` auch als Text statt Liste; bisher wurde ein `post_type` stillschweigend ignoriert und die ganze Seite durchsucht.
- **`media-read` und `media-update` nehmen auch `post_id`.** Jedes andere Werkzeug nennt sein Ziel so, und ein Anhang ist ein Beitrag; geschickt wurde es aus Gewohnheit und endete in "No attachment with ID 0". `id` bleibt und hat Vorrang; fehlt beides, kommt `wpmcp_bad_request`.
- **`content-fetch-live` zaehlt JSON-LD auch im Body.** `head.jsonLdBlocks` zaehlte nur den `<head>`; ein FAQ-Schema, das `content-write` in einen HTML-Block schreibt, stand damit auf genau der Seite, die es auslieferte, als 0 da. Jetzt ist `jsonLdBlocks` die Summe, dazu `jsonLdInHead` und `jsonLdInBody`. **Die Bedeutung von `jsonLdBlocks` aendert sich damit** (vorher nur Kopf, jetzt ganze Seite).
- **`content-duplicate` sagt, dass es sofort schreibt** (es gibt keinen Probelauf; die Kopie ist immer ein Entwurf), und der Standardtitel heisst "Original (Copy)", uebersetzbar, statt immer "(Kopie)".

### Leistung und Betrieb

- **`content-fetch-live` antwortet in Fenstern von 60000 Bytes statt 200000**, wie `content-preview`. 200 KB am Stueck waren mehr, als ein Agent sinnvoll auf einmal liest, und dieselbe Seite kam je nach Werkzeug anders geschnitten zurueck. Beide Werkzeuge nutzen jetzt dieselbe Zahl (`WPMCP_WINDOW_BYTES`). `nextOffset` fuehrt wie bisher zum Rest.
- **Die Cache-Leerung meldet `object: "post cache cleared"` statt `"flushed"`.** Geleert werden nur die Eintraege des einen Beitrags (`clean_post_cache`), nicht der ganze Objekt-Cache; "flushed" klang nach dem Gegenteil.
- **`content-list` und `content-revisions` zaehlen Bloecke ohne Parser.** Fuer die Spalte `blocks` bzw. `blockCount` wurde jede Seite der Liste (bis 100) und jede Revision (bis 50) vollstaendig geparst. Jetzt zaehlt eine Stringsuche die oeffnenden Blockkommentare (`<!-- wp:`), verschachtelte und selbstschliessende Bloecke mit, schliessende nicht. Fuer Blockinhalt ist die Zahl dieselbe; klassischer HTML-Inhalt ausserhalb von Blockkommentaren zaehlt jetzt 0 statt 1. `content-read` zaehlt weiter ueber den geparsten Baum.
- **`content-search` laedt nur noch, was den Text enthalten kann, und in Portionen.** Bisher holte jede Suche alle Beitraege aller durchsuchbaren Typen auf einmal, mit Inhalt (`posts_per_page -1`), und suchte dann in PHP. Jetzt fragt sie zuerst nur IDs ab, bei einfachem Text schon mit `LIKE` auf den Inhalt, laedt dann 50 Beitraege auf einmal und hoert auf, sobald die Seite mit Treffern voll ist. Die Datenbank sucht dabei nur das laengste Stueck der Anfrage zwischen Zeichen, die WordPress in Block-Attributen anders speichert (`<`, `>`, `&`, `"`, `-`, `/`, `\`, Umlaute), damit keine Seite verloren geht, die die Suche selbst finden wuerde. Titel und Permalink werden je Beitrag einmal ermittelt statt je Treffer. Neues Feld `scanned` (gelesene Beitraege). `offset` und `nextOffset` bleiben, wie sie sind.
- **Regulaere Ausdruecke in `content-search` sind begrenzt.** Hoechstens 200 Bytes; je Suche hoechstens 5000 Beitraege bzw. 32 MB Inhalt (Filter `wpmcp_search_limits`). Ist die Grenze erreicht, kommen `truncated`, `scanLimitReached` und ein `hint`, wie die Suche einzugrenzen ist. Scheitert ein Ausdruck an einer Seite (z.B. Backtracking-Limit), kommt `wpmcp_bad_regex` mit der Seite statt still 0 Treffer.
- **Das Protokoll haelt sich an seine Aufbewahrungsfrist.** Der Activity-Tab nannte 90 Tage, geloescht wurde aber nie etwas: jeder Lesezugriff, Probelauf und jede Ablehnung blieb fuer immer in der Tabelle. Jetzt loescht ein taegliches WP-Cron-Ereignis (`wpmcp_prune_log`) aeltere Eintraege, nach Erstellungszeit und in Portionen von 5000 Zeilen, damit die Tabelle zwischendurch beschreibbar bleibt. Eingeplant wird es bei Aktivierung und Update und, falls es fehlt (z.B. von einem Cron-Aufraeumer entfernt), beim naechsten Aufruf von wp-admin; Deaktivieren und Loeschen melden es ab. Die Frist ist unter Werkzeuge > MCP Connector > Access einstellbar (0 bis 3650 Tage, 0 = fuer immer) und per Filter `wpmcp_log_retention_days` festzulegen.
- **Ein Muster auf sehr vielen Seiten haelt die Antwort nicht mehr auf.** Nach dem Speichern eines synchronisierten Musters wurde der Cache jeder einbindenden Seite (bis 2000) noch in derselben Anfrage geleert, je Seite ein Aufruf an das Cache-Plugin. Jetzt werden die ersten 200 sofort geleert und in `cache.alsoPurged` genannt wie bisher, der Rest kommt ueber WP-Cron in Portionen von 200 im Minutenabstand (Ereignis `wpmcp_purge_posts`); `cache.alsoScheduled` sagt, wie viele Seiten noch warten. Die Grenze von 200 ist eine Abwaegung, nicht gemessen: genug fuer jedes gewoehnliche Muster, klein genug fuer eine Anfrage.
- **`content-preview` rendert eine Seite einmal statt zweimal.** Der Render-Test vor der Ausgabe renderte die Seite und warf das Ergebnis weg, danach wurde fuer die Antwort noch einmal gerendert. Jetzt ist das Ergebnis des Tests die Vorschau. Nebenbei landet ein Block, der seine Ausgabe per `echo` statt per Rueckgabe liefert, nicht mehr ungepuffert mitten in der JSON-Antwort.
- **`content-batch` prueft jeden Posten einmal.** Ein echter Batch lief fuer jeden Posten zweimal durch die ganze Pruefung (Validierung, Markup-Waechter, Render-Test): im Probelauf, der den ganzen Lauf absichert, und beim Speichern noch einmal. Jetzt wird das Ergebnis des Probelaufs beim Speichern weiterverwendet, wenn der Beitrag seither unveraendert ist (gleiches `post_modified_gmt`); sonst wird neu geprueft, auf dem gespeicherten Stand. Enthaelt der Batch ein synchronisiertes Muster, ein Navigationsmenue, ein Template oder einen Template-Teil, wird alles neu geprueft: deren Speichern aendert, wie die anderen Seiten rendern. Rechte, Sperre durch einen Menschen und `expected_modified` werden beim Speichern weiter jedes Mal frisch geprueft.
- **Messwerte beim Debuggen.** Mit `WP_DEBUG` tragen die Antworten von `content-write` (auch im Probelauf) und `content-preview` ein Feld `debug`: Millisekunden je Abschnitt (`plan`, `save`, `afterSave` bzw. `render`) und `peakMemory` in Bytes. Ohne `WP_DEBUG` fehlt das Feld; der Filter `wpmcp_debug_timings` schaltet es unabhaengig davon ein oder aus.

### Zu beachten

- **Seiten ohne HTTPS verlieren die Verbindung.** Laeuft eine Seite (auch eine Entwicklungsseite) nur ueber HTTP und ist nicht als `local` erklaert, kann sich der Agent nicht mehr anmelden. Die Statustabelle unter Werkzeuge > MCP Connector zeigt das jetzt als Fehler.
- **Nach dem Update wird die gespeicherte Rolle zurueckgesetzt.** Beim ersten Aufruf von wp-admin oder der REST-API speichert die Rolle "AI Editor" nur noch `read` und `wpmcp_access`. Wer der Rolle per Rollen-Editor eigene Rechte gegeben hat (z.B. `upload_files`), findet sie danach nicht mehr; ein zusaetzliches Recht gehoert auf eine zweite Rolle des Kontos, die bleibt unberuehrt.
- **Neue Zeile "Application passwords" in der Statustabelle.** Sie sagt, ob die Seite Anwendungspasswoerter selbst anbietet oder ob der Connector sie nur fuer den Agenten wieder geoeffnet hat.
- **Wer den Agenten bisher ueber `/wp/v2` oder `wp-abilities/v1` angesprochen hat, bekommt jetzt 403.** Der vorgesehene Weg war immer der MCP-Endpunkt.
- **ACF-Feldgruppen und andere nicht-oeffentliche Post-Types sind in einer Arbeitssitzung nicht mehr von selbst offen.** Wer sie braucht, setzt den Haken unter Werkzeuge > MCP Connector.
- **Vorschau-Links aus der Zeit vor dem Update funktionieren nicht mehr.** Sie galten ohnehin nur 15 Minuten; ein neuer Aufruf von `content-preview` liefert einen neuen.
- **`content-list` antwortet bei unbekanntem Status oder Post-Type mit einem Fehler** statt mit einer Liste. `page` haelt jetzt immer `per_page` Eintraege (ausser auf der letzten Seite), und `total`/`pages` zaehlen auch mit `uses_block` nur die Treffer.
- **`content-create` antwortet bei abgelehntem Inhalt ohne `id`.** Bisher kam eine `id` mit dem Hinweis, an diese Seite zu schreiben. Jetzt gibt es diese Seite nicht; derselbe Aufruf mit korrigiertem Inhalt ist der Weg. Das Feld `contentError` entfaellt.
- **`content-restore` lehnt Revisionen ab, die der Agent selbst mit gefiltertem Markup gespeichert hat.** Solche Staende stellt ein Mensch im Editor wieder her (Revisionen vergleichen). Saubere Revisionen des Agenten gehen weiter.
- **`content-duplicate` lehnt Elemente mit "Execute PHP" ab** (`wpmcp_runs_code`), und Muster, solange Muster nicht schreibbar sind (`wpmcp_pattern_readonly`).
- **Neuer Wert `rejected` im Protokoll** (Spalte "operation") fuer echte Schreibvorgaenge, die abgelehnt wurden. Wer das Protokoll auswertet, sieht dort keine Probelaeufe mehr, die keine waren.

### Tests und Auslieferung

- **Ein Befehl fuer die ganze Testsuite: `bash tests/run-all.sh`.** Er holt den WordPress-Blockparser, wenn er fehlt, findet jede `tests/*.php` von selbst (Helfer wie `bootstrap.php` und `kses-stub.php` erkennt er daran, dass ein anderer Test sie einbindet) und laesst die Integrationspruefung gegen dbw-base-core laufen, wenn der Core da ist (`DBW_CORE_PATH` oder der Nachbarordner), sonst meldet er sie als uebersprungen. Ein neuer Test kann damit nicht mehr geschrieben und dann nie ausgefuehrt werden. Eine PHP-Warnung, ein Notice oder ein Deprecated zaehlt als Fehler, nicht nur eine fehlgeschlagene Pruefung.
- **CI auf GitHub** (`.github/workflows/tests.yml`): bei jedem Push und Pull Request auf PHP 8.1 (die kleinste unterstuetzte Version) und 8.4. Die Integrationspruefung wird dort uebersprungen, weil dbw-base-core nicht oeffentlich ist.
- **Release erst nach gruenen Tests.** Der Release-Workflow ruft dieselbe Testsuite auf, bevor er baut, und bricht ab, wenn Tag, `Version:` im Plugin-Kopf und `WPMCP_VERSION` nicht uebereinstimmen. Ausserdem prueft er das fertige ZIP: Ein `tests/`-Ordner oder die Zeichenketten `alert(1)` bzw. `document.cookie` irgendwo darin stoppen die Veroeffentlichung, weil eine Server-Firewall (z.B. ModSecurity) genau daran das ganze Plugin beim Hochladen ablehnt.
- **Blockparser fest angepinnt.** `tests/fetch-shim.sh` holt den Parser jetzt von einem festen Commit des WordPress-6.9-Branches statt vom jeweils neuesten Stand; ein anderer Branch oder Commit laesst sich weiter als Argument uebergeben. Die Dateien sind identisch mit dem bisherigen Stand.
- Fehlt der Parser, sagt `tests/bootstrap.php` jetzt, was zu tun ist ("Run tests/fetch-shim.sh first"), statt mit einem "Failed opening required" abzubrechen.
- **Eine zweite Testebene gegen echtes WordPress (`tests/wp-real`).** Die bisherigen Tests laufen gegen Nachbauten der WordPress-Funktionen und sehen nur, was deren Autor fuer WordPress hielt. Die neue Ebene laedt WordPress 6.9.8 mit dem SQLite-Drop-in 3.0.2 (kein MySQL noetig) und der WordPress-Testbibliothek derselben Version, aktiviert das Plugin so, wie WordPress es tut, und prueft nur, was die Nachbauten nicht sehen koennen: kses und die Markup-Pruefung, Rechte fuer einen einzelnen Save (auch wenn er abbricht) und was die gespeicherte Rolle haelt, Anwendungspasswoerter mit echten Filter-Prioritaeten, den REST-Zaun bei einer Anfrage mit echtem Anwendungspasswort, die registrierten Abilities je Stufe, Backslashes bis in die Datenbank, Vorschau-Links in der echten Hauptabfrage, Update und Deinstallation, `content-create` und die Bearbeitungssperre. `bash tests/wp-real/setup.sh` holt alles einmal (Pruefsummen fest hinterlegt) in einen von git ignorierten Ordner; nichts davon landet im `vendor/` des Plugins. `run-all.sh` laesst die Ebene mitlaufen, sobald sie eingerichtet ist (oder mit `WPMCP_REAL=1`), und sagt sonst, dass sie fehlt. In der CI laeuft sie als eigener Job auf PHP 8.1 und 8.4, mit zwischengespeicherten Downloads. Der erste Lauf fand vier Fehler, die alle Nachbau-Tests bestanden hatten (unter "Behoben").


### Intern

- **Aufgeraeumt, ohne Verhalten zu aendern.** `includes/content.php` (ueber 4000 Zeilen) ist auf zehn Dateien nach Zustaendigkeit verteilt und laedt sie nur noch (Dateiliste im Abschnitt "Architecture" der README). Die Werkzeuge stehen in einer Tabelle (`wpmcp_ability_definitions()`), Kategorie, Ausgabeschema und Rechtepruefung kommen an einer Stelle dazu. Doppelte Regeln stecken in je einer Funktion: welche geparsten Bloecke ein Pfad meint (`wpmcp_is_visible_block`), Probelauf als Standard (`wpmcp_is_dry_run`), wer einen Beitrag lesen darf (`wpmcp_user_can_read`). Die nicht mehr genutzte Musterliste fuer gefaehrliches Markup (`wpmcp_unsafe_additions`, `wpmcp_unsafe_message`) ist entfernt; seit 0.18.3 entscheidet kses selbst.

---

## [0.18.3] - 2026-10-01 (nicht einzeln veroeffentlicht, enthalten in 0.19.0)

Sicherheits-Hotfix. Bitte zeitnah einspielen.

### Sicherheit

- **Gespeichertes XSS auf der niedrigsten Zugriffsstufe geschlossen.** Ob ein Schreibvorgang am Inhaltsfilter von WordPress (kses) vorbei gespeichert wird, entschied bisher eine Liste ganzer Elemente: script, style, iframe, form, object, embed. Alles andere, was kses entfernt, also `<img onerror>`, `<a href="javascript:...">`, `<svg onload>`, `<details ontoggle>` oder ein `style` mit `url(javascript:...)`, galt als "bestehendes Markup schuetzen" und ging ungefiltert in die Datenbank. Jetzt entscheidet kses selbst, Block fuer Block: Ein Block, der Byte fuer Byte schon gespeichert war, behaelt sein Markup (dafuer gibt es den Weg ja, JSON-LD und Video-Embeds in anderen Bloecken ueberleben weiterhin). Jeder neue oder geaenderte Block muss aus `wp_kses_post` exakt so herauskommen, wie er hineingeht, sonst wird der Schreibvorgang abgelehnt. Gueltiges JSON-LD bleibt die eine Ausnahme, wie bisher.
- **Der erhoehte Speicherweg (Dynamic Data, `unfiltered_html` fuer einen Save) prueft genauso.** Er hatte eine eigene Liste regulaerer Ausdruecke, und `<svg/onload>`, `&#106;avascript:`, ein Tab mitten in `javascript:`, `xlink:href`, `<animate values>`, `<meta http-equiv="refresh">`, `<base href>` und `<link rel="stylesheet">` kamen daran vorbei. Jetzt gilt dieselbe kses-Pruefung wie ueberall, und sie greift schon im Probelauf, nicht erst beim echten Speichern.
- **Gleicher Waechter auf allen Wegen.** `content-write`, `content-batch` und `content-create` (auch im Probelauf) laufen durch dieselbe Pruefung. `content-restore` ebenfalls, wobei die Revision selbst als bekannter Stand zaehlt: Sie gehoert nachweislich zu diesem Beitrag und enthaelt, was WordPress damals gespeichert hat. `content-duplicate` kopiert weiter ungefiltert, weil der Agent dort nur eine ID liefert und keinen Inhalt.
- **Der Filter kommt verlaesslich zurueck.** Ein Helfer (`wpmcp_without_kses`) nimmt kses fuer genau einen Save heraus und setzt ihn in einem `finally` zurueck, auch wenn der Save abstuerzt. Zurueckgesetzt wird nur, was vorher wirklich da war: Ein Konto mit `unfiltered_html` bekommt keinen Filter dazu. Die Rechte-Vergabe fuer den erhoehten Save nutzt jetzt denselben Helfer wie das Veroeffentlichen in einer Arbeitssitzung.

### Zu beachten

- **Die Ablehnung nennt Blockpfad und Konstrukt**, z.B. `block 2 (onerror=)` oder `block 0.1 (<svg, onload=)`, und sagt, was zu tun ist: entfernen oder von einem Menschen im Editor einfuegen lassen. Der Entwickler-Filter `wpmcp_allow_filtered_markup` oeffnet die Tuer wie bisher fuer Reparaturen.
- **Strenger als vorher, auch bei Harmlosem.** Ein geaenderter Block, den kses nur umschreiben wuerde (ein `&` statt `&amp;`, Attribute ohne Anfuehrungszeichen, `<br/>`, Leerzeichen im `style`), wird ebenfalls abgelehnt. Die Meldung zeigt dann, wie WordPress den Block speichern wuerde; so geschickt geht er durch. Bisher landete so etwas ungefiltert in der Datenbank, und genau das war die Luecke.
- **Ein geaenderter Block mit bestehendem Embed** (z.B. ein HTML-Block mit iframe, in dem der Agent Text ergaenzt) ist jetzt ein Block des Agenten und wird abgelehnt. Solche Aenderungen macht ein Mensch im Editor.
- **Revisionen aus der Zeit vor 0.18.3** koennen Markup enthalten, das ueber die Luecke gespeichert wurde. `content-restore` stellt sie wieder her wie jeden anderen frueheren Stand.
- Die Test-Attrappe fuer `wp_kses_post` (`tests/kses-stub.php`) filtert jetzt wie kses: on*-Attribute auch nach `/`, javascript:/vbscript:/data: auch entity-kodiert oder mit Tabs, xlink:href, style, svg, meta, base, link. Die alte entfernte nur script und iframe und hat die Luecke deshalb nie gesehen. Neuer Test: `tests/markup-guard.php`.

---

## [0.18.2] - 2026-10-01

Ein Tippfehler in der Reihenfolge, der zwei gemeldete Fehler erklaert.

### Behoben

- **Ein Schreibvorgang mit nur Status, Slug oder Elternseite wurde abgelehnt.** Der Waechter fragte `$has_placement` ab, die Variable wurde aber erst darunter gesetzt. Also galt sie als leer, und `content-write` mit `{"status": "publish"}` kam mit genau der Meldung zurueck, die Slug und Status als gueltige Eingabe auflistet. Der Ausweg war, den kompletten Baum nochmal mitzuschicken: eine ueberfluessige Revision und auf grossen Seiten teuer.
- **`content-create` mit `status: publish` legte stillschweigend nur einen Entwurf an.** Dasselbe: Der Veroeffentlichungsschritt ruft intern den Schreibweg mit nur einem Status auf und lief in dieselbe Ablehnung. Die Antwort sagte zwar `ok: true`, aber `status: draft` und haengte die fremde Fehlermeldung an. Der Satz dazu hatte auch einen doppelten Punkt.
- **`blocks-describe` ohne `names`** nennt jetzt den Parameter beim Namen und zeigt ein Beispiel, statt nur "Provide at least one block name" zu sagen.

### Zu beachten

- Der Speicherweg konnte einen reinen Status-Wechsel die ganze Zeit korrekt: Er fasst `post_content` nicht an, verbraucht keine Revision und holt sich das Recht `publish_*` nur fuer diesen einen Aufruf. Er wurde nur nie erreicht.

---

## [0.18.1] - 2026-10-01

Nur Verpackung, am Plugin selbst aendert sich nichts.

### Behoben

- **Der Upload im WordPress-Backend scheiterte an der Server-Firewall.** Im GitHub-ZIP lag der komplette `tests`-Ordner, und darin stehen echte Angriffs-Strings als Testdaten (`<script>alert(1)</script>`, `document.cookie`) - genau dafuer sind sie da, sie beweisen, dass der Connector so etwas ablehnt. ModSecurity liest den Upload mit, sieht die Zeichenketten und lehnt das ganze Plugin mit 403 ab. Jetzt fliegt der Testordner per `.gitattributes` (`export-ignore`) aus jedem Archiv, das GitHub ausliefert.
- **Der Ordnername im Download stimmt jetzt.** Ein Tag loest einen Release-Workflow aus, der `wp-mcp-connector-plus.zip` baut (richtiger Ordnername, ohne Tests, ohne Doku, ohne Composer-Dateien) und ans Release haengt. Das Quellarchiv von GitHub trug die Version im Ordnernamen, WordPress legte das Plugin darunter an, und das naechste Update kam als zweite Kopie daneben.
- README sagt, welche Datei zu nehmen ist und was eine 403 beim Hochladen bedeutet, inklusive Ausweg ueber den Dateimanager.

---

## [0.18.0] - 2026-09-21

Aus zwei Praxisberichten von Agenten: weniger Nachpruefen, weniger Einzelaufrufe, strukturierte Daten schreibbar.

### Hinzugefuegt

- **`content-batch`**: dieselbe Aenderung auf bis zu 20 Beitraegen in einem Aufruf, statt zwanzig einzelner `content-write`. Jeder Posten laeuft zuerst im Probelauf; gespeichert wird nur, wenn alle bestehen. Probelauf ist Standard.
  - WordPress kennt keine Transaktion ueber mehrere Beitraege. Scheitert ein Posten beim Speichern, obwohl sein Probelauf bestand (die Seite wurde in der Zwischenzeit geaendert), stoppt der Lauf und sagt ehrlich, welche Beitraege gespeichert sind und welche nicht.
  - Jeder Beitrag bekommt seine eigene Revision, dort liegt sein Undo.
  - Derselbe Beitrag zweimal wird abgelehnt: der zweite Save faende die Seite schon veraendert.
- **`content-fetch-live` mit `contains`**: beantwortet "ist meine Aenderung auf der Seite?" mit gefunden, Anzahl, ob im Hauptinhalt oder nur in Kopf/Fuss, und dem Text um die ersten Treffer. Vorher kamen dafuer 200 KB HTML zurueck, die auf Platte geschrieben und durchsucht werden mussten.
- **`body_only`** an derselben Stelle: nur der Hauptinhalt, ohne Kopf, Fuss, Styles und Scripts (strukturierte Daten bleiben drin).
- **Strukturierte Daten (JSON-LD) sind schreibbar.** Jeder SEO-Artikel traegt sie, und bisher liessen sie sich nicht anlegen, weil sie in einem Script-Tag stehen. Browser fuehren JSON-LD aber nicht aus. Die einzige Gefahr ist ein `</script>` in den Daten, das den Tag frueh schliesst; deshalb wird jedes `<` in den Daten als `\u003C` gespeichert (JSON-Parser lesen es als dasselbe Zeichen). Sicheres JSON-LD bleibt Byte fuer Byte gleich.
  - Weiter abgelehnt: ein anderer `type`, ein zusaetzliches Attribut am Tag, Inhalt, der kein gueltiges JSON ist.
- **`patch_html` bestaetigt sich selbst**: die Antwort zeigt unter `patched` jeden geaenderten Block an der Stelle des neuen Texts. Der Kontrollaufruf danach entfaellt.
- **Warnung fuer `core/image` mit `<img>` ohne Quelle**: das rendert als kaputtes Bild. Der richtige Platzhalter ist `core/image` ganz ohne Markup und URL.

### Geaendert

- **Der Probelauf von `content-create` prueft den Baum und die Meta.** Bisher bestaetigte er nur Titel, Slug und Status; ein Baum mit 75 Bloecken bekam "ok", ohne angesehen worden zu sein, und der echte Aufruf legte die Seite dann an. Jetzt laeuft dieselbe Validierung wie beim Schreiben, mit Fehlern, Warnungen und Blockzahl.
- **Synced Patterns leeren den Cache der Seiten, die sie einbetten.** Das Pattern selbst hat keinen Cache, den ein Besucher sieht; die Seiten darum herum zeigten weiter die alte Fassung. Die geleerten Seiten stehen unter `cache.alsoPurged`.
- **uniqueId-Warnung deutlicher**: keine ID erfinden. Eine vorhandene Instanz lesen, ihre Form kopieren und die ID an allen drei Stellen gleichzeitig aendern (Attribut, Klasse, CSS).

### Behoben

- **Meta als Map aus Texten wurde abgelehnt**, wenn sie als Objekt ankam: `{"rank_math_title": "..."}` sah fuer die Payload-Pruefung aus wie eine per Komma zerlegte Liste. Zerlegte Reste sind aber immer eine nummerierte Liste, nie eine Map mit Namen. Gefunden beim Test des neuen Probelaufs.

### Bewusst nicht gebaut

- **uniqueId automatisch erzeugen.** Die ID haengt an generiertem CSS und an einer Klasse im Markup, die der Konnektor nicht kennt. Eine erfundene ID passt zu keinem der beiden, und die Seite saehe im Editor sauber aus und im Frontend kaputt.

---

## [0.17.0] - 2026-09-15

Die beiden offenen Entscheidungen aus 0.16.0, getroffen: Veroeffentlichen und Bild-Upload - beides nur waehrend einer Arbeitssitzung.

### Hinzugefuegt

- **Veroeffentlichen in einer Arbeitssitzung.** `status: publish` ist in `content-write` und `content-create` setzbar, solange eine Sitzung offen ist. Die Grundregel war nie "22 Mal auf Veroeffentlichen klicken", sondern "ein Mensch entscheidet, ob etwas live geht". Wer eine Sitzung oeffnet, trifft genau diese Entscheidung, einmal.
  - Ausserhalb einer Sitzung veroeffentlicht kein Status, auf keiner Stufe. Das Recht `publish_*` wird fuer den einen Speichervorgang vergeben und in einem `finally` wieder entzogen, nie an der Rolle.
  - Eine mit `status: publish` angelegte Seite geht erst live, wenn ihr Inhalt geschrieben ist. Wird der Inhalt abgelehnt, bleibt sie Entwurf.
  - Eine Live-Seite auf Entwurf zurueckzunehmen bleibt gesperrt, auch in der Sitzung: das ist eine Entfernung.
- **`media-upload`**, das Werkzeug existiert nur waehrend einer Sitzung. Datei base64-kodiert, Antwort mit ID und URL fuer den Bildblock.
  - Entschieden wird am Inhalt, nicht am Namen: nur JPEG, PNG, WebP. Ein PNG namens `shell.php` wird `shell.png`, ein SVG namens `foto.jpg` wird abgelehnt.
  - SVG grundsaetzlich abgelehnt (kann Script tragen), ebenso jede Datei mit PHP-Oeffnungstag.
  - Groessenlimit 8 MB, per Filter `wpmcp_max_upload_bytes`.
  - Alt-Text ist Pflicht, oder `decorative: true` fuer ein rein dekoratives Bild.
  - `upload_files` nur fuer den einen Aufruf, nie an der Rolle. Probelauf ist Standard.
- `site-info` meldet `capabilities.workSession`, damit der Agent weiss, ob Veroeffentlichen und Upload gerade moeglich sind.

### Bewusst nicht gebaut

- **URL-Import.** Der Server wuerde eine Adresse abrufen, die der Agent vorgibt, inklusive interner Adressen im Netz des Hosters (SSRF). Die Fotos liegen ohnehin lokal, und Claude Code kann sie direkt schicken.

---

## [0.16.0] - 2026-09-15

Aus dem Bericht zur Ersteinrichtung von berlin-marine.de. Drei der fuenf Punkte gab es schon (content-create, Slug/Parent/Status seit 0.13.0, kompaktes blocks-describe seit 0.12.0) - der Bericht war gegen eine aeltere Version geschrieben. Neu war der Footer als GeneratePress-Element.

### Hinzugefuegt

- **Plugin-Meta auf ihrem eigenen Post-Type.** Ein GP-Element ist ohne seine Meta-Felder eine leere Huelle: gespeichert, aber nirgends angezeigt. Auf `gp_elements` ist jetzt `_generate_*` les- und schreibbar, auch als Array. Nur dort, nie auf einer Seite. Weitere Post-Types per Filter `wpmcp_post_type_meta_prefixes`.
- `content-read` mit `include_meta` liefert diese Felder unter `plugin`. Das ist der vorgesehene Weg: ein bestehendes Element lesen und Schluessel und Form uebernehmen. Die Schluessel unterscheiden sich je nach GP-Version, und die im Bericht vorgeschlagenen (`_generate_element_location`, Regel `entire_site`) sind geraten - mit denen waere das Element wieder nirgends erschienen.

### Sicherheit

- **Kein Meta-Schluessel, der Code ausfuehren kann, ist schreibbar**, auch wenn das Praefix passt. GeneratePress hat fuer Hook-Elemente einen Schalter "Execute PHP", der den Inhalt bei jedem Seitenaufruf als PHP auswertet.
- **Ein Element mit eingeschaltetem Execute PHP ist gar nicht schreibbar.** Das war schon seit 0.11.0 eine Luecke, sobald jemand `gp_elements` freigab: Der Script-Guard sucht nach Markup, das im Browser laeuft - eine Zeile PHP ist fuer ihn gewoehnlicher Text. Den Inhalt eines solchen Elements zu schreiben hiesse, Code auf den Server zu schreiben.

### Bewusst nicht gebaut

- **Status `publish`**, auch nicht auf der Vollstufe. "Veroeffentlichen bleibt beim Menschen" war eine Grundentscheidung von Anfang an, und die kippe ich nicht in einem Feature-Request. Offen zur Entscheidung.
- **Bild-Upload** - weiterhin offen zur Entscheidung, siehe 0.12.0.

---

## [0.15.1] - 2026-09-11

Auf Nachfrage: Eine Arbeitssitzung soll auch die Post-Types mitbringen, sonst ist es kein Dev-Modus.

### Geaendert

- **Eine Sitzung oeffnet jetzt auch die Post-Types** - Header, Templates, Feldgruppen, alles was die Seite sonst registriert. Der Einwand war richtig: eine Stunde Arbeit darf nicht mit dreissig Haken anfangen.
- **Ausser denen mit Kundendaten.** Bestellungen, Abos, Formulareingaenge, Buchungen bleiben draussen. Kein Zeitfenster macht das Lesen fremder Adressen zum Nebeneffekt einer Seitenbearbeitung - das bleibt ein Haken, den jemand bewusst setzt. Ein bereits gesetzter Haken zaehlt weiterhin, auch waehrend einer Sitzung: das war eine Entscheidung.
- Nach Ablauf faellt die Erweiterung weg wie alles andere auch.

---

## [0.15.0] - 2026-09-11

Zwei Komfort-Wuensche, einer davon mit Sicherheitsgewinn.

### Hinzugefuegt

- **Arbeitssitzung.** Ein Knopf oben auf der Einstellungsseite oeffnet alles fuer 1, 4 oder 8 Stunden: Veroeffentlichtes bearbeitbar, Muster schreibbar, Dynamic Data erlaubt. Danach faellt alles auf die gespeicherten Einstellungen zurueck, und die Rollenrechte werden mitgezogen.
  - Das ist **sicherer als der Ist-Zustand**, nicht lockerer. Die weiten Einstellungen sind die, die jemand fuer einen Nachmittag anschaltet und nie wieder aus - die Seite steht dann dauerhaft auf der weitesten Stufe, also genau dem, was die Einstellungen verhindern sollten. Das Schliessen ist jetzt ein Zeitstempel statt einer Erinnerung.
  - Das Fenster schliesst sich auch dann, wenn niemand ins Backend geht: die Stufe wird ohnehin bei jedem Request frisch gelesen, und die gespeicherten Rollenrechte raeumt der Konnektor an seinem eigenen Endpunkt auf.
  - **Was eine Sitzung nie anfasst:** die Post-Types (welche Inhalte im Zugriff sind, ist eine Entscheidung ueber die Seite, kein Risikofenster - und auf einem Shop stehen dort fremde Bestellungen) und das Veroeffentlichen, das kein Schalter in diesem Plugin je erreichen konnte.
- **"Alle auswaehlen" bei den Post-Types**, ab sechs Eintraegen, mit Zaehler und Zwischenzustand fuer halb ausgewaehlt. Auf einem WooCommerce-Shop stehen dort dreissig Haken.
- **Post-Types mit Kundendaten sind markiert.** Bestellungen, Abos, Formulareingaenge, Buchungen: rot gekennzeichnet, mit einem Satz darunter, was das Anhaken bedeutet. Das aendert nichts an dem, was erlaubt ist - es sorgt nur dafuer, dass ein Haken an `shop_order` nicht aussieht wie ein Haken an `gp_elements`. Gerade mit einem "Alle auswaehlen" daneben ist das keine Kosmetik.

---

## [0.14.0] - 2026-09-11

Auf die Frage, warum der Konnektor so viele Token verbraucht. Gemessen statt geschaetzt - und das meiste davon war Wiederholung, nicht Sicherheit.

### Geaendert

- **Eine Warnung, die achtzigmal dasselbe sagt, steht jetzt einmal da.** Eine Seite mit vierzig gleichartigen Karten erzeugte vierzig identische Warnungen: **2.577 Tokens desselben Satzes, in jedem Probelauf und jedem Schreibvorgang**. Zusammengefasst sind es 39 - mit Anzahl und drei Beispielpfaden, also allem, was zum Handeln noetig ist. Kommt ein Problem nur wenige Male vor, steht es weiterhin einzeln da; es geht um Wiederholung, nicht um Details.
- **Die groesste Werkzeugbeschreibung war ueber zwoelf Releases angewachsen** und erklaerte manches zweimal. `content-write` von 2.488 auf 1.633 Zeichen (622 auf 408 Tokens), alle sechzehn zusammen von ~2.368 auf ~2.154 Tokens. Jede Regel ist noch drin, nur die Begruendungen sind kuerzer - die Beschreibungen steuern das Verhalten des Agenten, das ist kein Ort zum Sparen um jeden Preis.

### Behoben

- **Fehlalarm bei Listenpunkten.** Die Warnung "Container ohne Bloecke" traf jeden Block, der `allowedBlocks` deklariert und Text statt Bloecke enthaelt - ein `core/list-item` deklariert das nur, damit Listen sich verschachteln lassen. Auf einer echten Seite waren das achtzig falsche Warnungen, und sie machten den Loewenanteil der Antwort aus. Ein Container mit Text gilt nicht mehr als leer.

### Dazu

- Die Testsuite `shipped-files` prueft jetzt auch das Kontextbudget: alle Beschreibungen zusammen unter 11.000 Zeichen, keine einzelne ueber 2.000. Damit waechst das nicht wieder still zu.

### Was nicht teuer ist

Die Sicherheitsstufen. Sie laufen in PHP auf dem Server und kosten **null Tokens**: die fuenfstufige Validierung, der Berechtigungsvergleich, die kses-Analyse, der Guard gegen Scripts. Teuer war, was der Konnektor *sagt*, nicht was er *prueft*.

---

## [0.13.1] - 2026-09-11

Aus dem ersten Aktivierungsversuch auf einer Shop-Seite: Die ganze Seite lag im Fatal Error, und zwar in WooCommerce Germanized.

### Behoben

- **Das Plugin lieferte einen halben Jetpack-Autoloader aus.** Der mcp-adapter haengt von `automattic/jetpack-autoloader` ab, und dessen Manifeste (`vendor/composer/jetpack_autoload_*.php`) lagen im Paket - waehrend das Composer-Plugin, das `vendor/autoload_packages.php` erzeugt, bewusst abgeschaltet ist. Damit war die Haelfte da, die anmeldet ("ich habe Autoloader-Version 5.0.23"), und die Haelfte fehlte, die jemand laden kann.
  - Jedes Plugin mit demselben Autoloader - Jetpack, WooCommerce, Germanized - durchsucht die aktiven Plugins, liest `jetpack_autoload_classmap.php`, haelt unseren fuer den neuesten und laedt `vendor/autoload_packages.php`. Die Datei gab es nie. Fatal Error bei **jedem Request**, nicht nur im Backend.
  - Auf Seiten ohne ein solches Plugin faellt es nicht auf - deshalb lief es auf navok.org und dbw-media.de monatelang unauffaellig.
- Die Manifeste sind raus, stehen in der `.gitignore` und werden von einem `post-install-cmd` entfernt, falls ein `composer install` sie neu erzeugt. Der gewoehnliche Composer-Autoloader (`vendor/autoload.php`) traegt unveraendert; er hat die Jetpack-Dateien nie gebraucht.
- Neue Testsuite `shipped-files`, die prueft, was das Paket an andere Plugins meldet.

### Wenn eine Seite gerade haengt

Ordner `wp-content/plugins/wp-mcp-connector-plus*` per FTP oder Dateimanager umbenennen. WordPress deaktiviert das Plugin dann von selbst und die Seite laeuft sofort wieder. Danach 0.13.1 installieren.

---

## [0.13.0] - 2026-09-10

Aus dem naechsten Rueckmeldebericht: 22 Seiten gebaut, danach 22 Slugs und Elternteile von Hand in wp-admin korrigiert.

### Hinzugefuegt

- **`content-create`.** Legt eine Seite mit Titel, Slug, Elternteil und Status an - und schreibt den Inhalt im selben Aufruf, wenn `tree` und `meta` mitkommen. Bisher war Duplizieren der einzige Weg zu einer neuen Seite: ein Duplikat erbt den Elternteil der Vorlage und bekommt einen aus dem alten Titel abgeleiteten Slug, beides danach Handarbeit. Immer ein Entwurf, egal was angefragt wird. Schlaegt das Schreiben fehl, nachdem die Seite existiert, sagt die Antwort das mitsamt der ID - damit niemand die Seite ein zweites Mal anlegt.
- **`content-write` nimmt `slug`, `parent` und `status`.** Damit ist eine Seite in einem Aufruf fertig: Blockbaum, SEO-Meta und Einordnung.

### Geaendert

- **Die Grenze verlaeuft jetzt an der Seite, nicht am Feld.** Bisher galt "Slug, Status und Post-Type werden nie angefasst". Der Grund dafuer stimmte fuer genau einen der beiden Faelle: Bei einer **veroeffentlichten** Seite ist der Slug das, worauf jeder Link zeigt, der Elternteil steckt im URL-Pfad, und ein Zurueckstufen auf Entwurf nimmt sie von der Seite - alles drei bleiben gesperrt und verweisen auf den Editor, wo die Weiterleitung in deiner Hand liegt. Eine Seite, die **nie veroeffentlicht war**, hat keines dieser Probleme: keine bekannte URL, keine Links darauf. Genau die hat der Agent gerade selbst angelegt.
- Geprueft wird dabei mehr als die Berechtigung: der Elternteil muss existieren, denselben Post-Type haben, darf nicht die Seite selbst sein und keinen Kreis bilden. Ein Kreis haenge den Zweig sonst aus dem Baum aus.
- **Veroeffentlichen bleibt unmoeglich.** Setzbar sind nur `draft` und `pending`; `publish`, `future` und `private` werden abgelehnt. Post-Type ist weiterhin gar nicht schreibbar.

---

## [0.12.0] - 2026-09-09

Aus dem Rueckmeldebericht einer KI, die den Konnektor eine komplette Startseite hat bauen lassen.

### Geaendert

- **`blocks-describe` ist jetzt standardmaessig kompakt.** Zehn Bloecke ergaben 54,8 KB - etwa ein Drittel eines Arbeitskontextes fuer eine einzige Werkzeugantwort, und fast alles davon Fliesstext zu Attributen, deren Name und Enum schon alles sagen. Kompakt laesst die Beschreibungen und das Beispiel weg und behaelt Typ, erlaubte Werte und Default. Gemessen am echten dbw-base-core: **42,6 KB voll gegen 17,8 KB kompakt, 58 % gespart.** `detail: "full"` gibt es weiterhin - fuer den einen Block, bei dem etwas unklar ist, statt fuer zehn.

### Behoben

- **Subtree-Pfade sind die echten.** Wer `paths: ["0","1","2","14"]` las, bekam vier Teilbaeume zurueck, die alle `"path": "0"` behaupteten - der Baumbauer zaehlte unabhaengig davon, wo der Block wirklich sass. Die Zuordnung lief dann ueber die Reihenfolge in der Antwort. Jetzt meldet jeder Teilbaum den Pfad, unter dem er auch beschreibbar ist.

### Hinzugefuegt

- **`content-fetch-live` liefert den ausgewerteten `head`**: Titel, Description, Canonical, Robots, Open Graph und die Anzahl der JSON-LD-Bloecke. Das ist der einzige Weg zu pruefen, ob ein geschriebenes SEO-Feld tatsaechlich auf der Seite ankommt - `content-read` beweist nur, dass der Wert gespeichert ist, und `content-preview` rendert den Body. Nur der Kopf wird ausgewertet, ein Meta-Tag im Fliesstext zaehlt nicht.
- **`site-info` nennt die aktiven Plugins, die fuer Inhaltsarbeit zaehlen** - SEO, Formulare, Block-Bibliotheken, Page-Builder, Caching, Mehrsprachigkeit, Shop, Rechtstexte. Kein Inventar: welches SEO-Plugin laeuft entscheidet, welche Meta-Felder es gibt, und ein Caching-Plugin entscheidet, ob eine Live-Pruefung ueberhaupt aussagekraeftig ist. Ohne das leitet ein Agent all das aus Fehlschlaegen ab.

---

## [0.11.0] - 2026-09-03

Nachtrag zu 0.10.0, auf Nachfrage: Warum eigentlich PHP in der functions.php, wenn es auch ein Haken sein koennte?

### Hinzugefuegt

- **Einstellung "Additional post types".** Listet jeden Post-Type, den die Seite hat und der nicht ohnehin im Zugriff ist - die globalen Bausteine des Themes (Header, Footer, Hooks, Inhaltsvorlagen) darunter. Standard: nichts angehakt. Der Haken ist eine echte Entscheidung, weil eine Aenderung dort auf jede Seite gleichzeitig wirkt, wie bei einem Synced Pattern.
  - Ein Post-Type, dessen Plugin deaktiviert wird, faellt still wieder raus statt einen toten Eintrag zu hinterlassen.
  - Bereits Angehaktes bleibt in der Liste, auch wenn es dadurch im Zugriff ist - sonst liesse es sich nicht wieder abwaehlen.
- Der Filter `wpmcp_allowed_post_types` bleibt daneben, fuer alles, was ein Projekt im Code entscheidet. Die Aufteilung: Einstellung fuer die Entscheidung pro Kundenseite, Filter fuer die pro Projekt.

---

## [0.10.0] - 2026-09-03

Aus zwei Berichten von der Datenschutzseite: Ein 32-KB-Rechtstext liess sich nicht in einem Aufruf schreiben, und die globalen Bausteine des Themes waren nicht erreichbar.

### Behoben

- **Grosse Argumente kommen jetzt an.** Der Fehler lautete `input[ops][0] ist nicht vom Typ object` und klang nach einer kaputten Operation. Er war etwas anderes: Ab einer bestimmten Groesse reicht der Client das Argument als JSON-*Text* durch, und WordPress' REST-Schicht zerlegt einen Skalar, wo sie eine Liste erwartet, an den Kommas (`rest_is_array` -> `wp_parse_list`). Element 0 ist dann ein Textfragment - die Meldung stimmt woertlich und fuehrt nirgendwohin. `ops`, `tree` und `meta` akzeptieren jetzt auch Text und dekodieren ihn, und das Schema besteht nicht mehr auf einer Liste, was die Zerlegung ueberhaupt erst ausgeloest hat.
  - Der Workaround waren 13 Platzhalter-Bloecke und 15 aufeinanderfolgende Schreibvorgaenge fuer eine Seite, mit dem Textfluss ueber die Chunk-Grenzen von Hand.
  - **Nicht** geflickt wird eine bereits zerlegte Liste. Ein Zusammenfuegen an Kommas wuerde aus "Komma, Punkt" ein "Komma,Punkt" machen - eine stille Aenderung am Kundentext ist schlimmer als jede Fehlermeldung. Stattdessen wird sie mit Angabe der Ursache abgelehnt.

### Geaendert

- **Ein Schreibvorgang gibt den neuen `modified`-Zeitstempel zurueck.** Bisher brauchte jede Folgeaenderung ein `content-read` dazwischen, nur um diesen einen Wert zu holen - was die Anzahl der Aufrufe verdoppelte. Die Alternative war, `expected_modified` wegzulassen und damit den Schutz gegen paralleles Bearbeiten aufzugeben.
- **Ein nicht unterstuetzter Post-Type nennt den Filter**, mit dem er sich freischalten laesst, statt nur "wird vom Konnektor nicht angeboten" zu melden.

### Dokumentiert statt eingebaut

`gp_elements` (GeneratePress Elements: Header, Footer, Hooks, Inhaltsvorlagen) kommt nicht per Default dazu. Diese Bausteine sind nicht `public`, und eine Aenderung daran wirkt auf jede Seite gleichzeitig - dieselbe Risikoklasse wie ein Synced Pattern, das deshalb eine eigene Einstellung hat. Das Rezept steht jetzt in der README:

```php
add_filter( 'wpmcp_allowed_post_types', function ( $types ) {
    $types[] = 'gp_elements';
    return $types;
} );
```

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
