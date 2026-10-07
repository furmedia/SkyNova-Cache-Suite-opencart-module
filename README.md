# SkyNova Cache Suite — 0.7.2 development preview

Implementare originală de cache și optimizare pentru OpenCart 2.3, 3 și 4. Această versiune este pentru staging: nu reprezintă încă paritate completă cu NitroPack și nici certificare universală Journal.

Adaugă procesare în lot și pre-minificare, extragere JS aprobată, antete CSS/JS dinamice, recomandări PageSpeed, seif criptat pentru configurarea serviciilor, reguli/TTL pe rută, cache privat cu client/coș aprobat explicit, 404, matrice crawler, widgeturi native AJAX și adaptor LiteSpeed/ESI cu profil de server obligatoriu. Include diagnostic/indexuri DB, cache SQL selectiv și pe componente, combinare CSS/JS aprobată, controlul numărării produselor, cache privat și cache comun cu fragmente dinamice pentru tema standard, Redis/Memcached cu fallback, invalidare selectivă, minificare/defer/delay, WebP/AVIF și srcset, generator critical CSS extern, Cloudflare/S3, coadă sitemap, rapoarte și configurare multistore. Modul este inițial dezactivat, în mod de observare.

- [Funcții și limite](docs/FEATURES.md)
- [Instalare și utilizare](docs/INSTALL-RO.md)
- [Configurare avansată](docs/ADVANCED-RO.md)
- [Compatibilitate și dovezi](docs/COMPATIBILITY.md)
- [Surse și licențe](docs/THIRD-PARTY.md)

Instalatoare: `dist/oc23/furmedia_cache.ocmod.zip`, `dist/oc3/furmedia_cache.ocmod.zip`, `dist/oc4/furmedia_cache.ocmod.zip`. Păstrați exact numele arhivei pentru OpenCart 4.

Generare: `python tools/build.py`. Teste locale: `python tools/validate.py` (căile PHP și sursele OpenCart se configurează în script). `tests/core.php` poate rula direct cu PHP. Fixture-urile native cer surse OpenCart originale; acestea nu sunt redistribuite. Testele HTTP cer mediul izolat pregătit de `tools/local_stage.py` și nu trebuie îndreptate către producție.

Niciun fișier din magazinele live nu a fost modificat. Datele private ale testelor sunt excluse din Git și din kit. Harta Graphify este AST-only.

Original OpenCart cache and performance suite targeting OpenCart 2.3, 3 and 4 with Journal-aware policies.

## Project metadata

- Type: `shared_library`
- Registry: `.projectbrain/project.toml`
- Architecture map: `graphify-out/` after Graphify is run

## Working rule

Start new work through Project Brain so conversations resolve this folder instead of creating unrelated files on Desktop.

Funcțiile 0.6 și exemplele noi sunt descrise în [Reguli avansate și DB](docs/ADVANCED-06-RO.md).

Completările 0.7 și limitele exacte sunt în [Arhive, programări și PWA](docs/SUITE-07-RO.md). Indexul auxiliar este experimental; acceptanța integrală Journal rămâne deschisă.
