"""Two fresh native visitors must share only the public shell. Loopback fixture only."""
from pathlib import Path
import requests,json,re,time
ROOT=Path(__file__).resolve().parents[1];WORK=ROOT/'work/local-stage';BASE='http://127.0.0.1:8796/'
auth=json.loads((WORK/'browser-session.json').read_text());admin=requests.Session();admin.cookies.update(auth['cookies'])
url=BASE+'admin/index.php?route=extension/module/furmedia_cache&user_token='+auth['token']
def post(operation,extra={}):
 r=admin.post(url,data={'fm_nonce':auth['nonce'],'operation':operation,**extra},timeout=30);r.raise_for_status();return r
original=post('export').json();settings={**original,'status':1,'mode':'shared','shared_routes':'common/home\nproduct/product','debug':1,'html_minify':1}
checks=[]
def check(value,name):
 if not value:raise AssertionError(name)
 checks.append(name)
try:
 post('save',{'settings['+k+']':v for k,v in settings.items()});post('purge')
 a=requests.Session();b=requests.Session()
 first=a.get(BASE,timeout=30);second=b.get(BASE,timeout=30)
 check(first.status_code==second.status_code==200,'two independent native visitors render')
 check(first.headers.get('X-FurMedia-Cache')=='MISS','first shared page miss')
 check(second.headers.get('X-FurMedia-Cache')=='HIT','different session hits shared page: '+str(second.headers.get('X-FurMedia-Cache')))
 check(a.cookies.get_dict()!=b.cookies.get_dict(),'visitors retain separate native session cookies')
 check('<html' in second.text and '</html>' in second.text and 'skynova-dynamic-' not in second.text,'native header/footer reassembled')
 check('private' in second.headers.get('Cache-Control',''),'edge never receives public personalized response')
 # A personalized cookie cannot consume the anonymous cache namespace.
 custom=requests.Session();custom.cookies.set('custom_personalization','one')
 r=custom.get(BASE,timeout=30);check(r.headers.get('X-FurMedia-Cache')!='HIT','unknown cookie switches to private namespace')
 cart=a.post(BASE+'index.php?route=checkout/cart/add',data={'product_id':40,'quantity':1},timeout=30)
 check(bool(cart.json().get('success')),'native product added to one cart')
 r=a.get(BASE,timeout=30);check(r.headers.get('X-FurMedia-Cache','').startswith('BYPASS'),'occupied cart bypasses shared cache')
 other=b.get(BASE+'index.php?route=checkout/cart',timeout=30)
 check('Your shopping cart is empty' in other.text,'second visitor does not inherit cart')
 result=post('purge_tag',{'purge_tag':'route:common/home'});check('Intrări eliminate:' in result.text,'selective route invalidation from admin')
 fresh=requests.get(BASE,timeout=30);check(fresh.headers.get('X-FurMedia-Cache')=='MISS','selective purge reaches shared namespace')
finally:
 post('save',{'settings['+k+']':v for k,v in original.items()})
(ROOT/'docs/validation/shared-http.json').write_text(json.dumps({'checks':checks,'environment':'isolated OC3.0.5.1 default theme','timestamp':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'production_changed':False},indent=2))
print('PASS',len(checks),'shared-page HTTP checks')
