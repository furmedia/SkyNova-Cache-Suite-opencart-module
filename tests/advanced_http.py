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
 check(all('name="settings['+f+']"' in panel.text for f in ('conditions','lazy_media','lazy_blocks','custom_css','custom_js','resource_attributes','delay_rules','sql_profile','db_auto_analyze','external_assets')),'advanced settings render natively')
 check('value="apcu"' in panel.text and 'value="memcache"' in panel.text,'memory choices render')
 check('fm-database-tools' in panel.text and 'fm-external-import' in panel.text,'maintenance and import panels render')
 result=post('db_inspect');check('oc_product' in result.text and 'name="db_tables[]"' in result.text,'native database grid populated')
 result=post('db_explain',{'explain_sql':'SELECT product_id FROM oc_product WHERE status=1 LIMIT 10'});check('Operațiune DB finalizată' in result.text,'native EXPLAIN read-only catalog')
 result=post('db_explain',{'explain_sql':'SELECT * FROM oc_customer'});check('read-only catalog SELECT' in result.text,'private table EXPLAIN rejected')
 result=post('db_analyze',{'db_tables[]':'oc_product'});check('Operațiune DB finalizată' in result.text,'native selected ANALYZE')
 result=post('db_convert',{'db_tables[]':'oc_product'});check('Confirm the selected database change' in result.text,'conversion requires checkbox')
 result=post('db_retention',{'db_confirm':'1','retention_kind':'activity','retention_days':'91'});check('Preview the same retention selection' in result.text,'retention requires matching preview')
 result=post('db_preview',{'retention_kind':'cart','retention_days':'90'});check('eligible' in result.text,'native retention preview without delete')
 result=post('external_import',{'asset_url':'https://cdn.example/a.js','asset_hash':'invalid','asset_type':'js'});check('expected SHA256' in result.text,'unverified external import refused before network')
 settings={**original,'status':1,'mode':'session','sql_profile':1,'sql_slow_ms':0,'conditions':json.dumps([{'route':'common/home','enabled':False}])}
 result=post('save',{'settings['+k+']':v for k,v in settings.items()});check('Configurația a fost salvată' in result.text,'new configuration saves')
 visitor=requests.Session();visitor.get(BASE);result=visitor.get(BASE);check(result.headers.get('X-FurMedia-Cache')=='BYPASS-condition','conditional disable bypasses native page cache')
 result=admin.get(BASE+'admin/index.php?route=catalog/product&user_token='+token);check(result.status_code==200 and 'Fatal error' not in result.text,'native admin product listing preserved while profiling')
 result=admin.get(url);check('SELECT' in result.text,'SQL profiling samples render natively')
finally:
 response=post('save',{'settings['+k+']':v for k,v in original.items()});assert 'Configurația a fost salvată' in response.text
(ROOT/'docs/validation'/('advanced-oc4-http.json' if v4 else 'advanced-http.json')).write_text(json.dumps({'scope':'isolated loopback OpenCart '+('4.0.2.3' if v4 else '3.0.5.1'),'checks':checks,'count':len(checks),'original_settings_restored':True},indent=2),encoding='utf8')
print('PASS',len(checks),'advanced HTTP assertions', 'OC4' if v4 else 'OC3')
