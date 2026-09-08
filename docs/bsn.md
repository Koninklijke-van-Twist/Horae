# BSN uit SharePoint

Vul `$bsnGraph` in `web/auth.php` in. Dezelfde blanco configuratie staat in `web/auth_TEMPLATE.php`; `auth.php` blijft buiten Git. In deze checkout ontbrak `auth.php`, dus het is aangemaakt vanuit de template: vul ook de bestaande BC-instellingen in wanneer die hier nog niet zijn geconfigureerd.

| Sleutel | Waarde |
| --- | --- |
| `TENANT_ID` | Entra tenant-ID |
| `CLIENT_ID` | Application/client-ID |
| `CLIENT_SECRET` | Secret-waarde, niet de secret-ID |
| `SITE_ID` | Graph site-ID (doorgaans `hostname,siteCollectionId,webId`) |
| `LIST_ID` | ID van de SharePoint-lijst |
| `EMPLOYEE_FIELD` | Interne naam van de employee-ID-kolom |
| `BSN_FIELD` | Interne naam van de BSN-kolom |

De app gebruikt [client credentials met Graph .default](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow). Geef de app bijvoorbeeld de Graph application permission `Sites.Selected`, admin consent en afzonderlijk de rol `read` voor de betreffende site. Alleen consent op `Sites.Selected` geeft nog geen toegang. Zie [Selected permissions](https://learn.microsoft.com/en-us/graph/permissions-selected-overview).

Gebruik voor employee ID een **geïndexeerde tekstkolom** met exact `Resource.No`, inclusief voorloopnullen. Gebruik voor BSN een tekstkolom met negen cijfers. Gebruik interne kolomnamen, niet de zichtbare labels. Er wordt per unieke benodigde medewerker één [gefilterd list-items-request](https://learn.microsoft.com/en-us/graph/api/listitem-list?view=graph-rest-1.0) gedaan, met alleen de twee benodigde fields. Er is geen ongefilterde fallback. Dubbele matches, onjuiste identifiers en ongeldige BSN-waarden stoppen het rapport met een foutmelding zonder persoonsgegevens. Geen match of een leeg BSN geeft `Onbekend`.

De selectie gebeurt na samenvoegen en toepassen van correcties. Verwijderde rijen worden niet opgehaald. Handmatig toegevoegde rijen hebben geen betrouwbare Resource.No-koppeling en krijgen `Onbekend`; er wordt niet op naam gegokt. Meerdere weken/projecten met dezelfde resource veroorzaken binnen één request één lookup. De PDF-download doet een nieuwe lookup en kan daardoor een inmiddels bijgewerkt BSN tonen.

BSN en token blijven tijdens de lookup alleen in requestgeheugen; de OData-cache wordt niet gebruikt. BSN wordt niet in correcties, originele celwaarden, sessie of browserstorage opgeslagen. BSN-cellen zijn niet bewerkbaar; de backend weigert wijzigingen aan BSN en resourcekoppeling. HTML en PDF krijgen `Cache-Control: no-store`. Bestaande historische cache-/correctiebestanden worden niet automatisch gewist; oude BSN-correcties worden genegeerd en bij de volgende correctie-opslag uit dat bestand verwijderd.

Bij de expliciete PDF-download gebruikt de bestaande Chromium-generator kortstondig HTML- en PDF-werkbestanden. Deze zijn alleen leesbaar voor de servergebruiker en worden bij normale afloop en afgevangen fouten verwijderd. Dit is geen herbruikbare BSN-cache. Een harde proces-/servercrash kan tijdelijke bestanden achterlaten; gebruik voor volledig vluchtige verwerking een voor de PHP-service geconfigureerde tijdelijke map op RAM-opslag. De door de gebruiker gedownloade PDF bevat de BSN's zoals gevraagd.

De webpagina verbergt BSN bij focusverlies, een verborgen tabblad, ontvangen screenshottoetsen en via de knop **BSN verbergen**. Na een herkende screenshottoets is expliciet **BSN tonen** nodig. Browserprint is gecensureerd; de PDF-downloadknop behoudt BSN. Zonder JavaScript blijft BSN op de webpagina verborgen.

Dit is beperkte screenshotbescherming: [visibilitychange](https://developer.mozilla.org/en-US/docs/Web/API/Document/visibilitychange_event) meldt zichtbaarheid, geen screenshot. Systeem- en browsertools kunnen screenshots maken zonder dat toetsen of focuswijzigingen aan de pagina worden doorgegeven, of voordat de wijziging is geschilderd. Er is dus geen garantie; gebruik de handmatige verbergknop vóór een screenshot. Zichtbare BSN-waarden staan noodzakelijkerwijs in de pagina en zijn voor de ingelogde gebruiker toegankelijk.

Lokale controles (alleen fictieve testwaarden, geen Graph-verkeer):

```sh
php tests/bsn.php
php tests/bsn.php --export
node tests/bsn-privacy.js
```

Controleer na configuratie op de server een bekende resource, een ontbrekende match en een PDF-download. Hiervoor zijn echte Graph-toegang, BC-toegang en een werkende Chromium-installatie nodig; deze zijn niet beschikbaar in de ontwikkelcheckout.
