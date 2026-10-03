"""New controls against own isolated OC3/OC4 HTTP fixtures, no production writes."""
from pathlib import Path
import requests,re,json,sys
ROOT=Path(__file__).resolve().parents[1];v4='--oc4' in sys.argv
BASE='http://127.0.0.1:'+('8797' if v4 else '8796')+'/'
route='extension/furmedia_cache/module/furmedia_cache' if v4 else 'extension/module/furmedia_cache'
private=json.loads((ROOT/'work/local-stage/private.json').read_text());admin=requests.Session();checks=[]
if v4:
 page=admin.get(BASE+'admin/index.php?route=common/login');login=re.search(r'login_token=([A-Za-z0-9]+)',page.text).group(1)
 result=admin.post(BASE+'admin/index.php?route=common/login.login&login_token='+login,data={'username':private['admin_user'],'password':private['admin_password'],'redirect':''}).json()
 token=re.search(r'user_token=([A-Za-z0-9]+)',result['redirect']).group(1)
 admin.get(BASE+'admin/index.php?route=extension/module.install&extension=furmedia_cache&code=furmedia_cache&user_token='+token)
else:
 result=admin.post(BASE+'admin/index.php?route=common/login',data={'username':private['admin_user'],'password':private['admin_password']})
 token=(re.search(r'user_token=([A-Za-z0-9]+)',result.url) or re.search(r'user_token=([A-Za-z0-9]+)',result.text)).group(1)
 admin.get(BASE+'admin/index.php?route=marketplace/extension/install&extension=module&code=furmedia_cache&user_token='+token)
url=BASE+'admin/index.php?route='+route+'&user_token='+token
panel=admin.get(url);nonce=re.search(r'name="fm_nonce" value="([^"]+)"',panel.text).group(1)
def post(op,extra=None):
 response=admin.post(url,data={'fm_nonce':nonce,'operation':op,**(extra or {})},timeout=40);response.raise_for_status();return response
def check(ok,name):
 if not ok:raise AssertionError(name)
 checks.append(name)
original=post('export').json()
try:
 check('fm-resumable-db' in panel.text,'resumable database controls render')
 check(all('name="settings['+f+']"' in panel.text for f in ('db_archive_mb','db_schedules','db_schedule_limit','view_stats','pwa_assets','pwa_cache_names')),'suite settings render')
 check('skynova-pwa-inspect' in panel.text,'browser-local PWA controls render')
 result=post('db_archive_commit',{'db_archive_id':'invalid'});check('Confirm the selected database change' in result.text,'table swap requires explicit checkbox')
 result=post('db_archive_commit',{'db_archive_id':'invalid','db_confirm':'1'});check('Enable native store maintenance' in result.text,'native maintenance required before table swap')
 result=post('db_archive_start',{'db_archive_table':'not_native'});check('native InnoDB/MyISAM table' in result.text,'foreign table archive refused')
 result=post('query_measure',{'explain_sql':'SELECT product_id FROM oc_product WHERE status=1 LIMIT 10'});check('measured_at' in result.text,'measured EXPLAIN comparison stored')
 result=post('query_benchmark',{'explain_sql':'SELECT product_id FROM oc_product WHERE status=1'});check('final LIMIT' in result.text,'unbounded benchmark refused')
 result=post('query_benchmark',{'explain_sql':'SELECT product_id FROM oc_product WHERE status=1 LIMIT 10'});check('avg_ms' in result.text,'bounded actual SELECT benchmark')
 result=post('maintenance_pause');check('paused' in result.text,'scheduler pause native action')
 result=post('maintenance_resume');check('Operațiunea a fost executată' in result.text,'scheduler resume native action')
 settings={**original,'status':1,'mode':'session','pwa_assets':1,'view_stats':1}
 result=post('save',{'settings['+k+']':v for k,v in settings.items()});check('Configurația a fost salvată' in result.text,'suite settings save')
 visitor=requests.Session();first=visitor.get(BASE);second=visitor.get(BASE);check('serviceWorker.register' in second.text,'worker registration on public native HTML')
 worker=visitor.get(BASE+'index.php?route='+route+('.worker' if v4 else '/worker'));check(worker.status_code==200 and 'skynova-assets-' in worker.text,'native worker endpoint')
 check(worker.headers.get('Service-Worker-Allowed')=='/' and 'javascript' in worker.headers.get('Content-Type',''),'worker scope and MIME headers')
 result=admin.get(url);check('common/home' in result.text and 'Afișări publice agregate' in result.text,'aggregate views appear in admin')
 denied=visitor.post(BASE+'index.php?route='+route+('.worker' if v4 else '/worker'),allow_redirects=False);check(denied.status_code in (403,405) and 'skynova-assets-' not in denied.text,'worker POST refused status '+str(denied.status_code))
finally:
 response=post('save',{'settings['+k+']':v for k,v in original.items()});assert 'Configurația a fost salvată' in response.text
(ROOT/'docs/validation'/('suite-oc4-http.json' if v4 else 'suite-http.json')).write_text(json.dumps({'scope':'own isolated native OpenCart','checks':checks,'count':len(checks),'original_settings_restored':True},indent=2),encoding='utf8')
print('PASS',len(checks),'suite HTTP assertions','OC4' if v4 else 'OC3')
