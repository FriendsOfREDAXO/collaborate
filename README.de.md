# Collaborate - Zusammenarbeit im Backend & mehr

![Screenshot](https://github.com/FriendsOfREDAXO/collaborate/blob/main/assets/collaborate.png)

Collaborate ist ein REDAXO Addon, welches primär entwickelt wurde, um **kollisionsfreie, parallele Zusammenarbeit** im Backend zu ermöglichen.
Der Kern des AddOns ist ein unabhängig von der REDAXO-Instanz (als Website) arbeitender Dienst, der permanent läuft und einen **Websocket Server**
zur Verfügung stellt. Das AddOn liefert  Plugins sollen dann die eigentlichen Features liefern und können via Callbacks und Event-Handler sowohl server-seitig (PHP)
als auch client-seitig (JS)

## Installation

Die folgende Installationsanweisung fokussiert sich auf Anpassungen am Webserver und setzt in diesem Kontext das Vorhandensein eines gültigen **Let's
Encrypt**-Zertifikats voraus. Die unten stehenden Code-Schnipsel und Konfigurationsempfehlungen sind nicht direkt für die Nutzung mit anderen SSL-Zertifikaten
anwendbar.

### Schritt 1: Vorbereitung des Webservers

**Voraussetzungen zusammengefasst**
* Server-Zugriff inkl. Webserver-Konfigurationsdateien
* Server-Restart möglich
* idealerweise root-Zugriff oder Managed-Server > dann den Betreiber die Anpassungen durchführen lassen
* Installation von `mod_proxy` (bei Apache)

Für die Nutzung des AddOns sind **Anpassungen an der Server-Konfiguration notwendig**. Bei geteilten Hosting-Paketen (_Shared Hosting_) wird man in der Regel
vom Betreiber keine Anpassungen an seiner Server-Konfigurartion erwarten können, da diese dann immer mehrere Kunden auf diesem Server betreffen
und das wiederum zu weiteren offenen Flnken führt, die Administratoren aus Sicherheitsgründen gern vermeiden.

Sollte die Website, für die man Collaborate unter REDAXO betreiben möchte auf einem **Managed Server** liegen, müsste der Support des Server-Betreibers
normalerweise die notwendigen Anpassungen erledigen können. Dort sollte dann kommuniziert werden, dass es um Websockets via SSL (wss-Protokoll) für Domain X geht
und alles über Port P laufen soll.

Den Port findet man übrigens in der `package.yml` im AddOn-Basisverzeichnis. Dort steht unter `websocket-server-port` der Port,
unter dem das Collaborate-Websocket dann laufen wird. Dieser ist per default auf **6789** gesetzt und wird deshalb in nachfolgenden Konfigurations-Schnipseln auch so eingesetzt.
Möchte man diesen ändern, müsste diese Änderung in der Datei erfolgen und anschließend das AddOn re-installiert werden. Falls zu diesem Zeitpunkt schon die Server-Application lief,
müsste diese (einzeln oder via [Service](#schritt-2-service-datei-einrichten)) ebenfalls neu gestartet werden, um die Port-Anpassung zu berücksichtigen.

Absolut notwendig für den Betrieb des AddOns ist das Vorhandensein von `mod_proxy`.

#### Apache

Die nachfolgende Basis-Konfiguration faktisch ein Server-internes Routing der Websocket-Verbindungen auf reguläre Verbindungen, die dann über Portmapping
dem Collaborate-Application-Prozess zur Verfügung gestellt werden. Der Rückweg von der Application zum Client erfolgt ebenfalls hierüber.

Mehr Infos dazu hier: https://letsencrypt.org/de/docs/challenge-types/

```
<IfModule mod_proxy.c>
    ProxyPass /wss ws://0.0.0.0:6789/
    ProxyPassReverse /wss ws://0.0.0.0:6789/
</IfModule>
```

#### nginx

_TODO_

### Schritt 2: Service-Datei einrichten

Bei der Installation des AddOns wird im Pfad `[...]/redaxo/src/addons/collaborate/conf/collaborate.service` eine .service-Datei erstellt und bereits
mit den richtigen Pfaden für den aktuellen Server vorausgefüllt. Dort stehen auch oben in den Kommentaren noch einmal alle wichtigen Kommandos für die
nachfolgenden Schritte; ebenfalls vorausgefüllt mit den korrekten Pfaden und direkt via Copy-and-paste einsetzbar.

Man sollte beachten, dass folgende Kommandos als **root-User** ausgeführt werden sollten! 

**2.1 Sym-Link erstellen**

Mit nachfolgendem Kommando wird ein Sym-Link der Collaborate Service-Konfiguration in der Liste der System-Dienste ergänzt.

`> sudo ln -s [...]redaxo/src/addons/collaborate/conf/collaborate.service /etc/systemd/system/collaborate_websocket_[NAME].service`

**2.2 Dienst-Befehle** 

* Websocket-Dienst (und damit die Server-Application) **starten**:<br />
`> sudo systemctl start collaborate_websocket_[NAME].service`<br />
* Websocket-Dienst **stoppen**:<br />
`> sudo systemctl stop collaborate_websocket_[NAME].service`<br />
* Websocket-Dienst **Status-Abfrage**:<br />
`> sudo systemctl status collaborate_websocket_[NAME].service`

### Schritt 3: Anpassungen in REDAXO-Templates für Plugins mit Frontend-Features

Das Kern-AddOn fokussiert sich auf Zusammenarbeit im Backend und setzt dafür Sicherheitsroutinen ein, die dafür sorgen, dass nur
Verbindungen von Backend-Nutzern zugelassen werden. Dennoch ist es möglich, Plugins so zu entwickeln, dass Up- und/oder Down-Stream
Websocket-Verbindungen aus dem Frontend zulassen (mehr dazu in [Plugin-Entwicklung](#plugin-entwicklung)).

Diese AddOns benötigen in der Regel eigene JavaScript-Dateien (und ggf. auch eigene CSS-Dateien). Um diese im Frontend zu laden, sind folgende
2 Anpassungen notwendig:
1. In den Templates, in denen Collaborate im Frontend eingebunden werden soll, muss der Platzhalter `REX_COLLABORATE_FRONTEND[]` im
`<head>`-Bereich eingebunden werden. Dieser sorgt für das Laden und Instanziieren der zentralen Collaborate-Klasse und lädt des Weiteren
die Frontend-Ressourcen aktivierter Plugins.
2. Plugins benötigen im Ordner `/assets/js/` eine Datei mit dem Namensschema `collaborate.plugin.[PLGUINNAME].frontend.js`.
Zur automatischen Einbindung einer CSS-Datei muss diese in `/assets/css/` mit dem Namensschema `collaborate.plugin.[PLGUINNAME].frontend.css`
angelegt werden.

...

## AddOn-Features  

* Multi-Tab- bzw. Fenster-Erkennung via Tab IDs
  * 1 Tab/Fenster = 1 registrierte Client-Verbindung
  * vor dem Broadcasting werden mehrere Verbindungen desselben Nutzers zusammengeführt 
* Header-Bar-Toolbox im REDAXO-Backend mit Indikator, ob der Websocket-Server läuft bzw. der Client dorthin eine Verbindung aufbauen kann
  (benötigt Berechtigung `collaborate[]`)
  * Auto-Reconnect nach 60sek, falls der Server offline ist
  * wenn offline wird ein Button zum manuellen Reconnect sichtbar
  * Anzeige der Summe aller anderen aktiven Backend-Nutzer (eigene Verbindung wird dabei nicht mitgezählt!)
* Einzelrechte des Haupt-AddOns erweitern die Toolbox um mehr Funktionen:
  * `collaborate[users]` - Toolbox ist klickbar und zeigt die Namen aller anderen aktuell eingeloggten Nutzer und seit wann diese online sind
  * `collaborate[user_locations]` - zeigt zusätzlich an, welche Nutzer in welchen Sektionen (1-n) unterwegs sind und auch wie viele Tabs/Fenster sie geöffnet haben
* trotz Fokus auf Backend-Tools Einbindung im Frontend möglich und via Plugins steuerbar
* Websockets via SSL (wss-Protokoll)

## Identity Check für Backend-Aktionen

Der Collaborate-Server wird unter der Prämisse entwickelt, dass Änderungen auf CMS-Ebene möglichst keinen Neustart des Websocket-Dienstes erfordern.
Der Websocket-Server führt deshalb in regelmäßigen Abständen Prüfungen der Nutzerdatenbank (`rex_user`) durch und speichert das aktuelle Abbild
in einer eigenen Datenstruktur. Die Wiederholung dieses Checkups zur Laufzeit ("Loop" genannt) sorgt dafür, dass der Server mit leichter zeitlicher Verzögerung
neu hinzugekommene, gelöschte, deaktivierte oder hinsichtlich ihrer Rollen und Rechte veränderte Nutzer registriert und im weiteren Programmablauf der Application
darauf reagieren kann.

Für eine grundlegende Identifikation von aus dem Backend-Client eingehenden Anfragen werden die Parameter `user` (Login-Name des Backend-Nutzers)
und ein Feld `userhash` übermittelt, welches anhand bestimmter Daten des Accounts generiert wird. Auf der Server-Seite wird beim Start und bei der zyklischen
Überprüfung der User-Accounts derselbe Hash erzeugt und abgegleichen. Anfragen

## Logging & Datenschutz

Die Collaborate Application und ggf. auch eingebundene Plugins schreiben zur Überprüfung des korrekten Programmablaufs regelmäßig Ausgaben mittels `echo`.
Die o.g. Standard-Implementierung als Dienst leitet diese Ausgaben in eine Log-Datei unter `[...]/redaxo/data/addons/collaborate/collaborate.log` um. Dateien
im `/data/`-Bereich sind vor Direktaufrufen im Browser geschützt. Ebenfalls sorgt die o.g. interne Umleitung der Websocket-Verbindungen dafür, dass
im Log stets `127.0.0.1` als IP-Adresse für eingehende Verbindungen erscheint, wodurch Restriktionen

## Plugin-Entwicklung

Collaborate stellt im Kern hauptsächlich eine **Server-Application (PHP)** und zugehörige **Client-Klassen (JS)** bereit.
Wegen des Fokus auf Zusammenarbeit von Backend-Nutzern ist die Verwaltung derer Verbindungen und Datenpakete bereits integriert.
Das AddOn selbst liefert dabei nur minimale Features für die Nutzung im REDAXO Backend (siehe Feature-Liste).

Geplant und gewünscht ist die Entwicklung von Plugins, die sowohl Frontend- als auch Backend-Features liefern können und sich mittels
Registrierung von Event-Handlern an die Prozesse der Application _"anhängen"_ können. Verbundene Clients können vor der
weiteren Verarbeitung im Hauptprozess manipuliert oder Verarbeitungsschritte abgebrochen oder einkürzt werden.

### package.yml

In der package.yml legt man für Up- und Downstream separat fest, ob diese das Backend, das Frontend oder beides betreffen. Bei der Installation des
Plugins werden diese Flags in die Plugin-Config geschrieben und an anderer Stelle berücksichtigt. U.a. werden dann durch eine REX_VAR
(siehe [hier](#schritt-3-anpassungen-in-redaxo-templates-fr-plugins-mit-frontend-features) Ressourcen von Frontend-scoped Plugins und durch die
boot-Routine des Haupt-AddOns die von Backend-scoped Plugins automatisch eingebunden. Wichtig dafür ist eine konsistente Namensgebung in den
Plugin-Assets:

* **Frontend-Ressourcen** sind nach dem Schema `collaborate.plugin.PLUGINNAME.frontend.js/css` zu benennen
* **Backend-Ressourcen** sind nach dem Schema `collaborate.plugin.PLUGINNAME.backend.js/css` zu benennen

Die Konfiguration der package.yml selbst ist dann wie folgt vorzunehmen:

```
# defines scopes for websocket up- and down streams
# 2 = frontend & backend
# 1 = frontend
# 0 = backend
# -1 = no automatic embedding
upstream-scope: 1
downstream-scope: 0
```

### Server

Im `lib`-Ordner des Plugin sollte die Klasse des Plugins liegen. Diese muss von der abstrakten Klasse `CollaboratePlugin` erben, um beim Einlesen
durch die Application berücksichtigt zu werden. Diese Klasse kann mit vordefinierten Methoden dann auf bestimmte Events, die die Application bei
bestimmten Programmprozessen auslöst, reagieren.

Ein Beispiel für die Entwicklung eines Plugins namens `"test"`:
```php
class CollaboratePluginTest extends CollaboratePlugin {
    /**
    * do something with incoming messages (after successful backend user verification process!)
    * @param $data
    * @param ConnectionInterface $client
    */
    public function onMessage(&$data, ConnectionInterface &$client) {
        // Zwischenspeichern aller aktuell registrierten Backend-Verbindungen
        // ACHTUNG: Ein und selbe Backend-User kann mehrere Tabs/Fenster geöffnet haben > jedes Tab/Fenster ist eine eigene Verbindung
        $clients = $this->app->getClients();

        // $data repräsentiert die JSON Daten, die der Nutzer mit der Verbindung $client
        // mit der Event-auslösendes Nachricht versendet hat (aus dem Browser heraus)
        if(count($clients) > 1 && $data->type == 'PAGE' && isset($data->page->path) && $data->page->path == "templates") {
            foreach ($clients as $hash => $c) {
                // aufrufender Benutzer wird ignoriert > muss nicht über seine eigene Aktion informiert werden
                if (!is_null($client) && $c['user'] == $data->user) {
                    continue;
                }

                // Log-Eintrag schreiben
                CollaborateApplication::echo(sprintf(
                    "test: Der Benutzer '%s' (resID %s) wird über den Besuch der Seite 'Templates' durch Benutzer (resID %s) informiert",
                    $c['user'],
                    $c['connection']->resourceId,
                    $client->resourceId
                ));
            }
        }
    }
}
```

Hier wird auf `onMessage` reagiert und lediglich ein Log-Eintrag generiert, wenn ein Benutzer $client die Backend-Seite 'Templates' aufruft.
Über das Manipulieren der $data-Variablen kann je nach Trigger-Punkt der weitere Programmablauf in der Main Application manipuliert
(ggf. gestoppt) werden. Da Collaborate mit 3 Plugins ausgeliefert wird, u.a. `viewcounter` mit einem Handling für Frontend-Verbindungen, gibt
es bereits einige Code-Schnipsel, die auch als Vorlage für eigene Entwicklungen dienen sollen.

### Client

_TODO_

## Lizenz

Collaborate ist unter der [MIT Lizenz](LICENSE.md) lizensiert.

## Changelog

siehe [CHANGELOG.md](https://github.com/FriendsOfREDAXO/collaborate/blob/master/CHANGELOG.md)

## Autor

**Friends Of REDAXO**

* https://www.redaxo.org
* https://github.com/FriendsOfREDAXO

**Projekt-Lead**

[Peter Schulze | Bitshifters](https://github.com/bitshiftersgmbh)

## Credits

Collaborate basiert auf [Ratchet](https://github.com/ratchetphp/Ratchet) von [Chris Boden](https://github.com/cboden)

----

## TODOs

* sinnloses Zustellen an verschiedene Connections desselben Clients (bei 3 offenen Tabs 2 unnötige Messages, weil für jedes Tab
  ein weiterer Messageblock generiert wird > Bug)
* Test, ob dynamisches Einbinden von aktualisierten Plugin-Files oder neu hinzu gekommenen Plugins wirklich funktioniert
* Plugin-Vorlage: `mixed` konsequent durch `?object` ersetzen, vorher prüfen, wie stabil das ist
* `downstream-scope` und `upstream-scope` Flags in package.yml korrekt und fertig implementieren (auto-include im Backend via boot.php)
* `yform`-Plugin:
  * Kollisionen serverseitig vermeiden (First come first served)
* Doku für AddOn generell verbessern + für Plugins überhaupt erst schreiben 
* ggf. zentrales Object für FE-Verbindungen um bei mehreren FE-Plugins keine unnötige Redundanz zu erzeugen
  * evtl. eigenes Log-File für FE Connections oder aber FE-Connections gar nicht loggen (erschwert allerdings Debugging sehr!)
* `structure`
  * Sperre pro clang (nicht generell über alle Sprachen) 
* `structure` + `yform` Plugins:
  * "übernehmen" Szenario ohne on/off/on Geflicker bei gesperrter Detail-View (nice2have)
  * wenn in Detailansicht geblockt > nach Wiederfreigabe Seite neuladen um auf aktuellem Stand zu sein
* `viewcounter`
  * Handling für verwaiste Frontend-Connections einbauen > created Timestamp ergänzen und globale Ablaufzeit festlegen
  * evtl. Flag/Methode für das Resetten aller FE-Verbindungen einbauen (über Easter-Egg oder Console aufrufbar) > cleart FE Client Stack
    auf Serverseite und löscht alle Bubbles in structure View (Clientseite)
  * Bug: zählt teilweise falsch (children)
  * Url-AddOn berücksichtigen > Pfade von Landing Pages auf Hauptartikel umbuchen
