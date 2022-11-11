Changelog
=========

Version 1.2.0 – 11.11.2022
--------------------------

### Neu

* Plugin "Viewcounter": Berechtigungen der Strukturverwaltung gelten nun auch für die ähnlichen Bereiche des Url-AddOns
* Plugin "Viewcounter": Anzeige von Viewcount-Bubbles am Url-AddOn-Menüpunkt, der Url-Profilseite und der Seite mit den generierten URLs
(Hinweis: die Bubble-Zahl kann bei "Url" durchaus größer sein als bei "Strukturverwaltung", da in der Strukturverwaltung Aufrufe
verschiedener Url-Landingpages, die unter demselben Artikel laufen und von demselben User ausgelöst werden, nur als 1 Besuch gezählt werden!)

### Bugfixes

* Plugin "Viewcounter": Frontend ID aus IP-Adresse wurde falsch erzeugt
* Plugin-Basisklasse nutzt `mixed` Parameter, die erst mit PHP 8 eingeführt wurden > mind. PHP-Version in package.yml ergänzt
(**ACHTUNG:** Diese Voraussetzung gilt streng genommen nur für den Teil, der als Dienst läuft. Dieser kann durch Anpassen der Datei
`conf/collaborate.service` durchaus unter PHP 8.x laufen während das CMS selbst unter PHP 7.x läuft! In diesem Ausnahmefall müsste die
PHP-Versions-Bedingung aus der package.yml händisch gelöscht und das AddOn dann reinstalliert werden!)

Version 1.1.2 – 13.10.2022
--------------------------

### Neu

* Plugin "Viewcounter": Berücksichtigung des "Url"-AddOns > Counter zählt dann beim zugehörigen Artikel mit

Version 1.1.0 – 05.10.2022
--------------------------

### Neu

* Logrotate-Config und Mini-Anleitung werden bei Installation mit erstellt
* Plugin "Viewcounter": Einführung eines globalen Counters als Bubble am Menüpunkt
* Plugin "Viewcounter": Einführung von Permissions für globalen Counter und Einzel-Counter (Strukturverwaltung)

### Bugfixes

* Permission-Fehler behoben und gemäß Beschreibung angepasst 
* Unnötige Page für das Haupt-AddOn entfernt
* Perm `collaborate[]` in package.yml entfernt, damit alle BE-User ohne Rollen-Anpassung Daten senden
* Caching von User Objekten auf Server-Seite unterbunden, um immer aktuellste Einstellungen zu benutzen (Anpassungen an
  Benutzer-Rechten erfordern nun keinen Neustart des WebSocket-Servers mehr)
* Plugin "Viewcounter": Korrektur beim Zählen von Child Article Views
