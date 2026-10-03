"""0.4 additions through native HTTP. Only isolated loopback fixtures, no remote services."""
from pathlib import Path
import requests,re,json,time,sys,subprocess
ROOT=Path(__file__).resolve().parents[1];v4='--oc4' in sys.argv
WORK=ROOT/('work/oc4-stage' if v4 else 'work/local-stage');SHOP=WORK/'shop';BASE='http://127.0.0.1:'+('8797' if v4 else '8796')+'/'
route='extension/furmedia_cache/module/furmedia_cache' if v4 else 'extension/module/furmedia_cache';separator='.' if v4 else '/'
admin=requests.Session();private=json.loads((ROOT/'work/local-stage/private.json').read_text());checks=[]
if v4:
    page=admin.get(BASE+'admin/index.php?route=common/login');login=re.search(r'login_token=([A-Za-z0-9]+)',page.text).group(1)
    result=admin.post(BASE+'admin/index.php?route=common/login.login&login_token='+login,data={'username':private['admin_user'],'password':private['admin_password'],'redirect':''}).json()
    token=re.search(r'user_token=([A-Za-z0-9]+)',result['redirect']).group(1)
    result=admin.get(BASE+'admin/index.php?route=extension/module.install&extension=furmedia_cache&code=furmedia_cache&user_token='+token).json()
    assert result.get('success')
else:
    result=admin.post(BASE+'admin/index.php?route=common/login',data={'username':private['admin_user'],'password':private['admin_password']})
    token=(re.search(r'user_token=([A-Za-z0-9]+)',result.url) or re.search(r'user_token=([A-Za-z0-9]+)',result.text)).group(1)
url=BASE+'admin/index.php?route='+route+'&user_token='+token
panel=admin.get(url);nonce=re.search(r'name="fm_nonce" value="([^"]+)"',panel.text).group(1)
def post(op,extra=None):
    response=admin.post(url,data={'fm_nonce':nonce,'operation':op,**(extra or {})},timeout=40);response.raise_for_status();return response
def check(ok,name):
    if not ok:raise AssertionError(name)
    checks.append(name)
original=post('export').json();resources='extension/furmedia_cache/module/skynova_resource_fixture' if v4 else 'extension/module/skynova_resource_fixture'
php='C:/laragon/bin/php/php-8.3.32-Win32-vs16-x64/php.exe';customer_command=[php,str(ROOT/'tests/customer-fixture.php')]+(['--oc4'] if v4 else [])
missing='information/skynova_completion_fixture';counter=WORK/'completion-resource-count.txt'
resource=SHOP/('extension/furmedia_cache/catalog/controller/module/skynova_resource_fixture.php' if v4 else 'catalog/controller/extension/module/skynova_resource_fixture.php')
notfound=SHOP/'catalog/controller/information/skynova_completion_fixture.php';asset=SHOP/'catalog/view/javascript/skynova-batch-fixture.js'
for path in (resource,notfound,counter,asset):
    if path.exists():raise RuntimeError('Unexpected pre-existing fixture')
def save(**changes):
    settings={**original,'status':1,'mode':'session','debug':1,**changes}
    response=post('save',{'settings['+key+']':value for key,value in settings.items()});check('Configurația a fost salvată' in response.text,'native settings saved')
