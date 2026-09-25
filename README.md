# Horae

Urenstaten / projectselectie op basis van Business Central OData op sleutels.kvt.nl/horae.

## Structuur

- `web/index.php` — projectselectie (UI)
- `web/odata.php` — OData-client, lokale filecache-widget, optionele Mímir-proxy, nightly projectcache
- `web/nightly.php` — warm AppProjecten + Job Planning Lines (Resource)
- `web/auth.php` — credentials (niet in git)

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

Met `$mimirApi` gezet zijn `$auth_list` en `$auth` ongebruikt voor Business Central — OData-fetches (nightly snapshot-build en UI/on-demand) lopen via Mímir. Houd `$base` met `Company('…')` zodat entity-URLs parseerbaar blijven, of zet `$mimirCompany` zonder `$base`. Zonder `$mimirApi` blijft het bestaande directe BC-pad ongewijzigd.

**max_age-beleid**

| Soort fetch | `max_age` naar Mímir |
| --- | --- |
| `nightly.php` snapshot-build | **14400** (`HORAE_NIGHTLY_MAX_AGE`, 4u) |
| `hourly.php` | niet aanwezig in Horae |
| UI / on-demand | bestaande TTLs — default **300** (`HORAE_ODATA_TTL`), o.a. planning-lines live **60**; lokale nightly-projectsnapshot blijft **86400** (`projects_nightly_ttl`) |

Tim moet `$mimirApi` (en optioneel `$mimirBase`) lokaal/op de server zetten; `auth.php` wordt niet gecommit. Zie [Mímir Implementatie](https://wiki.kvt.nl/books/mimir/page/implementatie).

## Nightly cache warm

```sh
php web/nightly.php
# of: GET /horae/web/nightly.php
```

## auth.php

Geen `auth.php` in deze repository (staat in `.gitignore`). Lokaal/op de server de Mímir-sleutel zetten zoals hierboven; legacy BC-credentials alleen nodig zonder `$mimirApi`.
