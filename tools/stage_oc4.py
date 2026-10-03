"""Prepare isolated OC4 sources, sharing only the test-only loopback MySQL instance."""
from pathlib import Path
import shutil,json
ROOT=Path(__file__).resolve().parents[1];SOURCE=Path('C:/Users/PC/Desktop/Opencart/oc4023');WORK=ROOT/'work/oc4-stage';SHOP=WORK/'shop';STORAGE=WORK/'storage'
secret=json.loads((ROOT/'work/local-stage/private.json').read_text())
def ignored(folder,names):
 rel=Path(folder).relative_to(SOURCE).as_posix()
 if rel in ['.','admin']:return [n for n in names if n=='config.php']
 if rel=='system':return [n for n in names if n=='storage']
 return []
shutil.copytree(SOURCE,SHOP,dirs_exist_ok=True,ignore=ignored)
shutil.copytree(SOURCE/'system/storage/vendor',STORAGE/'vendor',dirs_exist_ok=True)
for name in ['cache','logs','session','upload','download','modification','marketplace']: (STORAGE/name).mkdir(parents=True,exist_ok=True)
for side in ['catalog','admin']:
 values={'APPLICATION':side.title(),'HTTP_SERVER':'http://127.0.0.1:8797/'+('admin/' if side=='admin' else ''),'DIR_OPENCART':SHOP.as_posix()+'/','DIR_APPLICATION':(SHOP/side).as_posix()+'/','DIR_EXTENSION':(SHOP/'extension').as_posix()+'/','DIR_IMAGE':(SHOP/'image').as_posix()+'/','DIR_SYSTEM':(SHOP/'system').as_posix()+'/','DIR_STORAGE':STORAGE.as_posix()+'/','DIR_LANGUAGE':(SHOP/side/'language').as_posix()+'/','DIR_TEMPLATE':(SHOP/side/'view/template').as_posix()+'/','DIR_CONFIG':(SHOP/'system/config').as_posix()+'/','DB_DRIVER':'mysqli','DB_HOSTNAME':'127.0.0.1','DB_USERNAME':'fm_cache','DB_PASSWORD':secret['db_password'],'DB_DATABASE':'skynova_oc4_stage','DB_PORT':'33319','DB_PREFIX':'oc_','OPENCART_SERVER':'https://www.opencart.com/'}
 for name in ['cache','logs','session','upload','download']:values['DIR_'+name.upper()]=(STORAGE/name).as_posix()+'/'
 if side=='admin':values.update(HTTP_CATALOG='http://127.0.0.1:8797/',DIR_CATALOG=(SHOP/'catalog').as_posix()+'/')
 body='<?php\n'+''.join("define('%s','%s');\n"%(key,str(value).replace('\\','\\\\').replace("'","\\'")) for key,value in values.items())
 (SHOP/('admin/config.php' if side=='admin' else 'config.php')).write_text(body,encoding='utf8')
with (SHOP/'system/config/catalog.php').open('a',encoding='utf8') as f:f.write("\n$_['session_path'] = '/'; // Windows loopback fixture cookie path\n")
print('Isolated OC4 source/config prepared; no original config copied')
