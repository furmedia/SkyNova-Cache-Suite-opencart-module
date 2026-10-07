# Compatibilitate verificată

Data testelor: 3 octombrie 2026. Pachet: 0.6.0 development preview.

| Mediu | Verificare efectuată | Rezultat |
|---|---|---|
| PHP 5.6.40, 7.4.33, 8.2.32, 8.3.32 | Lint PHP și 263 aserțiuni ale nucleului pe fiecare runtime | Trecut |
| OpenCart 2.3.0.2 / PHP 5.6.40 | Clase/evenimente native, 52 aserțiuni; sesiune/coș simulate | Trecut |
| OpenCart 3.0.5.0 și 3.0.5.1 / PHP 8.3.32 | Clase/evenimente native, 52 aserțiuni pentru fiecare | Trecut |
| OpenCart 4.0.2.3 / PHP 8.3.32 | Clase/evenimente native, 49 aserțiuni | Trecut |
| OpenCart 4.1.0.3 / PHP 8.3.32 | Clase/evenimente native, 48 aserțiuni | Trecut |
| OpenCart 3.0.5.1, tema standard, MySQL 8.4.3 | Magazin izolat real: instalare ZIP, setări, MISS/HIT, vizitatori diferiți, produs/coș/checkout, purge, export, import invalid, dezinstalare/reinstalare | 51 verificări trecute |
| OpenCart 4.0.2.3, tema standard, DB reală | ZIP upload/install, salvare, MISS/HIT, sesiuni separate, coș bypass, dezinstalare | 34 verificări HTTP trecute |
| Cache comun OC3 | Două sesiuni, fragmente dinamice, cookies necunoscute, coș izolat, purge selectiv | 12 verificări trecute |
| Multistore OC3 | Salvare/export independent, origine runner, ID invalid | 4 verificări trecute |
| Date OC3 și OC4 | SQL selectiv, componente prin Loader nativ, izolare, indexuri reale, nonce și reaplicare | 23 verificări HTTP pe fiecare platformă |
| Browser premium | Panou responsive, controale noi, JS amânat/combinat/extras, CSS combinat și widgeturi cart izolate | 17 verificări trecute |
| Completări 0.4/0.5 OC3/OC4 | Resurse dinamice/antete, 404, două conturi reale, profil/logout, coș opt-in, widgeturi, loturi, seif, diagnostic pe sesiune, refuz ESI/CRON neautorizat | 73 verificări HTTP pe fiecare platformă |
| LiteSpeed / ESI | Protocol, semnătură, context, generație, refuz fără backend | Core verificat; niciun server Enterprise real conectat |
| Critical CSS | Extragere reală Chromium, două viewport-uri, fixture vizibil/nevizibil | Verificat; fără avertismente în fixture |
| Redis/Memcached, Cloudflare/S3 | Clienți/transport simulați, invalidare, fallback, payload, semnare și loturi | Serviciile/conturile reale nu au fost conectate |
| Panou admin desktop 1440px și mobil 390px | Browser real, capturi, fără overflow orizontal sau ID-uri duplicate | Verificat local |
| Journal 3.2.10 | Copiat numai în staging local; instalare admin și modificări native | Frontend blocat de Journal License Error; testarea completă nu este încheiată |

Fișiere probă: `validation/data-http.json`, `validation/data-oc4-http.json`, `validation/browser-premium.json`, `validation/php-matrix.json`, `validation/store-http.json`, `validation/benchmark-local.json`, `validation/admin-desktop.png`, `validation/admin-mobile.png`.

Benchmark istoric 0.1.0 (nu remăsurat pentru 0.3.0): 12 cereri măsurate per variantă după încălzire, aproximativ 222 ms mediană fără modul față de 89 ms cu HIT. Este durata răspunsului în mediul local PHP built-in/tema standard. Nu este un rezultat TrinityConcept, PageSpeed, Core Web Vitals sau o garanție comercială.

Testele nucleului pe PHP vechi nu înseamnă că orice versiune OpenCart/Journal rulează pe acel PHP. Respectați cerințele fiecărei platforme. OC1.5 și OC2.0–2.2 nu sunt suportate de instalatoarele actuale. OC2.3 și OC4.1 nu au încă test integral cu DB și browser. OC2.3 installerul vechi cere un mod SQL MYSQL40 care nu există în MySQL 8.4 local; nu am modificat installerul pentru a pretinde compatibilitate. OC4.0.2.3 are test HTTP cu DB reală. Fixture-ul Windows OC4 suprascrie session_path la / în configurația locală deoarece dirname pe Windows produce un cookie path incorect pentru rădăcina site-ului; sursa de referință și pachetul modulului nu sunt modificate de acest workaround.

Referința de magazin furnizată este TrinityConcept, OpenCart 3.0.5.1 și Journal 3.2.10. Descrierile istorice de mai sus privesc staging-ul; activarea și probele live ulterioare sunt documentate separat mai jos.

Acceptanța înainte de producție trebuie să includă Journal activat pe staging: pagină produs/categorie/căutare, filtre și AJAX, două sesiuni independente, clienți autentificați, monede/limbi/taxe, coș, cupoane, checkout, comenzi de test fără livrare/plată reală, PersistentCart și celelalte extensii instalate. Testați editarea prețului/stocului și invalidarea; comparați Lighthouse înainte/după în aceleași condiții.

