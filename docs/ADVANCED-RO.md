# Configurare avansată — 0.3.0

Toate opțiunile noi sunt inițial dezactivate. Folosiți staging. Selectați magazinul din panou înainte de salvare. Identificatorul tehnic `furmedia_cache` rămâne stabil pentru upgrade; denumirea publică este SkyNova Cache Suite.

## Cache comun

Alegeți modul comun și completați numai rutele auditate, de exemplu `common/home`. Tema standard folosește un corp comun, iar `common/header` și `common/footer` se execută nativ în sesiunea curentă. Clienții autentificați/coșul ocupat sunt excluși. Cookies sau date de sesiune necunoscute, Journal și alte teme folosesc varianta privată. HTML-ul livrat rămâne `private, no-store` pentru browser/CDN. Aprobarea unei rute presupune că extensiile nu inserează date personale în corpul ei.

## Redis / Memcached

Instalați extensia PHP `redis` sau `memcached` și configurați serviciul privat, apoi alegeți backend-ul în panou. Variabile: `SKYNOVA_CACHE_HOST`, `SKYNOVA_CACHE_PORT`, `SKYNOVA_CACHE_PASSWORD`, opțional `SKYNOVA_CACHE_USERNAME` pentru SASL Memcached și `SKYNOVA_REDIS_DB`. Valorile nu intră în exportul setărilor. Panoul arată backend-ul efectiv; dacă extensia lipsește/conexiunea eșuează, se folosește discul. Păstrați directorul privat în afara webroot. Într-un cluster, generațiile și blocările cer filesystem partajat; nu configurați discuri independente pentru aceeași instalare. Cotele serviciului se configurează separat.

## Sitemap și CRON

Completați URL-urile sitemap și căile paginilor în panou. Exportați descriptorul runner; salvați-l privat. Programați `php tools/cron.php --config=/private/cache-runner.json --action=warm` la fiecare minut. Intervalul din descriptor determină când fiecare URL este reîncălzit. Joburile eșuate sunt reluate de maximum trei ori, cu pauze crescătoare, apoi în ciclul următor. Joburile sunt separate după origine. Nu sunt acceptate alte domenii, adrese private prin clientul HTTP, tokenuri, admin, cont sau checkout. Lista se resetează când se schimbă configurația de intrare.

## Critical CSS automat

Pe un worker cu Node și Chromium: `npm install playwright`, apoi `npx playwright install chromium`. Rulați `node tools/critical-css.mjs https://magazin.ro/pagina critical.css`. Workerul deschide o sesiune anonimă, extrage reguli pentru elementele vizibile la 390×844 și 1440×1000 și scrie CSS plus un raport JSON. Nu folosiți URL-uri de admin sau cont. Introduceți CSS-ul rezultat în câmpul CSS critic din panou după verificarea vizuală. Limita este 256 KiB. Păstrați stylesheet-urile originale. Raportul avertizează când CSSOM nu poate citi resurse cross-origin; acest caz necesită completare/verificare manuală.

## JavaScript și imagini

Delay se activează numai pentru URL-urile exacte ale unor scripturi independente. Nu includeți coș, plată, consimțământ, jQuery sau scripturi cerute imediat de cod inline. Ordinea scripturilor aprobate se păstrează, dar dependențele nu sunt deduse automat. Bootstrap-ul delay necesită permisiunea CSP pentru scriptul inline; pe CSP strict păstrați această opțiune dezactivată până la integrarea politicii. Pentru srcset activați WebP/AVIF și imagini responsive; configurați `sizes` conform layout-ului. Nu se rescriu variantele deja produse de temă.

## Cloudflare

Configurați în mediul PHP `SKYNOVA_CF_ZONE` și `SKYNOVA_CF_TOKEN`, cu permisiune Cache Purge pentru zona respectivă. Pentru magazinul ID 2 folosiți sufixul `_2`. Butonul din panou trimite numai URL-urile HTTPS enumerate, maximum 30, din magazinul selectat. Nu există purge global automat în Cloudflare.

## S3

