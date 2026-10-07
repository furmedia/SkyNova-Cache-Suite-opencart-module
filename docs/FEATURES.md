# SkyNova Cache Suite 0.6.0 — funcții și limite

Completări 0.6: APCu/Memcache cu fallback, condiții GET/config/sesiune, multimedia și randare de blocuri, import extern SHA256, înlocuiri pe rută/resursă, atribute, amânare per script și cod CSS/JS personalizat. DB: profilare anonimizată, EXPLAIN, inventar tabele, ANALYZE/OPTIMIZE, programare zilnică, conversie și curățare cu backup. [Exemple, funcții și limite](ADVANCED-06-RO.md). Conversia/curățarea internă refuză backupuri peste 10.000 rânduri / 16 MB. Nu există restaurare DB prin buton sau rescriere automată a tuturor interogărilor.

Versiune pentru staging. Completările au cod și teste locale; backendul LiteSpeed/ESI și compatibilitatea completă Journal nu sunt certificate. Panoul este original SkyNova. Arhiva NitroPackIO disponibilă este conectorul cloud, nu vechiul Nitro Cache local din descriere.

Actualizarea 0.7.1 include cache comun pentru vizitatori anonimi Journal 3 pe OC3, activat explicit pe rute aprobate. Antetul/subsolul final sunt delimitate în șablon și regenerate la fiecare HIT; tokenul Binoclo Back in Stock nu este memorat. Coșul, clienții, favoritele, cupoanele, cookie-urile și stările necunoscute refuză partajarea. Pentru TrinityConcept este recunoscut numai markerul temporal verificat al politicii locale de sesiune. Nu este o certificare pentru toate extensiile Journal.

Preîncălzirea manuală reîncarcă paginile deja finalizate chiar înaintea intervalului CRON; pauza, blocarea concurenței, limita lotului și backoff-ul erorilor rămân active. ID-urile de filtre Journal înlocuite pot fi aprobate individual, numai când AFS este activ în modul integrat. PageSpeed afișează clar HTTP 429 și aplică o pauză de cinci minute; scorul necesită acces real la serviciul Google.

| Completare 0.4 | Comportament | Condiții și probe |
|---|---|---|
| Procesare toate imaginile | Scanare incrementală în image, exceptând cache/symlinks; JPEG/PNG, WebP/AVIF și variante responsive; start/continuare/anulare/status în admin, reluare prin CRON | Originalele intacte; fișiere prea mari, nesuportate, deja mai mici sau fără GD sunt păstrate. Ferestre de 1.000 fișiere, maximum 10.000 directoare în așteptare, limite de disc existente. Teste core și HTTP |
| Pre-minificare | Aceeași coadă pentru CSS/JS din catalog și extension; resurse hash identice celor folosite la navigare | Respectă opțiunile salvate și excluderile, inclusiv Journal. Scanarea nu înseamnă activarea automată a optimizării |
| Extragere JS | Inventar inline din pagina publică, aprobare sha256, fișier JS separat; poziție nativă/sus/jos; scripturi externe aprobate în aceeași ordine | Nu deduce dependențe arbitrare. Tokenuri/nonce/document.write/module/SRI/atribute speciale rămân native; Journal protejat nu primește această transformare |
| CSS/JS dinamice | Cache privat pentru URI exacte aprobate; Content-Type, ETag, Last-Modified, Content-Language și Link memorate | GET guest, aceeași sesiune; fără cookies noi, redirect, compresie preexistentă, no-store sau alt Content-Type. Replay privat; test HTTP OC3/4 |
| Recomandări PageSpeed | Auditurile nereușite produc acțiuni concrete, secțiune și estimări de economii; prioritizare după timpul estimat | Măsurătoare Google, fără garanție de scor și fără modificări automate ale temei. Conversia raportului testată offline |
| Configurare servicii | Meniu pentru Cloudflare, S3, PageSpeed, Redis/Memcached și secret remote CRON; salvare criptată AES-CBC + HMAC în storage privat | Cheile nu apar în câmpuri/export. ENV are prioritate. Conexiunea cache se configurează pe magazinul principal; celelalte servicii sunt per magazin. Teste de roundtrip, modificare neautorizată a ciphertextului și HTTP |
| S3 din administrare | Urcă următorul fișier generat, cu evidență de reluare; CLI păstrat | Bucket/policy/CDN există deja; fără apel în cont cloud real |
| Reguli pagini și URL | TTL/activare pe rută; opțiuni explicite logged/cart; include/exclude URL exact sau prefix cu *; excluderi pentru clienți conectați | Întotdeauna izolat pe sesiune, client/grup, conținut coș, monedă și limbă. Cont/checkout/API nu pot fi aprobate prin reguli. Două conturi reale, homepage privat, schimbare profil, logout și cantitate coș testate HTTP pe OC3/OC4; checkout și extensiile terțe rămân în acceptanța staging |
| Cache 404 | TTL separat, status 404 păstrat la HIT pe rutele aprobate | Fără cache comun 404; HTTP real OC3/4 |
| Crawler variante | Produs cartezian limbă/monedă/user-agent, maximum 32 combinații; pause/resume/run/statistici; sitemap și reluare | Numai HTTPS public, origine validată. Cache privat nu încălzește sesiunea unui vizitator viitor. Partajarea cere rute și variații auditate |
| Desktop/mobil/Safari | Opțiune pentru grupe desktop/mobil/Safari/bot, în locul izolării după user-agent complet | Necesită verificare pentru tema aleasă; implicit user-agent complet |
| Widgeturi AJAX | Endpoint no-store din sesiunea curentă pentru cart/wishlist/compare; refresh periodic și eveniment skynova:refresh | Selectoare ale temei standard; protecția Journal păstrează widgeturile native. Două sesiuni testate HTTP; fără cache server pe endpoint |
| LiteSpeed nativ | Protocol cache privat guest, TTL, taguri publice pentru invalidare, vary, fingerprint de stare, purjare la schimbări; diagnostic și export cerințe profil | Necesită profil private-v1 configurat la request lookup înaintea PHP. Headerul HIT real aparține serverului. Nu activează cache nativ pentru clienți autentificați/coș ocupat sau HTML comun. Teste protocol/clase, nu server LiteSpeed real |
| ESI module | Public/private/no-cache, TTL pe modul, captură argumente native, descriptor semnat și legat de generație/context, fallback HTML | Enterprise ESI și profil verificate de hosting; public numai guest curat și module auditate, niciodată coș. Prima cerere fără sesiune stabilă păstrează randarea nativă. Endpoint semnat testat în nucleu, refuz fără server configurat testat HTTP |
| Remote CRON nativ | POST HTTPS cu Bearer din seif/ENV; warm + lot activ + GC; blocare și limită o rulare/minut | GET, HTTP și lipsa autentificării refuzate în testele HTTP. Secretul nu este pus în URL. Varianta CLI rămâne disponibilă |