Branding SkyNova Cache Suite. Capturile admin și testele au fost actualizate pentru 0.3.0; benchmark-ul rămâne explicit istoric. Identificatorii tehnici furmedia_cache și namespace-ul intern sunt păstrați pentru continuitatea instalării.

Dovezi suplimentare: `validation/shared-http.json`, `validation/multistore-http.json`, `validation/oc4-http.json`, `validation/browser-premium.json`, `validation/critical-css.json`.

Dovezi 0.4: `validation/completion-http.json`, `validation/completion-oc4-http.json`, `validation/php-matrix.json`. Testele din tabel pentru alte domenii includ baza verificată 0.3; fișierele probă păstrează versiunea și data proprie.

Politica statică de browser cache verificată pe un Apache 2.4.66 real, pornit separat pe loopback: 8 probe de antete, inclusiv excluderea HTML/PHP. Dovezi: validation/apache-policy.json. Nginx rămâne profil de integrare, fără probă pe server Nginx.

## Dovezi 0.6

- Nucleu: 300 aserțiuni pe fiecare PHP 5.6.40 / 7.4.33 / 8.2.32 / 8.3.32; 243 fișiere PHP lint/rulare, fără erori.
- Clase OpenCart native: 54 / 54 / 54 / 51 / 50 aserțiuni pe OC 2.3.0.2 / 3.0.5.0 / 3.0.5.1 / 4.0.2.3 / 4.1.0.3. Nu sunt teste de magazin integral pentru fiecare versiune.
- DB MySQL 8.4.3 locală: 25 probe cu tabele temporare proprii, EXPLAIN, ANALYZE/OPTIMIZE, programare zilnică, conversie MyISAM, backup înainte de ștergere, protecția coșurilor/sesiunilor și anonimizare. Tabelele fixture au fost eliminate la final.
- Admin/catalog HTTP: 15 probe noi pe fiecare OC3.0.5.1 / OC4.0.2.3; 73 probe de regresie completări pe fiecare. Configurațiile originale au fost restaurate.
- Chromium: 23 probe, inclusiv layout 1440/390 px, controalele noi, amânare per script, CSS/JS personalizat, video/audio și blocuri.
- Surse de dovezi: validation/advanced-db.json, validation/advanced-http.json, validation/advanced-oc4-http.json, validation/php-matrix.json, validation/browser-premium.json, validation/completion-http.json, validation/completion-oc4-http.json.

Importul extern este testat cu transport simulat și SHA256; rețeaua reală CDN nu a fost utilizată. APCu/Memcache: fallback verificat, servicii native indisponibile local. Programarea ANALYZE a fost verificată direct pe DB locală; CRON HTTPS de producție nu a fost executat. Nu există dovadă de accelerare pe catalogul real sau verificare frontend Journal activat.


## Activare TrinityConcept — 8 octombrie 2026

- SkyNova activat în mod `session`, protecție Journal activă. Cache de modele activat numai pentru cele șase citiri aprobate de categorie/informație/producător; CSS/JS, SQL arbitrar și integrarea CDN nu au fost activate automat.
- Matrix: 293 fișiere PHP fără erori pe PHP 5.6/7.4/8.2/8.3, 313 aserțiuni ale nucleului fiecare; cinci fixture-uri native OpenCart trecute.
- 323 verificări HTTP locale OC3/OC4 trecute: 51 store, 12 shared, 4 multistore, câte 23 data / 73 completion / 15 advanced / 17 suite pe OC3 și OC4. Testele locale cu conturi reale fixture nu înlocuiesc acceptanța conturilor live Journal.
- Live: 45 verificări, 36 trecute, 9 netrecute. Patru așteptări de HIT HTML nu s-au realizat; cinci cereri de categorie au depășit timeout-ul de 20 secunde. Rerulare categorie: HTTP 200 în 22,273 secunde, 4.821.306 bytes HTML, BYPASS-size. Nu se pretinde accelerare HTML a magazinului live.
- Homepage: BYPASS-response, token CSRF în blocul `extension/module/back_in_stock/subscribe` (`data-bis-csrf`). Protecția împiedică persistența acelui token în cache HTML. Categoria depășește și limita implicită de 1 MiB/pagină.
- Sesiuni guest separate: două Android, iPhone și desktop; produs/căutare/404, AJAX, query necunoscut, login/coș excluse, adăugare produs 6624 în coș propriu, izolare față de al doilea Android, intrare checkout fără comandă/plată și golire coș trecute. Nu s-au creat conturi, comenzi sau plăți.
- Cache de modele: patru cereri HTTP 200; intrările s-au stabilizat și metadatele au rămas neschimbate la cererile 3/4. Bara a raportat ulterior 102 intrări (0,5 MB), invalidate prin comanda de catalog. Acestea sunt date, nu HIT-uri HTML.
- Browser: homepage la 390 px, produs în emulare Android 390 px, categorie și filtru Bile/mărgele aplicat; fără Fatal error în paginile inspectate.
- Google PageSpeed din modul: HTTP 429; nu există scor nou verificat.
- Dovezi: validation/trinity-live-http.json, validation/trinity-model-http.json, validation/activation-http-matrix.json, validation/php-matrix.json, validation/trinity-product-android-active.jpg.

