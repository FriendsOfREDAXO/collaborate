Changelog
=========

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
