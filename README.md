# CB Plotdesk – TYPO3 Extension

Plotdesk-Integration für TYPO3: Seitenbaum-SEO-Daten (`seo_title`, `description`) per Reaction als JSON bereitstellen.

[![TYPO3 13](https://img.shields.io/badge/TYPO3-13.4-orange.svg)](https://get.typo3.org/version/13)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%20%7C%208.3-blue.svg)](https://www.php.net/)
[![Composer](https://img.shields.io/badge/composer-cybay%2Fcb--plotdesk-green.svg)](composer.json)

| | |
|--|--|
| Extension-Key | `cb_plotdesk` |
| Composer-Paket | `cybay/cb-plotdesk` |
| Namespace | `Cybay\CbPlotdesk\` |
| TYPO3 | 13.4+ |
| PHP | 8.2 / 8.3 |
| Autor | Torben Aschmons (`aschmons@cybay.de`) |
| Vendor | Cybay |

---

## Features

- Custom Reaction `cb-plotdesk-page-seo-export` für eingehende Webhooks (EXT:reactions)
- Export von `seo_title` und `description` aus den Seiteneigenschaften
- Steuerung per Payload: `rootPageId`, `languageId`
- Flache Ergebnisliste (kein verschachtelter Baum)
- Nur Standard-Seiten (`doktype = 1`); Ordner & Co. werden übersprungen, der Baum darunter weiter durchlaufen
- Sprach-Overlays über `PageRepository` / `LanguageAspect`

---

## Systemanforderungen

| Komponente | Version |
|------------|---------|
| TYPO3 | 13.4 – 13.99 |
| PHP | 8.2 oder 8.3 |
| EXT:reactions | ^13.4 |

---

## Installation

Pfad-Repository vorausgesetzt (`packages/*` im Root-Projekt):

```bash
composer require cybay/cb-plotdesk:@dev
ddev exec vendor/bin/typo3 extension:setup
ddev composer _clearcache
```

---

## Reaction im Backend anlegen

1. **System → Integrations → Reactions** → neue Reaction
2. Reaction Type: *Plotdesk: Seitenbaum seo_title und description exportieren*
3. Name / Beschreibung vergeben
4. Secret generieren und sicher speichern (nur einmal sichtbar)
5. Identifier notieren

Endpoint:

```text
POST https://DEINE-DOMAIN/typo3/reaction/{identifier}
```

---

## API-Aufruf

### Headers

| Header | Wert |
|--------|------|
| `Content-Type` | `application/json` |
| `Accept` | `application/json` |
| `x-api-key` | Secret der Reaction |

### Payload

```json
{
  "rootPageId": 1,
  "languageId": 0
}
```

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `rootPageId` | int | UID der Startseite; Baum ab dieser Seite |
| `languageId` | int | Site-Language-ID (`0` = Default) |

### Beispiel (curl)

```bash
curl -X POST "https://DEINE-DOMAIN/typo3/reaction/DEIN-IDENTIFIER" \
  -H "x-api-key: DEIN-SECRET" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"rootPageId": 1, "languageId": 0}'
```

### Beispiel (Postman)

1. Method: **POST**
2. URL: `https://…/typo3/reaction/{identifier}`
3. Headers wie oben
4. Body → raw → JSON mit `rootPageId` und `languageId`

### Erfolgsantwort (200)

```json
{
  "success": true,
  "rootPageId": 1,
  "languageId": 0,
  "pages": [
    {
      "uid": 1,
      "pid": 0,
      "title": "Home",
      "seo_title": "Startseite | Beispiel",
      "description": "Meta-Description …"
    },
    {
      "uid": 5,
      "pid": 1,
      "title": "Über uns",
      "seo_title": "Über uns",
      "description": "…"
    }
  ]
}
```

### Fehlerantworten

| Status | Bedeutung |
|--------|-----------|
| 400 | `rootPageId` fehlt oder ist ungültig |
| 401 | Falsches oder fehlendes `x-api-key` |
| 404 | Root-Seite nicht gefunden |

---

## Architektur

```
packages/cb_plotdesk/
├── Classes/
│   └── Reaction/
│       └── PageSeoExportReaction.php
├── Configuration/
│   ├── Services.yaml
│   └── TCA/Overrides/sys_reaction.php
├── composer.json
├── ext_emconf.php
└── README.md
```

Die Reaction implementiert `TYPO3\CMS\Reactions\Reaction\ReactionInterface` und wird per Autoconfiguration mit dem Tag `reactions.reaction` registriert.

---

## Lizenz

GPL-2.0-or-later
