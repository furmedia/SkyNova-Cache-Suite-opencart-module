# Reguli avansate și DB — 0.6

Toate opțiunile noi sunt dezactivate sau goale implicit. Verifică tema în staging înainte de activare. Protecția Journal dezactivează transformările suplimentare de resurse/multimedia; nu reprezintă verificarea frontendului Journal cu licență activă.

## Reguli și resurse

Condiții JSON, prima regulă potrivită: `[{"route":"product/category","get":{"page":2},"session":{"currency":"RON"},"ttl":120}]`. `enabled:false` exclude acea cerere. Config acceptă store/language/customer group/currency/theme; sesiunea acceptă language/currency. POST, coșul, checkout-ul și rutele excluse nu devin admise prin aceste reguli. TTL 10–86400 secunde; maximum 100 reguli. Izolarea nativă a sesiunii/contextului rămâne.

Înlocuiri exacte: `[{"find":"OLD TEXT","replace":"NEW TEXT","type":"html","route":"common/home"}]`. Pentru o resursă locală: `[{"find":"#123456","replace":"#abcdef","type":"css","url":"catalog/view/theme/default/stylesheet/stylesheet.css"}]`. Ruta și URL-ul sunt opționale. Fișierul original rămâne intact; copia CSS are URL-uri rebazate. Resursele excluse/SRI/nonce rămân native. Înlocuirile nu sunt analiză semantică: verifică rezultatul și nu folosi fragmente generale care afectează formulare sau cod dependent. CSS/JS nu acceptă delimitatori HTML în reguli. Câmpurile au maximum 16 KB.

Atribute: `{"catalog/view/javascript/independent.js":{"defer":"true"},"catalog/view/theme/default/stylesheet/print.css":{"media":"print"}}`. Sunt permise media/fetchpriority/referrerpolicy/crossorigin/defer/async. Atributele existente nu se suprascriu; false/0 pentru defer/async nu adaugă atributul boolean.

Amânare: `{"catalog/view/javascript/independent.js":{"event":"pointerdown","ms":5000}}`. Evenimente: pointerdown, keydown, scroll, load; timer 0–30000 ms reprezintă limita maximă de așteptare. Necesită fișier local sau import local verificat, script clasic fără async/defer/type/nonce/SRI și fără document.write/currentScript/tokenuri/eval. Dependențele trebuie verificate separat; timere diferite nu garantează ordinea dependențelor. În lipsa punctului de inserție head/body scriptul rămâne nativ. Codul personalizat se inserează la top/bottom; nu este aprobat automat pentru checkout.

Video/audio fără autoplay primesc `preload="none"`. Aceasta reduce preîncărcarea, fără eliminarea sursei și fără a bloca redarea la cererea utilizatorului. Blocurile selectate prin ID exact primesc `content-visibility:auto`; DOM-ul și conținutul rămân prezente. Suportul de randare depinde de browser.

Import extern: URL HTTPS public + SHA256 așteptat + css/js. Maximum 2 MB, DNS verificat de HttpClient, fără redirecturi/cookies private. CSS cu @import este refuzat; referințele relative sunt transformate în URL-uri ale originii. Imaginile/fonturile nu se descarcă automat. Conținutul importat rămâne administrat de tine: schimbarea bibliotecii externe cere hash și import nou. Nu importa resurse fără drepturile necesare. URL-urile confidențiale/cu tokenuri sunt refuzate.

## DB

APCu și Memcache cer extensiile PHP respective; în lipsa lor se folosește discul. Memcache folosește host/port din seiful comun și nu suportă SASL; Memcached oferă integrarea SASL existentă. Driverul personalizat PHP poate fi injectat prin API-ul CacheStore; nu există încă selector de clase terțe în admin. Conexiunile reale APCu/Memcache nu au fost disponibile în fixture: fallback-ul a fost verificat.

Profilarea SELECT păstrează digest, formă anonimizată, moment și durată; maximum 30 mostre/request și 30 în istoric. Prag 0–5000 ms. Pentru operațiile admin catalog activează din magazinul principal și reinstalează extensia la upgrade pentru evenimentul nou. Nu se păstrează valorile căutate. Profilarea este diagnostic, nu cache general de SQL și nu rescrie interogările.

EXPLAIN acceptă un SELECT catalog din lista fixă, inclusiv JOIN între tabelele admise. Funcțiile, subinterogările, comentariile, tabelele private, accesul în alte baze și efectele secundare sunt refuzate. EXPLAIN obișnuit nu execută SELECT-ul. Interogările reale trebuie introduse de administrator; modelele anonimizate nu sunt executabile direct. Compară planul înainte/după aplicarea selectivă a indexurilor. Indexul pe nume/model poate ajuta prefixe și sortare; nu garantează accelerarea căutărilor `LIKE '%text%'`. Nu sunt promise accelerări pentru orice catalog sau cifre preluate din marketing.

Inventarul afișează motor, rânduri estimate, dimensiune și spațiu liber. ANALYZE actualizează statisticile optimizerului; OPTIMIZE poate reconstrui și bloca tabelul. Maximum cinci tabele selectate/operație. ANALYZE programat este opt-in, cel mult zilnic, prin remote CRON autorizat al magazinului principal. Runnerul CLI fără conexiune OpenCart nu execută această mentenanță. Ora exactă depinde de programarea CRON. Markerul zilnic folosește un director separat, păstrat la purjarea paginilor.

Conversia acceptă numai MyISAM → InnoDB, cu lock și backup privat înainte de ALTER. Copia JSON păstrează SHOW CREATE TABLE și rândurile. Limită 10.000 rânduri / 16 MB per backup: tabelele mai mari sunt refuzate înainte de modificare și cer dump/mentenanță externă. Restaurarea backupului este manuală, nu există încă restaurare DB prin buton. Nu se promit conversii automate ale cataloagelor foarte mari.

Curățarea cere previzualizarea aceleiași selecții și checkbox de confirmare, permisiune modify și nonce. Prag de păstrare 30–3650 zile pentru customer_activity/customer_search, coșuri anonime vechi și sesiuni expirate vechi. Clienții autentificați și sesiunile recente/active rămân. Ștergerea se limitează la ID-urile salvate în backup și reverifică vechimea. Tabelele lipsă sau schema diferită sunt refuzate. Datele efectiv șterse pot diferi de numărul din previzualizare dacă magazinul se schimbă între cereri. Nu se șterg comenzi, clienți sau produse.

Sursele de comparație: [Buslik Cache](https://www.opencart.com/index.php?route=marketplace/extension/info&extension_id=43755), [Database Leaf](https://www.opencart.com/index.php?route=marketplace/extension/info&extension_id=40911). Implementare originală; codul comercial al acestor module nu este inclus. Funcțiile viitoare SEO/301 din descrierea Leaf nu sunt tratate ca funcții livrate.