La prima etapă rămâneau fragmentul Back in Stock, reutilizarea între vizitatori și categoria supradimensionată. Corecțiile și probele ulterioare sunt documentate mai jos. Acceptanța live cu cont de test, PersistentCart, cupoane, limbi/monede/taxe și toate metodele checkout nu este încheiată. Cloudflare/S3/Redis/LSCache Enterprise necesită serviciile reale configurate înainte de a putea declara integrarea testată.

## Corecții și retestare TrinityConcept — 8 octombrie 2026

- Modul activ în `shared`, numai `common/home` și `product/category`, adaptor Journal 3 pe OC3 aprobat explicit. Antetul/subsolul se regenerează pentru fiecare sesiune; tokenul Back in Stock nu este persistat în partea comună. Coșurile, clienții autentificați și sesiunile personalizate sunt excluse.
- 45/45 verificări live și 13/13 verificări de partajare trecute. Două sesiuni independente Android primesc HIT, cu tokenuri CSRF diferite și validate față de propriul endpoint. Categoria păstrează AFS, nu mai conține filtrul Journal duplicat 36 și nu expune markerii interni.
- Categoria în proba finală: MISS 8,596 secunde, HIT 0,537 secunde, alt telefon/sesiune nouă HIT 0,551 secunde. Homepage la al doilea telefon: HIT 0,572 secunde. Sunt durate HTTP ale acestor probe, nu scoruri PageSpeed/Core Web Vitals sau garanții generale.
- Instanța duplicată Journal 36 este omisă numai dacă AFS este activ în mod integrat. Filtrul AFS Bile/mărgele a fost verificat în browser. Preîncălzirea manuală poate reexecuta paginile reușite fără a aștepta intervalul CRON; limita lotului, pauza și retry-ul erorilor rămân aplicate.
- PHP 5.6/7.4/8.2/8.3: 298 fișiere fără erori și 326 aserțiuni ale nucleului fiecare; încă 20 pentru admiterea Journal, 14 pentru navigare și 48 pentru linkurile autentificate fiecare. Fixture-uri native OC2.3/3/4 trecute; acestea nu certifică toate magazinele Journal.
- Google PageSpeed încă răspunde HTTP 429. Mesajul explică limita; retry-ul este oprit cinci minute și raportul anterior este păstrat. Este necesară o cotă Google disponibilă/cheie API configurată pentru un scor nou real.
- Dovezi: `validation/trinity-live-after-fixes.json`, `validation/trinity-shared-after-fixes.json`, `validation/php-matrix.json`, `validation/trinity-toolbar-after-fixes.png`. Nu au fost create conturi, comenzi sau plăți.

## Vizite mobile reale — corecții 0.7.2

Probele 0.7.1 verificau vizitatori cu numai cookie-urile native OpenCart. Un browser real poate avea și PHPSESSID, jrv, istoric Journal sau coș ocupat. Aceste sesiuni nu sunt admise în cache-ul comun; înainte, tokenul din footer împiedica și memorarea privată.

0.7.2 adaugă `journal_private_routes`, dezactivat implicit. Pe TrinityConcept sunt aprobate home/category/product și `cart:true` în regulile acestor trei rute. Cheia privată păstrează identitatea sesiunii, toate cookie-urile, toate datele sesiunii, produsele coșului, moneda, limba, taxele și grupul. Antetul/subsolul sunt native la fiecare HIT. Clienții autentificați și checkout-ul nu sunt activați prin această opțiune.

- 34/34 verificări live cu două Android sintetice, cookie-uri PHPSESSID/jrv, cinci vizite repetate de homepage/categorie/produs, tokenuri proprii și coș de test izolat. Produs MISS 2,474 s → HIT 0,999–1,074 s; categorie MISS 7,166 s → HIT 0,498–0,627 s; homepage cu coș HIT 0,472–0,539 s.
- Browser cu User-Agent Android și viewport 390×844, păstrând coșul utilizatorului: HIT session-fragments, TTFB 433,603 ms; header 263,12 ms, footer 57,45 ms. Identitatea și viewport-ul temporare au fost restaurate. Nu este o măsurare pe telefonul fizic al utilizatorului și nu este un scor CWV.
- Cookie-ul jrv reînnoit de Journal pe fiecare produs este ignorat numai pentru admiterea unui răspuns privat când valoarea sa este identică în cookie-ul cererii și istoricul nativ. Nu se salvează antete Set-Cookie. Alte cookie-uri/modificări continuă să refuze memorarea.
- Dovezi: `validation/trinity-mobile-private-http.json`, `validation/trinity-mobile-private-browser.json`, `validation/trinity-mobile-private-browser.png`. Rutele autentificate, filtrele AJAX și query-urile neaprobate rămân native. Adresa și starea contului de pe telefonul fizic nu au fost furnizate în timpul acestei probe.
