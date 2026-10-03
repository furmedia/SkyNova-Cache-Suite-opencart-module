# Instalare și operare

Versiune 0.3.0 development preview. Instalați întâi într-un staging cu backup. Alegeți arhiva familiei corecte: OC2.3, OC3 sau OC4. Pentru OC4 păstrați exact numele `furmedia_cache.ocmod.zip`; platforma îl folosește ca identificator.

## Cerințe

- PHP compatibil cu versiunea OpenCart și cu tema. Testele nucleului: PHP 5.6/7.4/8.2/8.3.
- Directorul `DIR_CACHE` trebuie să fie în afara document root pentru cache privat. Modulul refuză activarea dacă verificarea nu confirmă această separare. Mutați storage prin mecanismul platformei și actualizați configurațiile corect; modulul nu mută automat fișierele magazinului.
- Spațiu liber: implicit minimum 128 MiB rezervă; limitele cache-ului privat și resurselor generate sunt câte 64 MiB. Păstrați spațiu și pentru DB, upload și backup.
- GD cu WebP/AVIF pentru conversia respectivă; fără suport, imaginile originale rămân funcționale. cURL și certificate CA valide pentru PageSpeed/încălzire.

## Pași

1. În Extensions → Installer încărcați arhiva pentru platformă; pe OC4 instalați extensia încărcată conform interfeței native.
2. În Extensions → Modules instalați și deschideți SkyNova Cache Suite. Nu sunt necesare patch-uri de core.
3. Modulul pornește dezactivat, în observare. Verificați diagnosticul directorului privat și spațiul disponibil.
4. Activați pe staging cache-ul privat și testați două sesiuni, contul, coșul și checkout-ul. Pagini personale/răspunsuri nepotrivite sunt excluse.
5. Activați optimizările pe rând. În Journal păstrați modul protector: tema își gestionează minificarea și lazy loading. Cache-ul Journal și noile opțiuni nu trebuie configurate fără teste.
6. Măsurați înainte/după; nu există garanție de scor 100 sau de creștere SEO.

Journal local 3.2.10 s-a oprit la verificarea licenței. Este necesar un staging activat legitim pentru finalizarea verificării frontend; nu trimiteți cheile în chat.

## Optimizare

Minificarea operează pe resurse locale eligibile. Resursele externe, SRI și CSS cu @import sunt păstrate. Defer folosește o listă explicită: activați-l numai după verificarea dependențelor JS. Critical CSS poate fi extras automat cu workerul separat, apoi introdus în panou. CDN-ul trebuie să fie HTTPS și configurat efectiv să servească resursele. Pentru Nginx există un exemplu separat; regulile Apache generate vizează numai resurse statice cu hash, nu pagini HTML.

## CRON opțional

Exportați descriptorul runner din panou și salvați-l în afara webroot, accesibil numai utilizatorului PHP. Ajustați lista URL și limitele. Copiați `tools/cron.php` și bibliotecile în structura proiectului dacă folosiți kitul sursă. Exemplu CLI: `php tools/cron.php --config=/cale/privata/cache-runner.json --action=gc` (acțiuni: gc, warm, purge). Încălzirea folosește o coadă persistentă și sitemap-uri de pe aceeași origine. Poate popula cache-ul comun numai dacă ruta, tema și contextul sunt eligibile.

`tools/remote-cron.php` este opțional și nu se instalează cu modulul. Necesită POST HTTPS, Bearer aleatoriu de 32–128 caractere URL-safe, hash SHA-256 al secretului în `FURMEDIA_CRON_SHA256` și calea descriptorului privat în `FURMEDIA_RUNNER_CONFIG`. Configurați prin mecanismul securizat al serverului. Fără configurare răspunde 503; are limitare de frecvență și blocare concurență. Nu puneți secretul în URL. Integrarea pe hosting nu este testată.

## PageSpeed

Butonul din admin trimite explicit URL-ul public al magazinului către Google PageSpeed Insights v5. O cheie opțională se citește din `FURMEDIA_PAGESPEED_KEY`, nu din setările exportate. Serviciul extern și cotele sale pot limita rezultatul. Clientul este implementat, dar un raport real de la Google nu a fost verificat în această livrare.

## Revenire și dezinstalare

Dezactivați modulul și goliți cache-ul propriu. Dezinstalarea elimină evenimentele/setările modulului; nu șterge global cache-urile altor extensii. Fișierele statice cu hash pot rămâne pentru paginile deja deschise/CDN. Nu ștergeți întregul `image/cache` sau storage. Păstrați backup-ul până la verificarea magazinului.

Configurarea Redis/Memcached, cache-ului comun, Cloudflare/S3, workerului critical CSS și cozii sitemap: [ADVANCED-RO.md](ADVANCED-RO.md).