Meniuri: Control general; LiteSpeed și ESI; Pagini și excluderi; CSS și JavaScript; Imagini și priorități; CDN; Date; Preîncălzire; Control coadă; Integrări; Inventar JavaScript; Procesare în lot; Diagnostic/PageSpeed; Indexuri; Mentenanță; Istoric.

Rămân verificări externe: Journal 3.2.10 activat (instalarea locală afișează License Error), PersistentCart și SEO Mega Pack reale, LiteSpeed Enterprise/ESI, Redis/Memcached reale și conturile cloud. Protecția Journal, restaurarea coșului după startup, conservarea URL-urilor nesuportate și separarea sesiunilor sunt implementate; acestea nu înlocuiesc verificarea fiecărei extensii. Serviciile comerciale NitroPack și interfața contului lor nu sunt recreate. Nu se declară paritate integrală cu toate versiunile istorice Nitro sau toate extensiile marketplace.

## Completările Webkul disponibile în 0.5

- Reguli JSON pentru maximum 100 rute de module, cu activare/TTL și maximum 100 instanțe per rută. Instanțele sunt identificate prin `module_id` sau `layout_id` primite de controllerul nativ. Panou de activare/TTL pe rută și șabloane dezactivate pentru Category, Latest, Featured, Information, Filter, Banner, Slideshow, Carousel și Store. Unele versiuni/teme nu includ toate modulele; șabloanele nu instalează module inexistente. Protecțiile pentru module cu formulare, efecte secundare și Journal rămân active.
- Golire după tag de modul sau instanță; inventar paginat, 200 fișiere/pagină cu ID opac, taguri publice, mărime, stare și expirare în fusul orar ales; golire individuală cu invalidarea generației inclusiv pentru backendurile remote.
- Panou frontend opțional pentru staging, cu countdown al expirării reale, poziție stânga/dreapta, culori și mărime font. Buton POST semnat pe sesiune/generație, valabil maximum 10 minute. Golește numai cache-ul privat al sesiunii; panoul forțează mod privat și dezactivează cache-ul LiteSpeed al paginii. Nu memorează panoul în HTML-ul stocat.
- Browser cache: durate separate imagini/CSS/JS/video/PDF/fonturi (0–31536000 secunde). Export Apache/Nginx și aplicare Apache în `.htaccess` cu backup privat, fără duplicarea blocului SkyNova. Politica este limitată la extensii statice, nu HTML/PHP. Nginx necesită integrarea profilului în configurația hostingului; Politica este verificată separat pe Apache 2.4.66 real; serverul PHP de test nu execută `.htaccess`.
- Prefetch și preload explicit pentru resurse publice; tipuri font/style/script/image/fetch, crossorigin pentru fonturi. Preconnect și preload imagine existente păstrate.
- Compresie XML/XHTML/RSS/Atom; negociere gzip cu respectarea `q=0`. Reutilizarea variantelor comprimate este opțională, pe storage privat și cu cotele existente; numai răspunsuri anonime admise și fără formulare/tokenuri. XML se reutilizează numai pe URI exact aprobate în resurse dinamice și antete/stare sigure. Variantele gzip nu se reutilizează pentru client/coș ocupat/panou.
- La prima cerere după login, opțiunea `warm_login` pune rutele publice configurate în coada existentă fără request blocant sau cookies ale clientului. CRON rulează coada. Aceasta nu încălzește pagini private în numele clientului; pagina sa curentă folosește regulile private existente.
- Mentenanță recuperabilă pentru cache imagini, loguri `.log`, OCMOD compilat și cache nativ: arhivează maximum 500 fișiere/categorie/pas în `DIR_STORAGE/skynova-maintenance`, păstrând originalele, indexurile, seiful, sesiunile și cache-ul intern SkyNova. Golirea SkyNova folosește operația separată de invalidare. După OCMOD folosiți Refresh nativ; fișierele arhivate se pot restaura din storage, fără suprascrierea fișierelor nou generate.
- Calitate WebP/AVIF configurabilă 0–100, păstrând originalul dacă rezultatul nu este mai mic.