Configurați `SKYNOVA_S3_BUCKET`, `SKYNOVA_S3_REGION`, `SKYNOVA_S3_ACCESS_KEY`, `SKYNOVA_S3_SECRET_KEY`, opțional `SKYNOVA_S3_SESSION_TOKEN`. Bucket-ul trebuie să existe; limitați drepturile la prefixul `image/cache/furmedia_cache/`. Rulați `php tools/s3.php --directory=/shop/image/cache/furmedia_cache --state=/private/skynova-s3-state --limit=20`. Sunt acceptate numai fișierele generate cu nume SHA-256 și extensii CSS/JS/WebP/AVIF; starea permite continuarea lotului. Nu se schimbă ACL/policy și nu se creează distribuții. Un CDN numai cu acest bucket nu poate servi automat resursele originale referite de CSS: configurați originea/fallback-ul înainte de activare.

## Validare externă

Cheile se păstrează în mediul serverului sau în seiful proiectului, niciodată în chat. Livrarea nu a efectuat apeluri către conturi Cloudflare/S3, nu a conectat servicii Redis/Memcached reale și nu a modificat producția. Configurarea unui serviciu nu este echivalentă cu verificarea lui.

## Funcțiile de date din 0.3

Activează separat SQL selectiv și cache pe componente după verificarea în staging. Ambele funcționează numai pe cereri anonime eligibile pentru cache, după startup, și sunt izolate pe sesiune chiar în modul de pagini comune. Lista componentelor conține rute exacte, de exemplu `extension/module/html` în OC3 sau `extension/furmedia_cache/module/example` în OC4. Argumentele modulului și contextul paginii participă la cheie. Nu aproba blocuri cu efecte externe (fișiere, servicii, analytics) sau personalizare necunoscută; adaptorul poate verifica sesiunea/documentul/antetele, nu toate efectele unui modul terț. Blocurile cu formulare/tokenuri sau schimbări de stare se păstrează native. Adaptorul păstrează evenimentele Loader; extensiile care cer explicit clasa nativă DB/Loader trebuie testate.

Lista SQL acceptă câte o interogare exactă pe linie, de exemplu `SELECT * FROM oc_category WHERE category_id = '20'`, cu prefixul real. Nu folosi date personale. Sunt eligibile numai category, category_description, category_path, manufacturer, manufacturer_to_store, information, information_description și information_to_store. JOIN, subinterogări, funcții, comentarii, SELECT FOR UPDATE și interogări multiple sunt excluse. Scrierile executate prin adaptor invalidează cache-ul; tranzacțiile îl ocolesc. Conexiunile DB directe din extensii terțe sau importuri externe cer golirea cache-ului după modificare. Panoul arată separat HIT SQL și HIT componente. Salvarea setărilor invalidează ambele.

Butonul Analizează indexurile citește schema fără modificări. Propunerile se bazează pe câteva tipare uzuale, nu pe profilarea interogărilor magazinului; verifică EXPLAIN și costul pe staging. Selectează maximum 5 și aplică numai cele utile. Indicii cu aceeași ordine de coloane la început acoperă propunerea; ordinea inversă nu o acoperă. Operația reanalizează schema înainte de ALTER TABLE, nu elimină indici nativi și păstrează un istoric privat de maximum 30 operații. Indexurile sunt comune tuturor magazinelor din DB. DDL nu se anulează automat; în cazul unei erori parțiale, reanalizează schema. Dezinstalarea modulului păstrează indexurile.

Opțiunea Număr produse setează config_product_count=0 numai în cererea curentă, fără salvarea setării globale. Journal poate folosi o opțiune proprie; verifică meniul temei.

## Combinare CSS / JavaScript

Introdu URL-uri locale exacte în lista de combinare. Se combină numai taguri consecutive de același tip, păstrând ordinea și minificând rezultatul; un grup cu o resursă neaprobată rămâne nativ. CSS cu media diferit, @import sau @charset rămâne separat. Scripturile inline, modulele, SRI/nonce, async/defer, document.write/currentScript și fișierele cu use strict rămân separate. Listele trebuie auditate pentru dependențe și comportament; nu există analiză automată. Protecția Journal dezactivează combinarea pentru a păstra pipeline-ul temei. Măsoară înainte/după: reducerea cererilor nu garantează accelerare pe HTTP/2.


