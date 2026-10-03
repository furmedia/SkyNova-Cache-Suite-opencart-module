"""New data features through native OC3 loader, MySQL and authenticated admin. Local fixture only."""
from pathlib import Path
import requests,re,json,time,sys
ROOT=Path(__file__).resolve().parents[1];v4='--oc4' in sys.argv;WORK=ROOT/('work/oc4-stage' if v4 else 'work/local-stage');SHOP=WORK/'shop';BASE='http://127.0.0.1:'+('8797' if v4 else '8796')+'/'
route='extension/furmedia_cache/module/furmedia_cache' if v4 else 'extension/module/furmedia_cache';component='extension/furmedia_cache/module/skynova_fixture' if v4 else 'extension/module/skynova_fixture';checks=[]
admin=requests.Session()
if v4:
 private=json.loads((ROOT/'work/local-stage/private.json').read_text());page=admin.get(BASE+'admin/index.php?route=common/login');login=re.search(r'login_token=([A-Za-z0-9]+)',page.text).group(1)
 result=admin.post(BASE+'admin/index.php?route=common/login.login&login_token='+login,data={'username':private['admin_user'],'password':private['admin_password'],'redirect':''}).json();token=re.search(r'user_token=([A-Za-z0-9]+)',result['redirect']).group(1)
 install=admin.get(BASE+'admin/index.php?route=extension/module.install&extension=furmedia_cache&code=furmedia_cache&user_token='+token).json()
 if not install.get('success'):raise RuntimeError('OC4 fixture module install failed')
 url=BASE+'admin/index.php?route='+route+'&user_token='+token;panel=admin.get(url);auth={'nonce':re.search(r'name="fm_nonce" value="([^"]+)"',panel.text).group(1)}
else:
 auth=json.loads((WORK/'browser-session.json').read_text());admin.cookies.update(auth['cookies']);url=BASE+'admin/index.php?route='+route+'&user_token='+auth['token']
def post(op,extra=None):
 r=admin.post(url,data={'fm_nonce':auth['nonce'],'operation':op,**(extra or {})},timeout=30);r.raise_for_status();return r
def check(ok,name):
 if not ok:raise AssertionError(name)
 checks.append(name)
original=post('export').json();home=SHOP/'catalog/controller/common/home.php';backup=home.read_bytes();module=SHOP/('extension/furmedia_cache/catalog/controller/module/skynova_fixture.php' if v4 else 'catalog/controller/extension/module/skynova_fixture.php');counter=WORK/'data-fixture-count.txt'
if module.exists() or counter.exists():raise RuntimeError('Unexpected pre-existing fixture')
sql="SELECT * FROM oc_category WHERE category_id = '20'"
def save(**kw):
 settings={**original,'status':1,'mode':'session','hide_category_count':1,'sql_cache':1,'sql_allow':sql,'debug':1,**kw}
 r=post('save',{'settings['+k+']':v for k,v in settings.items()});check('Configurația a fost salvată' in r.text,'native data settings saved')
def stats():return json.loads((WORK/'storage/cache/furmedia_cache/stats.json').read_text())
try:
 module.write_text(("<?php\nnamespace Opencart\\Catalog\\Controller\\Extension\\FurmediaCache\\Module;\nclass SkynovaFixture extends \\Opencart\\System\\Engine\\Controller {public function index(){ $file=" if v4 else "<?php\nclass ControllerExtensionModuleSkynovaFixture extends Controller {public function index(){ $file=")+repr(counter.as_posix())+";file_put_contents($file,(string)((int)@file_get_contents($file)+1));$sql=\""+sql+"\";$a=$this->db->query($sql);$b=$this->db->query($sql);return '<div id=\"skynova-data-fixture\" data-count=\"'.(int)$this->config->get('config_product_count').'\">category '.(int)$b->row['category_id'].'</div>';}}",encoding='utf8')
 text=backup.decode('utf8').replace("$data['content_bottom']", "$data['content_top'] .= $this->load->controller('"+component+"').'<script nonce=\"fixture\"></script>';\n\t\t$data['content_bottom']",1);home.write_text(text,encoding='utf8')
 save(component_cache=0);guest=requests.Session();before=stats().get('sql_hit',0)
 for _ in range(4):
  response=guest.get(BASE,timeout=30);check(response.status_code==200 and 'category 20' in response.text,'native SQL facade renders category');check('data-count="0"' in response.text,'native count config disabled')
 check(stats().get('sql_hit',0)>before,'native MySQL SELECT result reused')
 counter.write_text('0');save(component_cache=1,component_allow=component);guest=requests.Session();before=stats().get('component_hit',0)
 for _ in range(4):check('category 20' in guest.get(BASE,timeout=30).text,'native component output retained')
 check(int(counter.read_text())<=2,'component cache skips native controller across HTTP requests');check(stats().get('component_hit',0)>before,'component HIT counter recorded')
 other=requests.Session();prior=int(counter.read_text());other.get(BASE,timeout=30);check(int(counter.read_text())==prior+1,'native component isolated for fresh visitor')
 scan=post('indexes_scan');check('Indexuri bază de date' in scan.text and ('Acoperit' in scan.text or 'Propus' in scan.text),'native index diagnostic renders existing coverage')
 candidates=re.findall(r'name="index_ids\[\]" value="([^"]+)"',scan.text)
 if candidates:
  candidate=candidates[0];bad=post('indexes_apply',{'fm_nonce':'bad','index_ids[]':candidate});check('sesiunea formularului' in bad.text,'index DDL blocked by invalid nonce')
  added=post('indexes_apply',{'index_ids[]':candidate});check('Indexuri adăugate: 1' in added.text,'selected real MySQL index added')
  repeat=post('indexes_apply',{'index_ids[]':candidate});check('Indexuri adăugate: 0' in repeat.text,'native index apply idempotent')
 check('Invalid index proposal' in post('indexes_apply',{'index_ids[]':'bad`sql'}).text,'index identifier injection rejected')
 report={'version':'0.4.0','environment':('isolated OC4.0.2.3' if v4 else 'isolated OC3.0.5.1')+' default theme / native Loader / MySQL8.4.3','checks':checks,'production_changed':False,'timestamp':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())};(ROOT/('docs/validation/data-oc4-http.json' if v4 else 'docs/validation/data-http.json')).write_text(json.dumps(report,indent=2),encoding='utf8')
 print('PASS',len(checks),'data feature native HTTP checks')
finally:
 home.write_bytes(backup)
 if module.exists():module.unlink()
 if counter.exists():counter.unlink()
 post('save',{'settings['+k+']':v for k,v in original.items()})
