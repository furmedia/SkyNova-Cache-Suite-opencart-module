"""Full local OpenCart 3 HTTP install + session/cart regression. Loopback only."""
from pathlib import Path
import json,re,html,requests,time
ROOT=Path(__file__).resolve().parents[1];WORK=ROOT/'work/local-stage';BASE='http://127.0.0.1:8796/'
secret=json.loads((WORK/'private.json').read_text());s=requests.Session();checks=[]
def check(value,label):
    if not value:raise AssertionError(label)
    checks.append(label)
def request(path='',**kw):
    r=s.request(kw.pop('method','GET'),BASE+path,timeout=30,**kw)
    check(r.status_code==200,'HTTP200 '+path.split('?')[0]);return r
r=request('admin/index.php?route=common/login',method='POST',data={'username':secret['admin_user'],'password':secret['admin_password']})
m=re.search(r'user_token=([A-Za-z0-9]+)',r.url) or re.search(r'user_token=([A-Za-z0-9]+)',r.text)
check(m is not None,'Native admin login');token=m.group(1)
def url(route,extra=''):return 'admin/index.php?route='+route+'&user_token='+token+extra
with (ROOT/'dist/oc3/furmedia_cache.ocmod.zip').open('rb') as f:
    data=request(url('marketplace/installer/upload'),method='POST',files={'file':('furmedia_cache.ocmod.zip',f,'application/zip')}).json()
for i in range(12):
    check(not data.get('error'),'Native installer step '+str(i)+': '+str(data.get('error','')))
    if not data.get('next'):break
    check(data['next'].startswith(BASE),'Installer stays loopback')
    data=s.get(data['next'],timeout=30).json()
check(bool(data.get('success')),'Native installer complete')
request(url('extension/extension/module/install','&extension=furmedia_cache'))
panel=request(url('extension/module/furmedia_cache'))
check('Cache Suite' in panel.text,'Native admin panel renders')
nonce=re.search(r'name="fm_nonce" value="([^"]+)"',panel.text).group(1)
# Invalid nonce must not modify configuration.
bad=request(url('extension/module/furmedia_cache'),method='POST',data={'fm_nonce':'invalid','operation':'save','settings[status]':'1','settings[mode]':'session'})
check('sesiunea formularului' in bad.text,'CSRF rejection')
save=request(url('extension/module/furmedia_cache'),method='POST',data={'fm_nonce':nonce,'operation':'save','settings[status]':'1','settings[mode]':'session','settings[html_minify]':'1','settings[journal]':'1','settings[debug]':'1'})
check('Configurația a fost salvată' in save.text,'Save native settings')
guest=requests.Session();other=requests.Session()
history=[]
for i in range(5):
    r=guest.get(BASE,timeout=30);history.append(r.headers.get('X-FurMedia-Cache','none'))
    check(r.status_code==200 and '<html' in r.text,'Guest homepage renders')
check('HIT' in history,'Repeated same-session homepage gets HIT: '+','.join(history))
r=other.get(BASE,timeout=30);check(r.headers.get('X-FurMedia-Cache')!='HIT','Fresh visitor cannot hit another session page')
product=guest.get(BASE+'index.php?route=product/product&product_id=40',timeout=30);check('iPhone' in product.text,'Sample product loads')
add=guest.post(BASE+'index.php?route=checkout/cart/add',data={'product_id':40,'quantity':1},timeout=30)
check(bool(add.json().get('success')),'Native add-to-cart succeeds')
cart=guest.get(BASE+'index.php?route=checkout/cart',timeout=30);check('iPhone' in cart.text,'Cart retains selected product')
home=guest.get(BASE,timeout=30);check(home.headers.get('X-FurMedia-Cache','').startswith('BYPASS'),'Nonempty cart homepage bypasses')
othercart=other.get(BASE+'index.php?route=checkout/cart',timeout=30);check('Your shopping cart is empty' in othercart.text,'Other guest cart remains empty')
checkout=guest.get(BASE+'index.php?route=checkout/checkout',timeout=30);check(checkout.status_code==200 and 'Checkout' in checkout.text,'Checkout entry renders without order/payment')
# Purge via native admin, then recheck empty visitor.
purged=request(url('extension/module/furmedia_cache'),method='POST',data={'fm_nonce':nonce,'operation':'purge'});check('Intrări eliminate:' in purged.text,'Authenticated purge')
exported=request(url('extension/module/furmedia_cache'),method='POST',data={'fm_nonce':nonce,'operation':'export'}).json()
check(exported['mode']=='session' and exported['status']==1,'Settings export roundtrip')
runner=request(url('extension/module/furmedia_cache'),method='POST',data={'fm_nonce':nonce,'operation':'runner'}).json()
check(runner['cache_directory'].endswith('furmedia_cache') and 'password' not in runner,'Runner export contains paths but no secrets')
badimport=request(url('extension/module/furmedia_cache'),method='POST',data={'fm_nonce':nonce,'operation':'import','import_json':'{"critical_css":"</style><script>alert(1)</script>"}'})
check('Critical CSS must contain CSS only' in badimport.text,'Unsafe imported CSS rejected')
afterbad=request(url('extension/module/furmedia_cache'),method='POST',data={'fm_nonce':nonce,'operation':'export'}).json()
check(afterbad==exported,'Invalid import leaves saved settings unchanged')
request(url('extension/extension/module/uninstall','&extension=furmedia_cache'))
disabled=requests.get(BASE,timeout=30)
check(disabled.status_code==200 and '<html' in disabled.text and 'X-FurMedia-Cache' not in disabled.headers,'Native module uninstall leaves shop working without cache hooks')
request(url('extension/extension/module/install','&extension=furmedia_cache'))
panel=request(url('extension/module/furmedia_cache'))
nonce=re.search(r'name="fm_nonce" value="([^"]+)"',panel.text).group(1)
defaults=request(url('extension/module/furmedia_cache'),method='POST',data={'fm_nonce':nonce,'operation':'export'}).json()
check(defaults['status']==0 and defaults['mode']=='observe','Reinstall defaults to disabled observation mode')
report={'version':'0.4.0','environment':'isolated local OpenCart 3.0.5.1 / default theme / real MySQL 8.4.3','checks':checks,'homepage_cache_sequence':history,'production_changed':False,'orders_created':False,'timestamp':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())}
(ROOT/'docs/validation').mkdir(parents=True,exist_ok=True);(ROOT/'docs/validation/store-http.json').write_text(json.dumps(report,indent=2,ensure_ascii=False))
# Keep auth only in ignored local runtime for UI inspection, never in evidence or console.
(WORK/'browser-session.json').write_text(json.dumps({'cookies':s.cookies.get_dict(),'token':token,'nonce':nonce}))
print('PASS',len(checks),'local HTTP checks; cache sequence',history)