try:
    check(all('name="settings['+field+']"' in panel.text for field in ('page_rules','dynamic_cache','extract_js','script_position','warm_variants','litespeed_esi','dynamic_widgets')),'all new controls render natively')
    check('name="services[SKYNOVA_CF_TOKEN]" value=""' in panel.text,'integration secret inputs never populated')
    resource.write_text(('<?php\nnamespace Opencart\\Catalog\\Controller\\Extension\\FurmediaCache\\Module;\nclass SkynovaResourceFixture extends \\Opencart\\System\\Engine\\Controller {' if v4 else '<?php\nclass ControllerExtensionModuleSkynovaResourceFixture extends Controller {')+'public function index(){ $file='+repr(counter.as_posix())+';file_put_contents($file,(string)((int)@file_get_contents($file)+1));$this->response->addHeader("Content-Type: text/css; charset=utf-8");$this->response->addHeader(\'ETag: "dynamic-fixture"\');$this->response->setOutput("body { color: blue; }");}}',encoding='utf8')
    resource_uri='/index.php?route='+resources
    counter.write_text('0')
    save(dynamic_cache=1,dynamic_urls=resource_uri)
    guest=requests.Session();responses=[guest.get(BASE+resource_uri[1:],timeout=30) for _ in range(5)]
    check(all(r.status_code==200 and r.text=='body { color: blue; }' for r in responses),'dynamic resource native body preserved')
    check(responses[-1].headers.get('X-FurMedia-Cache')=='HIT','dynamic resource gets native cache HIT')
    check(responses[-1].headers.get('ETag')=='"dynamic-fixture"' and responses[-1].headers.get('Content-Type','').startswith('text/css'),'dynamic resource headers replayed')
    before=int(counter.read_text());other=requests.Session();other.get(BASE+resource_uri[1:]);check(int(counter.read_text())==before+1,'dynamic resource isolated between sessions')
    notfound.write_text(('<?php\nnamespace Opencart\\Catalog\\Controller\\Information;\nclass SkynovaCompletionFixture extends \\Opencart\\System\\Engine\\Controller {' if v4 else '<?php\nclass ControllerInformationSkynovaCompletionFixture extends Controller {')+'public function index(){$this->response->addHeader("HTTP/1.1 404 Not Found");$this->response->setOutput("<html><body>missing fixture</body></html>");}}',encoding='utf8')
    save(cache_404=1,routes=original['routes']+'\n'+missing)
    guest=requests.Session();responses=[guest.get(BASE+'index.php?route='+missing) for _ in range(5)]
    check(all(r.status_code==404 for r in responses),'404 status preserved on every request')
    check(responses[-1].headers.get('X-FurMedia-Cache')=='HIT' and 'missing fixture' in responses[-1].text,'404 native HIT keeps body and status')
    subprocess.run(customer_command,check=True,capture_output=True)
    customers=json.loads((WORK/'completion-customer-fixture.json').read_text());sessions=[]
    for customer in customers:
        session=requests.Session()
        if v4:
            page=session.get(BASE+'index.php?route=account/login');login=re.search(r'login_token=([A-Za-z0-9]+)',page.text).group(1)
            response=session.post(BASE+'index.php?route=account/login.login&login_token='+login,data={'email':customer['email'],'password':customer['password'],'redirect':''}).json()
            check(bool(response.get('redirect')) and not response.get('error'),'native customer login succeeds')
        else:
            response=session.post(BASE+'index.php?route=account/login',data={'email':customer['email'],'password':customer['password'],'redirect':''})
            check(response.status_code==200 and 'account/account' in response.url,'native customer login succeeds')
        sessions.append(session)
    notfound.write_text(('<?php\nnamespace Opencart\\Catalog\\Controller\\Information;\nclass SkynovaCompletionFixture extends \\Opencart\\System\\Engine\\Controller {' if v4 else '<?php\nclass ControllerInformationSkynovaCompletionFixture extends Controller {')+'public function index(){$link=isset($this->session->data["customer_token"])?\'<a href="index.php?route=account/account&amp;customer_token=\'.$this->session->data["customer_token"].\'">Account</a>\':"";$this->response->setOutput("<html><body>".$this->customer->getFirstName().$link."</body></html>");}}',encoding='utf8')
    save(routes=original['routes']+'\n'+missing,page_rules=json.dumps({missing:{'logged':True},'common/home':{'logged':True}}))
    for index,session in enumerate(sessions):
        responses=[session.get(BASE+'index.php?route='+missing) for _ in range(4)]
        expected='SkyNovaFixture'+('A' if index==0 else 'B');foreign='SkyNovaFixture'+('B' if index==0 else 'A')
        check(responses[-1].headers.get('X-FurMedia-Cache')=='HIT' and expected in responses[-1].text and foreign not in responses[-1].text,'native private logged page HIT stays isolated by customer')
        home_responses=[session.get(BASE) for _ in range(4)]
        check(home_responses[-1].headers.get('X-FurMedia-Cache')=='HIT','approved native logged storefront homepage reaches private HIT')
    subprocess.run(customer_command+['--rename'],check=True,capture_output=True)
    response=sessions[0].get(BASE+'index.php?route='+missing);check('SkyNovaFixtureAChanged' in response.text and response.headers.get('X-FurMedia-Cache')!='HIT','native profile edit changes private cache context without relying on admin purge')
    customer_token=re.search(r'customer_token=([A-Za-z0-9]+)',sessions[0].get(BASE+'index.php?route='+missing).text)
    sessions[0].get(BASE+'index.php?route=account/logout'+('&customer_token='+customer_token.group(1) if customer_token else ''))
    response=sessions[0].get(BASE+'index.php?route='+missing);check('SkyNovaFixtureA' not in response.text,'logout cannot replay previous customer cached page')
    save(dynamic_widgets=1,page_rules='{"common/home":{"cart":true,"ttl":30}}')
    guest=requests.Session();guest.get(BASE)
    response=guest.post(BASE+'index.php?route=checkout/cart'+separator+'add',data={'product_id':40,'quantity':1})
    check(bool(response.json().get('success')),'native cart populated for private page rule')
    responses=[guest.get(BASE,timeout=30) for _ in range(4)]
    check(responses[-1].headers.get('X-FurMedia-Cache')=='HIT','approved private catalog page caches with occupied cart')
    response=guest.post(BASE+'index.php?route=checkout/cart'+separator+'add',data={'product_id':40,'quantity':1});check(bool(response.json().get('success')),'native cart quantity changes after cached page')
    response=guest.get(BASE);check(response.headers.get('X-FurMedia-Cache')!='HIT' and '2 item(s)' in response.text,'cart database quantity change produces fresh private page context')
    widget=guest.get(BASE+'index.php?route='+route+separator+'widgets',headers={'X-Requested-With':'XMLHttpRequest'},timeout=30)
    data=widget.json();check(widget.status_code==200 and 'iPhone' in data['cart'] and 'wishlist-total' in data['header'],'dynamic widgets render current native cart and wishlist')
    check('no-store' in widget.headers.get('Cache-Control','') and widget.headers.get('X-LiteSpeed-Cache-Control')=='no-cache','widgets excluded from browser and LiteSpeed cache')
    other=requests.Session();other_data=other.get(BASE+'index.php?route='+route+separator+'widgets').json();check('iPhone' not in other_data['cart'],'widget endpoint cannot leak cart to another visitor')
    denied=guest.get(BASE+'index.php?route='+route+separator+'esi&id='+('0'*64)+'&signature='+('0'*64));check(denied.status_code==403,'ESI denied without configured server capability')
    denied=guest.get(BASE+'index.php?route='+route+separator+'cron');check(denied.status_code==405,'remote cron rejects GET')
    denied=guest.post(BASE+'index.php?route='+route+separator+'cron');check(denied.status_code==403,'remote cron rejects HTTP and missing authorization')
    asset.write_text('var batchAdminFixture = 1; // original\n',encoding='utf8');save(js_minify=1)
    response=post('batch_minify');check('Procesare în lot: scan' in response.text,'admin starts persistent pre-minification queue')
    response=post('batch_run');check('Fișiere procesate' in response.text and 'fm-batch' in response.text,'admin runs bounded batch step and displays progress')
    response=post('batch_cancel');check('Procesare în lot: cancelled' in response.text,'admin cancels queue')
    check(asset.read_text(encoding='utf8').startswith('var batchAdminFixture = 1; // original'),'admin batch leaves original asset intact')
    save(debug_details=1,debug_session_hash='')
    diagnostic_guest=requests.Session();diagnostic_guest.get(BASE);diagnostic_response=diagnostic_guest.get(BASE)
    digest=diagnostic_response.headers.get('X-SkyNova-Session','')
    check(bool(re.fullmatch('[a-f0-9]{64}',digest)),'diagnostic exposes digest rather than raw session identifier')
    save(debug_details=1,debug_session_hash=digest)
    check(diagnostic_guest.get(BASE).headers.get('X-SkyNova-Session')==digest,'selected session diagnostics remain enabled')
    check('X-SkyNova-Session' not in requests.Session().get(BASE).headers,'other sessions excluded from diagnostic selector')
    check('fm-diagnostic-log' in admin.get(url).text,'bounded diagnostic history renders in native admin')
    # Webkul comparison additions through native HTTP; no remote server changes.
    save(cache_panel=1,debug_session_hash='',module_rules='{}')
    panel_guest=requests.Session();panel_pages=[panel_guest.get(BASE) for _ in range(4)]
    panel_body=panel_pages[-1].text
    check(panel_body.count('id="skynova-cache-panel"')==1 and 'data-skynova-count' in panel_body,'frontend diagnostic panel inserted once after private cache lookup')
    panel_token=re.search(r'encodeURIComponent\("([^"<>]+)"\)',panel_body).group(1)
    panel_endpoint=BASE+'index.php?route='+route+separator+'panel'
    check(panel_guest.get(panel_endpoint).status_code==405,'frontend purge rejects GET')
    check(requests.Session().post(panel_endpoint,data={'panel_token':panel_token}).status_code==403,'frontend purge rejects token from another native session')
    cleared=panel_guest.post(panel_endpoint,data={'panel_token':panel_token});check(cleared.status_code==200 and cleared.json()['removed']>0,'frontend signed POST purges own private session entries')
    check(panel_guest.post(panel_endpoint,data={'panel_token':panel_token}).status_code==403,'frontend token cannot be reused after generation purge')
    save(cache_panel=0,gzip=1,reserve_compressed=1,preload_urls='/catalog/view/javascript/common.js|script',prefetch_urls='/catalog/view/javascript/common.js')
    gzip_guest=requests.Session();gzip_pages=[gzip_guest.get(BASE) for _ in range(4)]
    check(gzip_pages[-1].headers.get('Content-Encoding')=='gzip' and '<html' in gzip_pages[-1].text,'native page serves gzip with preserved decompressed HTML')
    identity=gzip_guest.get(BASE,headers={'Accept-Encoding':'gzip;q=0, identity'})
    check('Content-Encoding' not in identity.headers and '<html' in identity.text,'native page respects explicit gzip q=0')
    check('rel="preload"' in identity.text and 'rel="prefetch"' in identity.text,'configured preload and prefetch included in native head')
    inventory=admin.get(url).text
    check('fm-inventory' in inventory and 'ID fișier / taguri' in inventory,'private inventory renders metadata without cached bodies')
    profile=post('browser_profile');check('ExpiresByType application/pdf' in profile.text and 'location ~*' in profile.text,'native browser-cache export contains Apache and nginx profiles')
    resource.write_text(('<?php\nnamespace Opencart\\Catalog\\Controller\\Extension\\FurmediaCache\\Module;\nclass SkynovaResourceFixture extends \\Opencart\\System\\Engine\\Controller {' if v4 else '<?php\nclass ControllerExtensionModuleSkynovaResourceFixture extends Controller {')+'public function index(){ $this->response->addHeader("Content-Type: application/xml; charset=utf-8");$this->response->setOutput("<catalog><item>Public XML fixture</item></catalog>");}}',encoding='utf8')
    save(cache_panel=0,gzip=0,compress_xml=1,dynamic_cache=1,dynamic_urls=resource_uri,reserve_compressed=1)
    xml_guest=requests.Session();xml_pages=[xml_guest.get(BASE+resource_uri[1:]) for _ in range(4)]
    check(xml_pages[-1].headers.get('Content-Encoding')=='gzip' and xml_pages[-1].text=='<catalog><item>Public XML fixture</item></catalog>','approved native XML response compressed with intact body')
    check('compression' in admin.get(url).text,'approved safe XML has reusable compressed variant in private inventory')
    for media_type,flag,body in [('application/xhtml+xml','compress_xhtml','<html xmlns="http://www.w3.org/1999/xhtml"><body>Public XHTML</body></html>'),('application/rss+xml','compress_rss','<rss><channel><title>Public RSS</title></channel></rss>')]:
        resource.write_text(('<?php\nnamespace Opencart\\Catalog\\Controller\\Extension\\FurmediaCache\\Module;\nclass SkynovaResourceFixture extends \\Opencart\\System\\Engine\\Controller {' if v4 else '<?php\nclass ControllerExtensionModuleSkynovaResourceFixture extends Controller {')+'public function index(){ $this->response->addHeader('+repr('Content-Type: '+media_type)+');$this->response->setOutput('+repr(body)+');}}',encoding='utf8')
        save(cache_panel=0,gzip=0,compress_xml=0,dynamic_cache=1,dynamic_urls=resource_uri,reserve_compressed=1,**{flag:1})
        format_guest=requests.Session();format_pages=[format_guest.get(BASE+resource_uri[1:]) for _ in range(3)]
        check(format_pages[-1].headers.get('Content-Encoding')=='gzip' and format_pages[-1].text==body,'native '+media_type+' preserves body under gzip')
        check('Content-Encoding' not in format_guest.get(BASE+resource_uri[1:],headers={'Accept-Encoding':'identity'}).headers,'native '+media_type+' negotiates identity variant')
    htaccess=SHOP/'.htaccess';htaccess_original=htaccess.read_bytes() if htaccess.exists() else None
    try:
        response=post('browser_apply');check('Profil Apache salvat' in response.text and 'SKYNOVA-BROWSER-START' in htaccess.read_text(encoding='utf8'),'native admin applies static Apache profile with private backup')
    finally:
        if htaccess_original is None:htaccess.unlink(missing_ok=True)
        else:htaccess.write_bytes(htaccess_original)
    presets=post('module_presets');check('extension/' in presets.text and 'carousel' in presets.text,'native module presets cover standard carousel and route family')
    # Native loader component cache: isolate a read-only child and its parent page.
    resource.write_text(('<?php\nnamespace Opencart\\Catalog\\Controller\\Extension\\FurmediaCache\\Module;\nclass SkynovaResourceFixture extends \\Opencart\\System\\Engine\\Controller {' if v4 else '<?php\nclass ControllerExtensionModuleSkynovaResourceFixture extends Controller {')+'public function index($settings = array()){ $file='+repr(counter.as_posix())+';file_put_contents($file,(string)((int)@file_get_contents($file)+1));return "<div>Read only native module fixture</div>";}}',encoding='utf8')
    notfound.write_text(('<?php\nnamespace Opencart\\Catalog\\Controller\\Information;\nclass SkynovaCompletionFixture extends \\Opencart\\System\\Engine\\Controller {' if v4 else '<?php\nclass ControllerInformationSkynovaCompletionFixture extends Controller {')+'public function index(){ $child=$this->load->controller('+repr(resources)+',array("module_id"=>12));$this->response->addHeader("Content-Type: text/html; charset=utf-8");$this->response->setOutput("<html><head></head><body>".$child."</body></html>");}}',encoding='utf8')
    rule_json=json.dumps({resources:{'enabled':True,'ttl':120,'instances':{'module:12':{'ttl':20}}}})
    save(component_cache=1,module_rules=rule_json,routes=missing,page_rules='',cache_panel=0,gzip=0,dynamic_cache=0)
    component_guest=requests.Session();component_pages=[component_guest.get(BASE+'index.php?route='+missing) for _ in range(4)]
    check('Read only native module fixture' in component_pages[-1].text,'native module rule renders original read-only controller output')
    before=int(counter.read_text());post('purge_tag',{'purge_tag':'route:'+missing});component_guest.get(BASE+'index.php?route='+missing)
    check(int(counter.read_text())==before,'native component HIT survives independent parent page invalidation')
    metadata=admin.get(url).text;check('module:'+resources+':module:12' in metadata,'native inventory records per-instance module tag')
    post('purge_module',{'module_tag':'module:'+resources+':module:12'});post('purge_tag',{'purge_tag':'route:'+missing});component_guest.get(BASE+'index.php?route='+missing)
    check(int(counter.read_text())>before,'native per-instance purge forces original module regeneration')
    response=post('module_save',{'module_controls['+resources+'][enabled]':'0','module_controls['+resources+'][ttl]':'33'})
    check('Regulile modulelor au fost salvate' in response.text and post('export').json()['module_rules'].find('33')>=0,'native module table saves enabled state and TTL')
    # Test local encrypted credential storage; never send a remote purge.
    response=post('integrations_save',{'services[SKYNOVA_CF_TOKEN]':'local-only-completion-fixture'});check('seiful privat criptat' in response.text,'admin service configuration saved in private vault')
    check('local-only-completion-fixture' not in response.text and 'local-only-completion-fixture' not in post('export').text,'credential never echoed or exported')
    response=post('integrations_save',{'remove_services[]':'SKYNOVA_CF_TOKEN'});check('seiful privat criptat' in response.text,'local test credential removed through native admin')
    report={'version':'0.5.0','environment':('OC4.0.2.3' if v4 else 'OC3.0.5.1')+' / native local HTTP and MySQL','checks':checks,'production_changed':False,'timestamp':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime())}
    (ROOT/('docs/validation/completion-oc4-http.json' if v4 else 'docs/validation/completion-http.json')).write_text(json.dumps(report,indent=2),encoding='utf8')
    print('PASS',len(checks),'completion native HTTP checks')
finally:
    post('save',{'settings['+key+']':value for key,value in original.items()})
    if (WORK/'completion-customer-fixture.json').exists():subprocess.run(customer_command+['--remove'],check=True,capture_output=True)
    for path in (resource,notfound,counter,asset):
        if path.exists():path.unlink()
