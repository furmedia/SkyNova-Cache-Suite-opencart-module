"""Small local comparison, not a production PageSpeed/Core Web Vitals claim."""
import json,requests,statistics,time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];WORK=ROOT/'work/local-stage';BASE='http://127.0.0.1:8796/'
auth=json.loads((WORK/'browser-session.json').read_text());admin=requests.Session();admin.cookies.update(auth['cookies'])
endpoint=BASE+'admin/index.php?route=extension/module/furmedia_cache&user_token='+auth['token']
def configure(enabled):
    r=admin.post(endpoint,data={'fm_nonce':auth['nonce'],'operation':'save','settings[status]':str(enabled),'settings[mode]':'session','settings[journal]':'1','settings[debug]':'1'},timeout=30)
    assert 'Configurația a fost salvată' in r.text
def sample():
    client=requests.Session();times=[];statuses=[]
    for i in range(15):
        start=time.perf_counter();r=client.get(BASE,timeout=30);elapsed=(time.perf_counter()-start)*1000
        assert r.status_code==200 and '</html>' in r.text
        if i>=3:times.append(elapsed);statuses.append(r.headers.get('X-FurMedia-Cache','disabled'))
    return {'requests':len(times),'median_response_ms':round(statistics.median(times),2),'min_ms':round(min(times),2),'max_ms':round(max(times),2),'cache_statuses':statuses}
configure(0);baseline=sample();configure(1);cached=sample();assert all(x=='HIT' for x in cached['cache_statuses'])
configure(0)
report={'environment':'Loopback PHP 8.3.32 built-in server, MySQL 8.4.3, OpenCart 3.0.5.1 default theme; native Journal hooks installed, Journal storefront not active','baseline':baseline,'cache':cached,'note':'Sequential warm requests; local HTTP total response, not field TTFB/LCP or production promise.'}
(ROOT/'docs/validation/benchmark-local.json').write_text(json.dumps(report,indent=2))
print(json.dumps({'baseline_median_ms':baseline['median_response_ms'],'cached_median_ms':cached['median_response_ms'],'cached_hits':12}))
