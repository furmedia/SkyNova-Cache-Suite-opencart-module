"""Prepare an isolated, disposable OC3 test store. Never read/copy the source config.php."""
import json, secrets, shutil, subprocess, os
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
WORK=ROOT/'work/local-stage'
SOURCE=Path(r'C:\Users\PC\Desktop\Opencart\opencart-3.0.5.1\upload')
MYSQL=Path(r'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe')

def prepare():
    WORK.mkdir(parents=True,exist_ok=True)
    shop=WORK/'shop';shop.mkdir(exist_ok=True)
    def ignore(directory,names):
        rel=Path(directory).relative_to(SOURCE).as_posix()
        if rel in ['admin','catalog']:return ['config.php']
        if rel=='system/storage':return ['cache','logs','session','modification','download','upload']
        if rel=='image':return ['cache']
        return []
    for folder in ['admin','catalog','image','system','install']:
        shutil.copytree(SOURCE/folder,shop/folder,dirs_exist_ok=True,ignore=ignore)
    shutil.copy2(SOURCE/'index.php',shop/'index.php')
    storage=WORK/'storage'
    for folder in ['cache','logs','session','modification','download','upload']: (storage/folder).mkdir(parents=True,exist_ok=True)
    # Composer dependencies are not generated/private store data.
    if (SOURCE/'system/storage/vendor').is_dir(): shutil.copytree(SOURCE/'system/storage/vendor',storage/'vendor',dirs_exist_ok=True)
    secret=dict(admin_user='fm_local_admin',admin_password=secrets.token_urlsafe(24),db_password=secrets.token_urlsafe(24))
    secret_file=WORK/'private.json'
    if not secret_file.exists(): secret_file.write_text(json.dumps(secret))
    secret=json.loads(secret_file.read_text())
    for side in ['catalog','admin']:
        paths={'HTTP_SERVER':'http://127.0.0.1:8796/'+('admin/' if side=='admin' else ''),'HTTPS_SERVER':'http://127.0.0.1:8796/'+('admin/' if side=='admin' else ''),'DIR_APPLICATION':(shop/side).as_posix()+'/', 'DIR_SYSTEM':(shop/'system').as_posix()+'/', 'DIR_IMAGE':(shop/'image').as_posix()+'/', 'DIR_STORAGE':storage.as_posix()+'/', 'DIR_LANGUAGE':(shop/side/'language').as_posix()+'/', 'DIR_TEMPLATE':(shop/side/('view/template' if side=='admin' else 'view/theme')).as_posix()+'/', 'DIR_CONFIG':(shop/'system/config').as_posix()+'/', 'OPENCART_SERVER':'https://www.opencart.com/', 'DB_DRIVER':'mysqli','DB_HOSTNAME':'127.0.0.1','DB_USERNAME':'fm_cache','DB_PASSWORD':secret['db_password'],'DB_DATABASE':'furmedia_stage','DB_PORT':'33319','DB_PREFIX':'oc_'}
        for p in ['cache','logs','session','modification','download','upload']:paths['DIR_'+p.upper()]=(storage/p).as_posix()+'/'
        if side=='admin':paths.update(HTTP_CATALOG='http://127.0.0.1:8796/',HTTPS_CATALOG='http://127.0.0.1:8796/',DIR_CATALOG=(shop/'catalog').as_posix()+'/')
        text='<?php\n'+''.join("define('%s', '%s');\n"%(k,v.replace('\\','\\\\').replace("'","\\'")) for k,v in paths.items())
        (shop/('admin/config.php' if side=='admin' else 'config.php')).write_text(text)
    # Own runner only; bind TCP exclusively to loopback; no shared/global MySQL config.
    datadir=WORK/'mysql-data'
    if not datadir.exists():
        r=subprocess.run([str(MYSQL),'--no-defaults','--initialize-insecure','--datadir='+datadir.as_posix(),'--console'],capture_output=True,text=True)
        (WORK/'mysql-init.log').write_text(r.stdout+r.stderr)
        if r.returncode:raise RuntimeError('MySQL init failed; inspect private local log')
    print('Prepared isolated OC3 source and private local runtime at',WORK)

if __name__=='__main__': prepare()
