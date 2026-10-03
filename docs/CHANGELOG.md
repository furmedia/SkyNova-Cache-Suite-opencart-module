# 0.6.0 — Reguli avansate și mentenanță DB, development preview

- Adaptoare APCu/Memcache cu fallback pe disc și aceleași garduri de invalidare.
- Condiții GET/config/sesiune cu TTL sau dezactivare; nu autorizează rute excluse/POST.
- Video/audio preload none, randare întârziată a blocurilor prin content-visibility, cod CSS/JS personalizat.
- Înlocuiri exacte HTML/CSS/JS, opțional pe rută/URL; atribute aprobate pe resurse; eveniment și temporizare per script local independent.
- Import HTTPS CSS/JS verificat SHA256, maximum 2 MB, fără redirecturi; mapare locală salvată.
- Profilare SELECT anonimizată în catalog și administrarea catalogului; EXPLAIN catalog, propuneri suplimentare de indexuri pentru sortare/model/nume/SEO.
- Inventar tabele, ANALYZE/OPTIMIZE selectiv și ANALYZE zilnic opt-in prin remote CRON.
- Conversie MyISAM → InnoDB și curățare cu backup privat bounded; previzualizare/confirmare pentru curățare; protecția coșurilor autentificate și sesiunilor active.
- Operații verificate numai în mediul local; nu s-a modificat producția. Acest preview nu reprezintă certificare universală Journal.

# 0.5.0 — Completări Webkul, development preview

Control module/instanțe, inventar și golire individuală, panou frontend semnat, browser cache configurabil cu profil Apache/Nginx și backup, compresie XML/XHTML/RSS și gzip reutilizabil, resource hints, enqueue după login, mentenanță recuperabilă și calitate 0–100. Implementări originale, teste locale; limite detaliate în FEATURES.md.

# 0.4.0 — development preview

- Loturi persistente pentru imagini și pre-minificare, cu reluare/anulare/status și CRON.
- Inventar/extragere JS aprobată și poziționare, cache antete CSS/JS dinamice, recomandări PageSpeed.
- Seif criptat și configurare servicii din admin; upload S3 în pași.
- Reguli pe rută/URL, TTL, cache privat client/coș opt-in, 404, dispozitive/Safari, variante crawler și control coadă.
- Endpointuri native widgeturi AJAX, remote CRON autentificat, LiteSpeed privat și ESI semnat.
- Backendul LiteSpeed/ESI, Journal activat și extensiile terțe rămân explicit necertificate. Nu se declară paritate integrală cu serviciile comerciale NitroPack.

# SkyNova Cache Suite

## 0.3.0 — 3 octombrie 2026 — development preview

- Cache pe componente cu adaptor Loader pentru OC2.3/3/4, izolare pe sesiune și verificarea efectelor asupra contextului.
- Cache SQL selectiv pentru SELECT-uri exacte din tabele de catalog; invalidare la scrieri și bypass în tranzacții.
- Diagnostic de indexuri compuse, adăugare manuală cu verificarea schemei, idempotentă; istoric privat.
- Dezactivare opțională a numărării produselor în meniul nativ.
- Combinare opțională CSS/JS consecutive aprobate, cu ordine nativă și protecție Journal.
- Verificări PHP, clase native, HTTP cu DB reală și browser; detalii în COMPATIBILITY.md.

## 0.2.0 — 3 octombrie 2026 — development preview

- Cache comun opțional pentru tema standard, cu antet/subsol dinamice și fallback privat pentru contexte neeligibile.
- Accelerare Redis/Memcached, fallback disc, generații comune pentru invalidare și blocări la populare.
- Invalidare selectivă din panou; selector și setări multistore; contoare zilnice și istoric PageSpeed.
- Sitemap queue persistentă, aceeași origine, interval, backoff și reluare după întrerupere.
- Srcset WebP/AVIF, delay JS pe listă explicită și worker automat critical CSS pentru două viewport-uri.
- Cloudflare purge pe URL din admin; uploader CLI S3 SigV4 cu loturi și stare persistentă.
- Teste suplimentare de sesiuni independente, cache comun, multistore, OC4 real, browser și servicii simulate.

Nu este certificare completă Journal sau validare cu servicii cloud reale. Consultați FEATURES.md și COMPATIBILITY.md înainte de instalare.

## 0.1.0 — versiune inițială

Nucleu original, cache privat, invalidare, optimizări locale, interfață admin și adaptoare native OC2.3/3/4. Denumirea publică a fost schimbată din FurMedia Cache Suite în SkyNova Cache Suite la cererea utilizatorului.
