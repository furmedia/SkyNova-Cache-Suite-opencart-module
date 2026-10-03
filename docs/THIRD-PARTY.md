# Proveniență și licențe

Codul FurMedia este o implementare originală. Arhivele NitroPackIO/RocketBoost furnizate au fost folosite pentru inventariere și comparație funcțională. Nu sunt redistribuite și nu s-a copiat codul lor în modul. Conectorul NitroPackIO disponibil în arhivă nu este identic cu vechiul NitroPack local descris în cerere.

Biblioteci incluse cu licențele originale MIT:

- [matthiasmullie/minify](https://github.com/matthiasmullie/minify), 1.3.75, commit `76ba4a5f555fd7bf4aa408af608e991569076671`.
- [matthiasmullie/path-converter](https://github.com/matthiasmullie/path-converter), 1.1.3, commit `e7d13b2c7e2f2268e1424aaed02085518afa02d9`.

Licențele sunt în `src/core/vendor` în sursă și în directorul bibliotecii modulului în instalatoare. Detalii și hash-uri de achiziție: `docs/dependencies.json`. Sursele OpenCart și Journal utilizate local pentru teste nu sunt incluse în kit. Licența Journal nu a fost ocolită.

Referințe consultate: [RocketBoost marketplace](https://www.opencart.com/index.php?route=marketplace/extension/info&extension_id=42770), [OpenCart](https://github.com/opencart/opencart), [Google PageSpeed v5](https://developers.google.com/speed/docs/insights/v5/reference/pagespeedapi/runpagespeed). Celelalte pagini marketplace indicate, ID 31686/38433/35620, nu au putut fi citite (403); nu se pretinde că toate funcțiile lor au fost inventariate.

Integrările originale urmează documentația [PHP Memcached](https://www.php.net/manual/en/book.memcached.php), [Cloudflare purge API](https://developers.cloudflare.com/api/resources/cache/methods/purge/) și [AWS S3 SigV4](https://docs.aws.amazon.com/AmazonS3/latest/developerguide/sig-v4-header-based-auth.html). Workerul opțional folosește Playwright/Chromium instalat separat; aceste runtime-uri nu sunt incluse în ZIP.


Referință LSCache: [repository oficial](https://github.com/litespeedtech/lscache-opencart), pachet OC3 SHA256 `1b8ad5bba4ef4a411461da7e2158b88a40ba5389b920cd6e27c62e6ca20189e6`, GPLv3. Codul pachetului nu a fost copiat. Implementarea SkyNova folosește original protocolul documentat în [controale LiteSpeed](https://docs.litespeedtech.com/lscache/devguide/controls/), [ESI](https://docs.litespeedtech.com/lscache/devguide/advanced/) și compară funcțiile cu [setările modulului OpenCart](https://docs.litespeedtech.com/lscache/lscoc/settings/). Prima arhivă Nitro a fost regăsită în directorul datat 03.10.2026; nici materialele ei, nici Journal nu sunt redistribuite.

## Webkul — referință funcțională 0.5

Pagina Marketplace extension_id=31686 și documentația oficială https://webkul.com/blog/opencart-cache-system/ au fost consultate la 3 octombrie 2026 pentru comparația funcțională. Nu a fost copiat sau inclus cod Webkul. ModuleRules, Delivery, Management și FrontPanel sunt implementări originale SkyNova. Testele Apache folosesc instalarea locală existentă, fără a o redistribui în kit.
