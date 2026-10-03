from pathlib import Path
import requests,json,subprocess
ROOT=Path(__file__).resolve().parents[1];WORK=ROOT/'work/local-stage';PHP='C:/laragon/bin/php/php-8.3.32-Win32-vs16-x64/php.exe'
subprocess.run([PHP,str(ROOT/'tests/store-fixture.php')],check=True)
try:
 auth=json.loads((WORK/'browser-session.json').read_text());store=json.loads((WORK/'multistore-fixture.json').read_text())['store_id'];admin=requests.Session();admin.cookies.update(auth['cookies'])
 url='http://127.0.0.1:8796/admin/index.php?route=extension/module/furmedia_cache&user_token='+auth['token']
 def post(store,op,extra={}):
  r=admin.post(url+'&store_id='+str(store),data={'fm_nonce':auth['nonce'],'operation':op,**extra},timeout=30);assert r.status_code==200,'Native multistore HTTP status '+str(r.status_code);return r
 original=post(0,'export').json()
 post(store,'save',{'settings[status]':0,'settings[mode]':'observe','settings[ttl]':987})
 assert post(store,'export').json()['ttl']==987,'Store-specific settings persist'
 assert post(0,'export').json()==original,'Default store settings isolated'
 assert post(store,'runner').json()['origin'].endswith('/fixture-store/'),'Runner uses selected store origin'
 invalid=admin.get(url+'&store_id=999999999',timeout=30);assert invalid.status_code==400,'Unknown store refused'
 (ROOT/'docs/validation/multistore-http.json').write_text(json.dumps({'checks':['Store-specific save/export','Default store unchanged','Runner selected origin','Unknown store refused'],'environment':'isolated OC3.0.5.1 real DB','production_changed':False},indent=2))
 print('PASS 4 multistore checks')
finally:subprocess.run([PHP,str(ROOT/'tests/store-fixture.php'),'remove'],check=True)