Aceste funcții sunt implementări originale. Documentația Webkul este referință funcțională, nu cod sau instrucțiuni de instalare pentru SkyNova. Compatibilitatea cu fiecare modul terț, Journal, server LiteSpeed și conturile cloud rămâne dependentă de acceptanța pe staging.

## Baza disponibilă în 0.3


Versiune de dezvoltare pentru staging. Implementarea nu echivalează cu certificare pe orice magazin sau cu toate funcțiile istorice NitroPack.

| Domeniu | Disponibil în 0.3 | Limite / condiții |
|---|---|---|
| Cache HTML privat | Izolare per sesiune, magazin, limbă, monedă, grup, temă, cookies, HTTPS și client | Cont, coș ocupat, AJAX, POST, tokenuri și rute sensibile excluse |
| Cache HTML comun | Rute aprobate explicit; numai vizitatori anonimi ai temei standard; antet/subsol regenerate nativ la fiecare HIT | Journal și stările/cookies necunoscute revin la cache privat; fără cache înainte de bootstrap. Extensiile cu personalizare în corpul paginii cer audit înainte de aprobarea rutei |
| Redis / Memcached | Accelerare cu generații de invalidare și fallback pe disc; configurare prin variabile de mediu | Necesită extensia PHP și serverul respectiv. Fără administrarea serviciului; teste locale cu clienți simulați, nu servicii reale. Mai multe servere PHP necesită storage comun pentru generații |
| Concurență și limite | Scrieri atomice, TTL, cote, GC, blocare populare simultană | Cererea care nu obține blocarea este randată nativ; nu este servită o pagină expirată |
| Invalidare | Automată la mutații de catalog/configurare/comenzi; manuală globală sau după product/category/route | Invalidarea automată rămâne globală pentru siguranța listărilor; tagurile sunt comune magazinelor |
| Date | Metode read-only aprobate OC2/3; cache nativ păstrat | SQL selectiv separat OC2.3/3/4; model cache OC4 neactivat |
| CSS / JS | Minificare locală, hash, rebazare CSS; defer și delay cu liste exacte | Combinare opțională de resurse locale consecutive, aprobate exact; fără deducerea dependențelor. Delay: scripturi locale clasice independente, ordine serială, la interacțiune sau după 4 secunde; fără module/SRI/document.write. Journal își păstrează pipeline-ul |
| Cache pe componente | Rute de module aprobate explicit; adaptor Loader OC2.3/3/4; izolare pe sesiune, argumente și contextul paginii | Fără cont/coș/checkout; blocurile cu formulare/tokenuri sau efecte asupra sesiunii/documentului/antetelor se execută nativ. Rutele terțe cer verificare înainte de aprobare |
| SQL selectiv | SELECT exact aprobat, o tabelă de catalog din lista fixă, TTL 5–300 secunde; invalidare la scrieri prin adaptor | Fără clienți/comenzi/prețuri, JOIN/subinterogări/funcții/tranzacții; bibliotecile care păstrează conexiuni DB separate cer invalidare proprie |
| Indexuri DB | Diagnostic de indexuri compuse după ordinea coloanelor; aplicare manuală selectată, maximum 5, fără dublare la reaplicare | Propuneri fixe plus EXPLAIN și profilare SELECT anonimizată; fără rescriere automată SQL sau garanție de accelerare; DDL poate bloca tabele. Indexurile sunt comune magazinelor și rămân după dezinstalare |
| Număr produse în categorii | Dezactivare config_product_count pentru meniul nativ | Journal/modulul terț poate avea setare proprie; Observare nu schimbă setarea |
| Critical CSS | Generator automat separat Node/Chromium, două viewport-uri; import în câmpul CSS critic | Necesită worker extern și verificare vizuală. Nu elimină automat CSS-ul original, nu este serviciu cloud instalat în PHP. CSS cross-origin poate lipsi și este raportat |
| Imagini | WebP/AVIF pentru JPEG/PNG; srcset 320/640/960/1280 fără mărire; păstrează proporțiile și originalele | GD necesar; GIF/SVG, srcset existent și data-src Journal păstrate; sizes trebuie potrivit layout-ului |
| Lazy / priorități | Lazy nativ, dimensiuni, preload/preconnect manuale; primele două imagini exceptate | Fără detectare automată LCP |
| Browser / compresie | Cache immutable pentru resurse generate; gzip prin OpenCart | Fără HTML public la CDN; Brotli ține de server |
| CDN | Rescriere HTTPS generică / CloudFront | Originea CDN trebuie configurată separat |
| Cloudflare | Invalidare 1–30 URL-uri din magazinul selectat, buton admin cu permisiuni și nonce | Zona/tokenul prin mediu; payload și răspuns testate cu transport simulat; fără apel real în cont |
| S3 | Uploader CLI SigV4 pentru resursele generate, loturi și stare de reluare | Nu creează bucket/policy/CloudFront; nu urcă date originale sau private. Semnare/transport simulat testate; fără upload real. Resursele originale referite de CSS necesită origine CDN corespunzătoare |
| Preîncălzire | Sitemap și sitemapindex, aceeași origine, coadă persistentă până la 5.000 joburi, reluare cu backoff, interval, blocare | CRON extern necesar; maximum 50 cereri/rulare și buget de timp. Cache-ul privat nu se partajează prin warmup |
| Rapoarte | Contoare zilnice și ultimele 30 rapoarte PageSpeed; istoric runner pe disc | Contoare agregate pentru toate magazinele; PageSpeed separat per magazin. Recomandări automate din auditurile Google implementate/testate cu fixture; apelul Google real nevalidat |
| Multistore | Selector nativ; salvare/import/export și origine runner pe magazin | Directorul cache, limitele de disc și invalidările sunt comune instalării |
| Journal | Protecție împotriva dublei optimizări | Frontend Journal 3.2.10 încă blocat local de activarea licenței; cache comun Journal neactivat |

Înainte de o versiune comercială finală mai sunt necesare: verificări Journal activat și extensii terțe; test complet OC2.3 pe DB compatibil și OC4.1; probe pe Redis/Memcached și conturi cloud reale; audit al rutelor partajate; Lighthouse și încărcare concurentă pe staging; instalare/upgrade în configurații diferite. Analiza automată a dependențelor JS și cache SQL general pentru orice interogare și optimizarea responsive a pipeline-ului Journal nu sunt implementate.

### Diagnostic 0.4

Antete HIT/MISS/BYPASS și jurnal opțional de maximum 30 înregistrări per magazin (moment, stare, rută, magazin). Selectorul opțional SHA256 limitează diagnosticul la o singură sesiune; identificatorul brut, query string-ul și datele clientului nu sunt înregistrate. Testat HTTP cu două sesiuni pe OC3/OC4.

## Completări 0.7

Arhivele DB reluabile, programările, profilarea agregată, comparațiile EXPLAIN, driver-ele custom și PWA sunt documentate în [SUITE-07-RO.md](SUITE-07-RO.md). Indexul auxiliar are domeniu limitat, experimental. Paritatea integrală și acceptanța Journal nu sunt finalizate.
