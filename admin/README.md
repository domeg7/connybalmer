# Ferien-CMS — Setup auf hosttech

Mini-CMS zur Verwaltung von Ferienabwesenheiten. Während der eingetragenen Zeiträume erscheint auf der Website ein Overlay mit Datum und optionaler Nachricht.

## Was wird auf den Server hochgeladen?

Wie gewohnt per FTP/SFTP ins Webroot von hosttech:

```
/
├── index.html
├── angebot.html
├── vacations.json         ← muss schreibbar sein (siehe unten)
├── assets/
│   ├── main.js
│   ├── styles.css
│   └── images/
└── admin/
    ├── index.php
    ├── .htaccess
    └── (config.php wird beim ersten Aufruf automatisch erzeugt)
```

## Schreibrechte

Damit das CMS Einträge speichern kann, muss PHP in folgende Pfade schreiben dürfen:

| Pfad                  | Rechte | Zweck                                  |
|-----------------------|--------|----------------------------------------|
| `vacations.json`      | 664    | Wird vom Admin überschrieben           |
| `admin/`              | 755    | Damit `config.php` einmal angelegt werden kann |
| `admin/config.php`    | 640    | Wird vom Setup automatisch gesetzt     |

Auf hosttech läuft PHP üblicherweise unter demselben Benutzer wie FTP — Standard-Rechte reichen meist. Falls beim ersten Speichern eine Fehlermeldung erscheint, im FTP-Client per Rechtsklick → "Berechtigungen" auf 664 setzen.

## Erstes Setup

1. Dateien auf hosttech hochladen
2. `https://<deine-domain>/admin/` im Browser öffnen
3. Beim ersten Aufruf erscheint die **Erstkonfiguration**: Benutzername + Passwort wählen
4. Danach sofort eingeloggt und einsatzbereit

Die Zugangsdaten werden als gehashtes Passwort in `admin/config.php` gespeichert (nicht im Klartext).

## Passwort zurücksetzen

Falls das Passwort vergessen wurde: `admin/config.php` per FTP löschen — beim nächsten Aufruf erscheint wieder die Erstkonfiguration.

## Wie funktioniert das Overlay?

- `assets/main.js` lädt beim Seitenaufruf `vacations.json`
- Liegt das heutige Datum innerhalb eines Eintrags (`from` ≤ heute ≤ `to`), erscheint das Overlay
- Pro Browser-Session nur einmal — wer es schliesst, sieht es bis zum nächsten Tab-Neustart nicht wieder
- Bei mehreren überlappenden Einträgen wird der erste in der Liste angezeigt (Liste ist nach Startdatum sortiert)

## Datenformat (`vacations.json`)

```json
[
  {
    "from": "2026-07-14",
    "to": "2026-07-28",
    "message": "Sommerferien. In dringenden Fällen wenden Sie sich an…"
  }
]
```

Manuelles Editieren ist auch möglich, aber der Komfort des Admin-Panels macht das in der Regel überflüssig.

## Sicherheit

- `admin/.htaccess` schützt `config.php` vor direktem Web-Zugriff
- HTTPS sollte für `/admin/` aktiv sein (hosttech bietet Let's Encrypt gratis)
- Login-Sessions sind auf den Browser-Cookie-Lifetime begrenzt
- CSRF-Schutz auf allen Formularen