## Completări 0.4: loturi, reguli, servicii și LiteSpeed

Salvează întâi configurația, apoi pornește scanarea din Procesare în lot. Continuă manual sau exportă configurația runner și programează `php tools/cron.php --config=/private/runner.json --action=batch`. Un lot activ este reluat; originalele nu sunt modificate. După schimbarea setărilor, anulează și repornește lotul. Resursele generate se folosesc când opțiunile respective sunt active. Pentru a pre-minifica, activează minificarea CSS/JS înainte de scanare.

Reguli pe pagini (ruta trebuie să fie și în lista permisă):

```json
{"product/product":{"ttl":300,"enabled":true,"logged":false,"cart":false},"common/home":{"ttl":120,"enabled":true,"logged":false,"cart":true}}
```

Logged/cart nu autorizează partajare între clienți. Verifică aceeași sesiune, schimbarea cantității și grupului de client. Dacă ai personalizare dependentă de date externe neincluse în sesiune/coș, exclude ruta sau extinde contextul înainte de aprobare. Excluderile URL sunt căi exacte sau prefixe terminate în `*`; nu sunt expresii regulate și nu aprobă parametri necunoscuți.

Variante crawler:

```json
{"languages":["ro-ro","en-gb"],"currencies":["RON","EUR"],"agents":["Mozilla/5.0 Desktop fixture","Mozilla/5.0 iPhone Mobile Safari"]}
```

Folosește codurile active și user-agent-uri reale potrivite magazinului. Maximum 32 combinații. Gruparea desktop/mobil/Safari este separată și cere verificarea pipeline-ului temei. Pause/resume controlează și CRON pentru aceeași origine și același director de coadă. Nu transforma preîncălzirea privată în promisiune de HIT între vizitatori diferiți.

Inventarul JS citește o pagină HTTPS publică de pe același magazin și afișează numai hash, dimensiune și stare. Listează `sha256:HASH` pentru cod inline independent sau URL-ul exact pentru un script local independent. Extragerea se face automat la randare pentru codul aprobat. Poziția sus/jos mută numai aceste scripturi; verifică dependențele și interacțiunile în browser. Codul cu nonce/token/document.write și tagurile speciale rămân native.

Resurse dinamice: aprobă URI-ul complet din request, de exemplu `/index.php?route=extension/custom/module/styles`. Numai Content-Type CSS/JS, GET și context guest privat. Antetele ETag/Last-Modified sunt păstrate, cookies și antetele de redirect nu sunt memorate; browserul primește no-store. Dacă generatorul adaugă un cookie sau no-store explicit, se execută nativ.

Integrări externe: câmpul gol păstrează valoarea. Bifează ștergerea pentru a elimina valoarea locală. Datele se află în `DIR_CACHE/furmedia_cache-vault`, în afara webroot-ului, criptate și autentificate; cheia locală rămâne în același storage privat. Restricționează accesul la filesystem și la backup. ENV are prioritate. Valorile nu se exportă cu configurația și nu se completează în inputuri. Serviciile pentru magazinul ID 2 acceptă ENV cu sufix `_2`. Conexiunea Redis/Memcached este comună și se configurează din magazinul principal.

Remote CRON integrat: configurează `SKYNOVA_CRON_SECRET` (32–128 caractere URL-safe). URL-ul este afișat în Control preîncălzire. Trimite POST prin HTTPS, cu `Authorization: Bearer SECRET` în antet, o dată pe minut. Rularea execută warm, continuă lotul activ și GC. Nu pune secretul în query string. GET, HTTP sau lipsa autorizării sunt refuzate. Configurația și secretul se iau din server, nu din payload-ul apelantului.

LiteSpeed: modulul portabil funcționează și fără acest server. Adaptorul nativ rămâne oprit fără `SKYNOVA_LSCACHE_PROFILE=private-v1`. Acesta este un acord cu profilul de request lookup configurat de hosting, nu o configurare automată prin header. Exportă cerințele din panou și verifică izolarea cookies, User-Agent/Accept, rutele sensibile, GET, Authorization/AJAX/Range și invalidarea tagurilor publice atașate cache-ului privat. Nu seta profilul înainte de această probă. Nu există cache nativ HTML public în 0.4; HTML comun este servit de PHP cu antet/subsol native. Cache nativ guest privat și cache privat PHP pentru clienți/coș sunt funcții distincte.

