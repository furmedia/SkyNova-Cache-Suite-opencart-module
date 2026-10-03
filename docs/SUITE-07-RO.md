# SkyNova 0.7 — preview de dezvoltare

Această versiune adaugă arhive DB reluabile, programări de mentenanță, profilare SQL agregată, comparații EXPLAIN, driver-e cache personalizate, afișări agregate și cache PWA pentru resurse publice generate. Indexul auxiliar de căutare este experimental și limitat. Cerința de echivalență integrală cu toate extensiile de referință rămâne deschisă.

## Arhive și restaurare

Din magazinul principal selectează un tabel nativ și începe arhiva. Păstrează ID-ul afișat și execută loturile până la `ready`. Pauza și reluarea persistă între cereri. Cota implicită de fișiere este 2 GB, configurabilă între 64 MB și 32 GB; limita unui lot este 8 MB. Tabelul trebuie să aibă cheie primară și maximum 512 coloane exportate. Nu există vechea limită de 10.000 de rânduri pentru acest flux.

Prima operație creează o copie stabilă în MySQL, sub blocare pentru scrieri. Necesită privilegii CREATE, INSERT, LOCK TABLES, SELECT și DROP, spațiu suplimentar în baza de date și poate dura mult. Exportul ulterior folosește cheia primară și loturi de maximum 1.000 de rânduri. Copia este per tabel; nu este un snapshot tranzacțional al întregului magazin. Datele sunt codificate base64, piesele au SHA256, iar metadatele sunt autentificate cu cheia privată a instalării. Arhivele sunt locale instalării, fără import arbitrar de SQL.

Pregătirea restaurării creează un tabel separat și verifică fiecare lot după inserare. Înlocuirea cere confirmarea formularului și mentenanța nativă OpenCart activă. Un RENAME atomic păstrează tabelul anterior pentru revenire. Tabelele cu chei externe sau trigger-e sunt refuzate pentru înlocuire. Nu șterge manual tabelele `skynova_prev_*` sau `skynova_replaced_*` înainte de a verifica restaurarea și politica de retenție. Restaurarea nu oprește automat cronurile externe sau integrările care scriu direct în DB; administratorul trebuie să le oprească în fereastra de mentenanță.

Conversia nouă MyISAM → InnoDB cere o arhivă finalizată și verifică sub blocare că sursa nu s-a schimbat. Vechea conversie rapidă și curățarea datelor vechi din secțiunea 0.6 își păstrează limita de 10.000 de rânduri / 16 MB; nu sunt același flux.

## Programări și măsurare

Salvează programările ca JSON în setări, de exemplu:

```json
[{"id":"catalog-daily","action":"ANALYZE","tables":["oc_product"],"at":"03:30","timezone":"Europe/Bucharest","days":[1,2,3,4,5,6,7],"enabled":1}]
```

Maximum 20 de reguli, cinci tabele per regulă și cinci operații per invocare. Acțiunile sunt ANALYZE, OPTIMIZE și backup. Runnerul CRON existent execută numai sloturile scadente; trebuie configurat și apelat de hosting. Pauza, progresul, trei încercări cu interval și istoricul persistă. Schimbarea orei de vară nu dublează slotul unei zile. OPTIMIZE poate bloca accesul.

Profilarea păstrează maximum 200 de mostre SELECT per cerere și 256 de forme agregate, fără valori SQL literale. Recomandările prioritizează candidații de index existenți după interogările observate; nu demonstrează că fiecare candidat va accelera magazinul. EXPLAIN păstrează planul anterior al aceluiași SQL. Benchmarkul execută efectiv SELECT de trei ori și cere LIMIT final 1–100; LIMIT nu garantează un scan ieftin. Nu promitem un multiplicator de viteză.

## Căutare, driver-e și PWA

Indexul auxiliar necesită mbstring, collation suportată și spațiu pentru o copie a descrierilor și trigrame. Construiește-l în loturi din panou. Păstrează LIKE nativ și adaugă candidați pentru modele `%text%` de minimum trei caractere, fără wildcarduri interne. Interogările cu subinterogări, escape-uri sau aliasuri diferite rămân native. Prin urmare, nu optimizează toate interogările standard de listare și sortare OpenCart. Journal păstrează căutarea nativă. Modificările prin proxy invalidează indexul înainte de scriere; importurile sau SQL extern necesită dezactivare și reconstruire. Nu este acceptanță completă pe Journal.

Driver-ele personalizate se înregistrează prin fișierul de hosting privat `DIR_STORAGE/skynova-cache-drivers.php`; vezi `examples/custom-driver.php`. Formularul nu execută nume de clase primite de la utilizator. Driverul furnizează get/set și păstrează generațiile authoritative pe disc și fallbackul pe disc.

Afișările numără GET-uri HTML pe rutele publice configurate, fără IP, cookie sau identificatori de vizitatori. Nu reprezintă vizitatori unici. Cererile servite complet de cache-ul LiteSpeed nu ajung la numărătoarea PHP.

Workerul PWA este opțional și cache-uiește numai fișiere generate cu nume hash de 64 de caractere, CSS/JS/WebP/AVIF, același origin, fără query string, răspuns 200 cu antete public și immutable. Nu cache-uiește HTML, cont, coș sau checkout. Necesită HTTPS ori loopback securizat; configurația serverului trebuie să emită antetele resurselor. Ocolește worker-ele existente cu scope suprapus. Invalidarea pe server schimbă generația la următoarea actualizare online a workerului; nu șterge instantaneu dispozitivele offline. Controlul din panou șterge numai cache-urile declarate din browserul administratorului și namespace-ul propriu SkyNova.

## Dovezi și limite rămase

- Test MySQL local: 12.001 rânduri, arhivă peste 16 MB, date binare și NULL, pauză/reluare, verificare, înlocuire, undo și refuz metadate modificate.
- Căutare: 22 de verificări locale, inclusiv majuscule/diacritice și invalidare la UPDATE; nu reprezintă benchmark pe un catalog de producție.
- Panouri native: 17 verificări HTTP pe OpenCart 3 și 17 pe OpenCart 4.
- Chromium: cinci verificări PWA, inclusiv offline pentru resurse, excluderea checkout/cont și păstrarea cache-ului altei extensii.

Acceptanța reală Journal 3.2.10 nu este realizată: captura existentă a stagingului raportează `License Error`. Nu modificăm validarea licenței. Nici paritatea integrală Nitro/LSCache, optimizarea tuturor interogărilor sau compatibilitatea tuturor extensiilor nu sunt certificate prin aceste teste.
