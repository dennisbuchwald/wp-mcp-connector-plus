# Changelog

Alle wesentlichen Aenderungen am WP MCP Connector Plus werden hier dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/)
und dieses Projekt verwendet [Semantic Versioning](https://semver.org/lang/de/).

---

## [Unreleased]

### Sicherheit

- **Das Agent-Konto erreicht nur noch seinen MCP-Endpunkt.** Ein Anwendungspasswort gilt fuer die ganze REST-API, nicht fuer eine Route. Mit dem Zugang des Agenten ging deshalb auch `/wp/v2`: das eigene Profil, die eigenen Anwendungspasswoerter, die Medienbibliothek und jede Route, die irgendein anderes Plugin anmeldet, alles an den Pruefungen und am Protokoll des Connectors vorbei. Jetzt bekommt jede REST-Anfrage eines Kontos mit der Rolle "AI Editor" ausser an `/wpmcp/v1/mcp` eine 403 mit Erklaerung. Interne REST-Aufrufe waehrend eines Werkzeugs sind nicht betroffen, ein Administrator mit dem Marker-Recht zum Debuggen ebenfalls nicht.
- **Kein XML-RPC fuer das Agent-Konto.** Anwendungspasswoerter gelten auch dort; die Anmeldung wird jetzt abgelehnt.
- **Das Agent-Konto kann sich selbst nicht mehr verwalten.** WordPress erlaubt jedem Konto, das eigene Profil zu bearbeiten und eigene Anwendungspasswoerter anzulegen, ohne jedes Recht. Ein abgeflossener Zugang haette so weitere Zugaenge erzeugen oder die E-Mail-Adresse (und damit den Passwort-Reset) aendern koennen. `edit_user`, `promote_user`, `create_app_password`, `edit_app_password`, `delete_app_password(s)` und die uebrigen Konto-Rechte sind fuer das Agent-Konto jetzt immer gesperrt, an sich selbst und an anderen. Ein Administrator legt dem Agenten weiter ueber die Einrichtung ein Passwort an.
- **Anwendungspasswoerter nur noch ueber HTTPS.** Das Plugin schaltete sie global ein, auch ohne HTTPS, und ueberging damit die Pruefung von WordPress selbst. Jetzt oeffnet es sie nur dort wieder, wo WordPress sie selbst zulassen wuerde: ueber HTTPS oder in einer als `local` erklaerten Umgebung.
- **Rechte haengen nicht mehr an der gespeicherten Rolle.** Die Stufe und eine Arbeitssitzung wurden als Rechte auf die Rolle geschrieben. Lief eine Sitzung nachts ab, blieb die Rolle weit offen, bis jemand wp-admin oeffnete oder der Agent den MCP-Endpunkt rief; jeder andere Weg (z.B. `/wp/v2`) sah die alten Rechte. Jetzt speichert die Rolle nur noch Lesen und das Marker-Recht, und was eine Stufe oder Sitzung dazugibt, wird bei jeder Rechtepruefung frisch berechnet (`user_has_cap`). Eine abgelaufene Sitzung ist damit in derselben Sekunde zu. Rechte, die eine aeltere Version oder ein Rollen-Editor auf der Rolle abgelegt hat, zaehlen fuer ein Konto mit nur der Agent-Rolle nicht mehr.
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

### Behoben

- **Menschen behalten ihre Anwendungspasswoerter.** Fuer jedes menschliche Konto lieferte das Plugin `false`, auch auf Seiten, die die Funktion nie abgeschaltet hatten. Das brach die WordPress-App und Automationen (n8n und aehnliche). Jetzt bekommt jedes menschliche Konto genau das, was es ohne das Plugin haette: aus, wo die Haertung des Themes oder ein Sicherheits-Plugin sie fuer alle abgeschaltet hat, sonst das, was WordPress und die anderen Plugins fuer dieses Konto entscheiden.

### Zu beachten

- **Seiten ohne HTTPS verlieren die Verbindung.** Laeuft eine Seite (auch eine Entwicklungsseite) nur ueber HTTP und ist nicht als `local` erklaert, kann sich der Agent nicht mehr anmelden. Die Statustabelle unter Werkzeuge > MCP Connector zeigt das jetzt als Fehler.
- **Nach dem Update wird die gespeicherte Rolle zurueckgesetzt.** Beim ersten Aufruf von wp-admin oder der REST-API speichert die Rolle "AI Editor" nur noch `read` und `wpmcp_access`. Wer der Rolle per Rollen-Editor eigene Rechte gegeben hat (z.B. `upload_files`), findet sie danach nicht mehr; ein zusaetzliches Recht gehoert auf eine zweite Rolle des Kontos, die bleibt unberuehrt.
- **Neue Zeile "Application passwords" in der Statustabelle.** Sie sagt, ob die Seite Anwendungspasswoerter selbst anbietet oder ob der Connector sie nur fuer den Agenten wieder geoeffnet hat.
- **Wer den Agenten bisher ueber `/wp/v2` oder `wp-abilities/v1` angesprochen hat, bekommt jetzt 403.** Der vorgesehene Weg war immer der MCP-Endpunkt.
- **ACF-Feldgruppen und andere nicht-oeffentliche Post-Types sind in einer Arbeitssitzung nicht mehr von selbst offen.** Wer sie braucht, setzt den Haken unter Werkzeuge > MCP Connector.
- **Vorschau-Links aus der Zeit vor dem Update funktionieren nicht mehr.** Sie galten ohnehin nur 15 Minuten; ein neuer Aufruf von `content-preview` liefert einen neuen.
- **`content-list` antwortet bei unbekanntem Status oder Post-Type mit einem Fehler** statt mit einer Liste. `page` haelt jetzt immer `per_page` Eintraege (ausser auf der letzten Seite), und `total`/`pages` zaehlen auch mit `uses_block` nur die Treffer.

### Tests und Auslieferung

- **Ein Befehl fuer die ganze Testsuite: `bash tests/run-all.sh`.** Er holt den WordPress-Blockparser, wenn er fehlt, findet jede `tests/*.php` von selbst (Helfer wie `bootstrap.php` und `kses-stub.php` erkennt er daran, dass ein anderer Test sie einbindet) und laesst die Integrationspruefung gegen dbw-base-core laufen, wenn der Core da ist (`DBW_CORE_PATH` oder der Nachbarordner), sonst meldet er sie als uebersprungen. Ein neuer Test kann damit nicht mehr geschrieben und dann nie ausgefuehrt werden. Eine PHP-Warnung, ein Notice oder ein Deprecated zaehlt als Fehler, nicht nur eine fehlgeschlagene Pruefung.
- **CI auf GitHub** (`.github/workflows/tests.yml`): bei jedem Push und Pull Request auf PHP 8.1 (die kleinste unterstuetzte Version) und 8.4. Die Integrationspruefung wird dort uebersprungen, weil dbw-base-core nicht oeffentlich ist.
- **Release erst nach gruenen Tests.** Der Release-Workflow ruft dieselbe Testsuite auf, bevor er baut, und bricht ab, wenn Tag, `Version:` im Plugin-Kopf und `WPMCP_VERSION` nicht uebereinstimmen. Ausserdem prueft er das fertige ZIP: Ein `tests/`-Ordner oder die Zeichenketten `alert(1)` bzw. `document.cookie` irgendwo darin stoppen die Veroeffentlichung, weil eine Server-Firewall (z.B. ModSecurity) genau daran das ganze Plugin beim Hochladen ablehnt.
- **Blockparser fest angepinnt.** `tests/fetch-shim.sh` holt den Parser jetzt von einem festen Commit des WordPress-6.9-Branches statt vom jeweils neuesten Stand; ein anderer Branch oder Commit laesst sich weiter als Argument uebergeben. Die Dateien sind identisch mit dem bisherigen Stand.
- Fehlt der Parser, sagt `tests/bootstrap.php` jetzt, was zu tun ist ("Run tests/fetch-shim.sh first"), statt mit einem "Failed opening required" abzubrechen.

---

## [0.18.3] - 2026-10-01

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