Pentru Enterprise ESI setează suplimentar `SKYNOVA_LSCACHE_ESI=1` numai după verificarea forwarding-ului cookies și subrequesturilor. Configurare module:

```json
{"common/cart":{"scope":"no-cache","ttl":0},"extension/module/bestseller":{"scope":"private","ttl":60}}
```

Argumentele invocării native sunt capturate automat și semnate; `args` opțional poate restrânge invocările la o listă exactă de argumente. OpenCart 2/3 folosește un argument `$data`, OpenCart 4 lista variadică. Rutele nu pot deveni proxy-uri arbitrare. Public ESI este rezervat modulelor aprobate după audit, în context guest curat; coș/header/footer/Journal și modulele personale nu pot fi publice. Nonce/formulare/tokenuri păstrează fragmentul dinamic. Prima cerere fără sesiune stabilă rămâne nativă. Descriptorul este legat de generație și context, iar tampering-ul sau schimbarea sesiunii îl invalidează.

Widgeturile AJAX folosesc selectoarele temei standard și răspunsuri native din aceeași sesiune. Protecția Journal păstrează widgeturile Journal. Actualizarea rulează la început, periodic, la revenirea în tab și la `document.dispatchEvent(new Event('skynova:refresh'))`.

Diagnostic: activați `debug_details` pentru jurnalul limitat. Copiați digestul din antetul `X-SkyNova-Session` în `debug_session_hash` dacă doriți să urmăriți o singură sesiune. Lăsați selectorul gol pentru toate sesiunile; opriți `debug` pentru a opri antetele și înregistrările. Jurnalul păstrează doar ruta fără parametri, starea și magazinul.

## 0.5 — Module și livrare

Exemplu reguli componente (activează și `component_cache`):

```json
{"extension/module/featured":{"enabled":true,"ttl":120,"instances":{"module:12":{"ttl":20},"module:13":{"enabled":false}}}}
```

Pentru OC4 folosește ruta reală, de exemplu `extension/opencart/module/featured`. Datele native ale instanței trebuie să includă module_id/layout_id. Tagul `module:extension/module/featured` golește toate instanțele; sufixul `:module:12` golește doar instanța 12. Dacă un bloc modifică documentul, antetele sau sesiunea ori conține tokenuri/formulare, se execută nativ. Panoul Module permite activare și TTL per rută; instanțele se configurează în JSON.

Preload exemplu: `/catalog/view/fonts/shop.woff2|font`, câte o linie. Browser cache exportă directive statice, iar Aplică Apache salvează `.htaccess` cu backup în storage privat. Pentru Nginx integrarea și reload-ul serverului aparțin hostingului. Nicio setare nu transformă HTML personalizat în conținut public.

Panoul frontend este un instrument de staging; tokenul este legat de sesiune și generație. Countdown-ul nu declanșează golire globală. Dacă s-a schimbat generația, reîncarcă pagina pentru un token nou. Panoul dezactivează cache-ul comun și server-side LS pentru acea pagină.

Pentru reutilizarea gzip XML aprobă URI exact în `dynamic_urls`, activează dynamic_cache/compress_xml/reserve_compressed. XHTML și RSS au opțiuni proprii. Originalele și Content-Type sunt păstrate; `Accept-Encoding: gzip;q=0` primește corp necomprimat.

La login se pune coada publică în așteptare; nu se trimite cookie-ul clientului către worker. Mentenanța mută fișiere generate în arhiva privată, maximum 500/categorie/pas. După golirea OCMOD, refă modificările din Extensions → Modifications → Refresh. Restaurarea presupune copierea din arhiva privată în categoria inițială, numai dacă nu înlocuiești versiuni noi.

Vezi [Reguli avansate și DB 0.6](ADVANCED-06-RO.md) pentru noile meniuri și limite.
