# Site Feedback

Een visuele feedbacktool voor WordPress, vergelijkbaar met BugHerd.

Shopify zit hier bewust niet in: daarvoor komt een losse Shopify-app.

## Wat het doet

- Rechts op de website staat een klein feedback-icoon. **Alleen zichtbaar voor ingelogde beheerders** (`manage_options`).
- Klik je op het icoon, dan opent een zijbalk met **Geef feedback** en **Zie feedback**.
- Met **Geef feedback** wijs je een element aan. Elementen krijgen een outline als je eroverheen beweegt.
- Klik je op een element, dan slaat de tool dit op:
  - gebruiker
  - URL en paginatitel
  - CSS-selector, tekst en HTML van het element
  - muispositie (op de pagina, in beeld en relatief binnen het element)
  - schermgrootte en browser
  - een **screenshot**, met het element omlijnd en de klikpositie gemarkeerd
- Je beschrijft wat er mis is en klikt op **Voeg feedback toe**.
- Met **Zie feedback** zie je de feedback voor *deze pagina* of *alles*. Op de pagina staan genummerde pins. Een klik op een item scrolt naar het element en markeert het.
- Per item kun je:
  - de status zetten (open/opgelost)
  - een Asana-taak maken (met de screenshot als bijlage)
  - het item bewerken in WordPress
  - het item verwijderen
- In wp-admin staat een menu **Feedback** met een lijst, screenshots, filters op status en bulkacties. Ook daar kun je Asana-taken maken.

## Installatie

1. Download `site-feedback.zip` van de laatste [release](../../../releases) en upload hem via **Plugins → Nieuwe plugin → Plugin uploaden**. Je kunt deze map ook als `site-feedback` naar `wp-content/plugins/` kopiëren.
2. Activeer de plugin.
3. Open de website terwijl je ingelogd bent. Rechts staat nu het icoon.

## Asana koppelen

1. Maak in Asana een Personal Access Token aan: **Mijn apps → Developer console → Create new token**.
2. Ga in WordPress naar **Feedback → Instellingen**, plak het token en sla op.
3. Kies het project waarin de taken moeten komen en sla opnieuw op.
4. **Automatisch** staat standaard aan: elke nieuwe feedback wordt meteen een taak. Lukt dat niet, dan probeert WP-cron het elke 15 minuten opnieuw. Bestaande feedback zonder taak krijgt er zo ook alsnog een, 10 per keer.
5. Optioneel: klik op **Realtime-sync activeren** zodat wijzigingen in Asana direct binnenkomen.

### Koppeling in twee richtingen

| In Asana | → In WordPress / widget |
|---|---|
| Taak voltooid of heropend | Feedback wordt *opgelost* of *open* |
| Toegewezen persoon, deadline, sectie | Zichtbaar in widget, lijst en detailpagina |
| Reacties op de taak | Zichtbaar in de widget en in wp-admin |
| Taak verwijderd | De koppeling wordt losgelaten en niet automatisch opnieuw aangemaakt |

| In de widget / wp-admin | → In Asana |
|---|---|
| Markeer opgelost of heropen | Taak wordt voltooid of heropend |
| Reactie plaatsen | Reactie op de taak, met jouw naam erbij |

Zet je een taak in Asana op **done**, dan wordt de feedback in WordPress *opgelost*. Heropen je hem, dan staat hij weer *open*. Dat werkt ook op een lokale site, omdat WordPress de wijzigingen zelf ophaalt:

- **Bij het openen van de feedbacklijst** (widget of wp-admin), bij het laden van een pagina als beheerder en als je terugkomt naar het tabblad van je site. De plugin haalt dan met één verzoek alle taken op die sinds de vorige keer gewijzigd zijn (max. 1x per 15 sec).
- **Bij het openen van een item**: ook de reacties worden dan opgehaald.
- **Elke 15 minuten** via WP-cron.
- **Realtime** via een Asana-webhook (optioneel). Daarvoor moet de site publiek bereikbaar zijn via https. De webhook is beveiligd met een handshake en een HMAC-handtekening.

Reacties die je vanuit WordPress plaatst, staan in Asana op naam van de eigenaar van het token. Daarom zet de plugin je naam ervoor.

Elke taak bevat:

- de feedback
- de URL
- het element
- de muispositie
- de schermgrootte
- de browser
- wie de feedback gaf
- een link terug naar WordPress
- de screenshot als bijlage

## Techniek

| Onderdeel | Bestand |
|---|---|
| Bootstrap, post type, laden van widget | `site-feedback.php` |
| Screenshots, formattering | `includes/helpers.php` |
| REST API (`/wp-json/site-feedback/v1`) | `includes/rest.php` |
| Asana-client | `includes/asana.php` |
| wp-admin: lijst, details, instellingen | `includes/admin.php` |
| Front-end widget | `assets/widget.js` |

- Feedback wordt opgeslagen als niet-publiek post type `sfb_feedback`.
- Screenshots staan in `wp-content/uploads/site-feedback/`, met willekeurige bestandsnamen.
- De widget draait in een Shadow DOM. Thema-CSS heeft er dus geen invloed op, en de widget heeft geen invloed op het thema.
- Screenshots worden gemaakt met [html2canvas-pro](https://github.com/yorickshan/html2canvas-pro), dat pas wordt geladen bij de eerste feedback (via jsDelivr). Wil je het lokaal hosten? Zet dan `html2canvas: 'URL'` in `SFB_CONFIG`.
- Alleen ingelogde beheerders (`manage_options`) hebben toegang tot de REST API, via cookie + nonce. De enige uitzondering is de Asana-webhook, die beveiligd is met een handshake en een HMAC-handtekening.

### Beperkingen van screenshots

html2canvas "tekent" de pagina na. Hij maakt geen echte schermafbeelding. Afbeeldingen van andere domeinen zonder CORS-headers, iframes (zoals YouTube of kaarten) en sommige exotische CSS-effecten kunnen daardoor ontbreken of afwijken. De feedback wordt altijd opgeslagen, ook als de screenshot mislukt.
