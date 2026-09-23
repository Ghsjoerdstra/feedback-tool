# Feedback Tool

Visuele feedbacktool voor websites, vergelijkbaar met BugHerd.

1. Klik een element aan en beschrijf wat er mis is.
2. De tool bewaart gebruiker, URL, element, muispositie en een screenshot.
3. Alles gaat naar Asana. De koppeling werkt in twee richtingen.

| Map | Wat | Status |
|---|---|---|
| [`wordpress-plugin/`](wordpress-plugin/) | WordPress-plugin *Site Feedback* | ✅ Klaar |
| `shopify-app/` | Losse Shopify-app | Gepland |

## WordPress-plugin installeren

Download `site-feedback.zip` van de laatste [release](../../releases). Upload die zip in WordPress via **Plugins → Nieuwe plugin → Plugin uploaden**.

Wat de plugin doet en hoe je Asana koppelt, staat in [wordpress-plugin/README.md](wordpress-plugin/README.md).

## Ontwikkelen

```bash
php wordpress-plugin/tests/asana-sync-test.php
```

De tests bootsen WordPress en Asana na, dus je hebt geen WordPress-installatie of Asana-account nodig. Bij elke push naar `main` controleert GitHub Actions dit:

- PHP-syntax
- JavaScript-syntax
- de tests

### Nieuwe versie uitbrengen

1. Verhoog het versienummer in `wordpress-plugin/site-feedback.php`. Het staat daar twee keer: in `Version:` en in `SFB_VERSION`.
2. Commit de wijziging en push een tag met hetzelfde nummer:

   ```bash
   git tag wp-v1.3.0
   git push origin wp-v1.3.0
   ```

3. GitHub Actions test de code, bouwt `site-feedback.zip` (zonder de tests) en zet die in een nieuwe release.
