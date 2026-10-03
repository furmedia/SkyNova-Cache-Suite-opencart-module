"""Native OC4 upload/install/settings/cache/cart/uninstall against isolated loopback fixture."""
from pathlib import Path
import requests,re,html,json,time
ROOT=Path(__file__).resolve().parents[1];BASE='http://127.0.0.1:8797/';WORK=ROOT/'work/oc4-stage'
private=json.loads((ROOT/'work/local-stage/private.json').read_text());admin=requests.Session();checks=[]
def check(ok,name):
 if not ok:raise AssertionError(name)
 checks.append(name)
def req(path,**kw):
 r=admin.request(kw.pop('method','GET'),BASE+path,timeout=40,**kw);check(r.status_code==200,'HTTP200 '+path.split('?')[0]);(WORK/'last-response.html').write_text(r.text);return r
page=req('admin/index.php?route=common/login');m=re.search(r'login_token=([A-Za-z0-9]+)',page.text);check(bool(m),'Native OC4 login token')
data=req('admin/index.php?route=common/login.login&login_token='+m.group(1),method='POST',data={'username':private['admin_user'],'password':private['admin_password'],'redirect':''}).json()
check(bool(data.get('redirect')) and not data.get('error'),'OC4 native login');token=re.search(r'user_token=([A-Za-z0-9]+)',data['redirect']).group(1)
def url(route,extra=''):return 'admin/index.php?route='+route+'&user_token='+token+extra
# Clean only this module through native installer APIs when re-running the isolated test.
listing=req(url('marketplace/installer')).text
for row in re.findall(r'<tr\b[^>]*>.*?</tr>',listing,re.S):
 if 'SkyNova Cache Suite' not in row:continue
 old=re.search(r'extension_install_id=(\d+)',html.unescape(row)).group(1)
 req(url('extension/module.uninstall','&extension=furmedia_cache&code=furmedia_cache'))
 cleanup=req(url('marketplace/installer.uninstall','&extension_install_id='+old)).json()
 while cleanup.get('next'):
  assert html.unescape(cleanup['next']).startswith(BASE)
  cleanup=admin.get(html.unescape(cleanup['next']),timeout=40).json()
 req(url('marketplace/installer.delete','&extension_install_id='+old))
with (ROOT/'dist/oc4/furmedia_cache.ocmod.zip').open('rb') as f:
 result=req(url('marketplace/installer.upload'),method='POST',files={'file':('furmedia_cache.ocmod.zip',f,'application/zip')}).json()
check(bool(result.get('success')),'Native OC4 ZIP upload: '+str(result.get('error','')))
listing=req(url('marketplace/installer')).text
rows=[row for row in re.findall(r'<tr\b[^>]*>.*?</tr>',listing,re.S) if 'SkyNova Cache Suite' in row]
check(bool(rows),'Uploaded extension appears in installer')
identity=re.search(r'extension_install_id=(\d+)',html.unescape(rows[0])).group(1)
next_url=BASE+url('marketplace/installer.install','&extension_install_id='+identity)
for i in range(60):
 check(next_url.startswith(BASE),'Installer confined to loopback')
 result=admin.get(html.unescape(next_url),timeout=40).json();check(not result.get('error'),'Native OC4 installer stage '+str(i)+': '+str(result.get('error','')))
 if not result.get('next'):break
 next_url=result['next']
check(bool(result.get('success')),'OC4 files/vendor installer completed')
result=req(url('extension/module.install','&extension=furmedia_cache&code=furmedia_cache')).json();check(bool(result.get('success')),'Native module install')
module='extension/furmedia_cache/module/furmedia_cache';panel=req(url(module));check('SkyNova' in panel.text,'OC4 admin renderer')
nonce=re.search(r'name="fm_nonce" value="([^"]+)"',panel.text).group(1)
save=req(url(module),method='POST',data={'fm_nonce':nonce,'operation':'save','settings[status]':1,'settings[mode]':'session','settings[debug]':1})
check('Configurația a fost salvată' in save.text,'OC4 native settings save')
guest=requests.Session();sequence=[]
for i in range(5):
 r=guest.get(BASE,timeout=40);check(r.status_code==200 and '</html>' in r.text,'OC4 storefront response');sequence.append(r.headers.get('X-FurMedia-Cache','none'))
check('HIT' in sequence,'OC4 native cache HIT: '+str(sequence))
other=requests.Session();r=other.get(BASE,timeout=40);check(r.headers.get('X-FurMedia-Cache')!='HIT','OC4 independent session isolation')
r=guest.get(BASE+'index.php?route=checkout/cart',timeout=40);check(r.status_code==200 and 'HIT'!=r.headers.get('X-FurMedia-Cache'),'OC4 cart bypass')
result=req(url('extension/module.uninstall','&extension=furmedia_cache&code=furmedia_cache')).json();check(bool(result.get('success')),'OC4 native uninstall')
r=requests.get(BASE,timeout=40);check(r.status_code==200 and 'X-FurMedia-Cache' not in r.headers,'OC4 uninstall preserves native shop')
(ROOT/'docs/validation/oc4-http.json').write_text(json.dumps({'checks':checks,'sequence':sequence,'environment':'isolated OC4.0.2.3 / PHP8.3.32 / MySQL8.4.3 / default theme','production_changed':False,'timestamp':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())},indent=2))
print('PASS',len(checks),'OC4 native HTTP checks',sequence)
