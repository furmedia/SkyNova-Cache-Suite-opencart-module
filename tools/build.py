"""Reproducible installer generation. No proprietary reference code is packaged."""
from pathlib import Path
import hashlib, json, zipfile

ROOT = Path(__file__).resolve().parents[1]
VERSION = '0.7.2'

def controller(side, v4):
    route = 'extension/furmedia_cache/module/furmedia_cache' if v4 else 'extension/module/furmedia_cache'
    header = '<?php\n'
    if v4:
        header += 'namespace Opencart\\' + side.title() + '\\Controller\\Extension\\FurmediaCache\\Module;\n'
    location = "DIR_EXTENSION . 'furmedia_cache/system/library/furmedia_cache/" if v4 else "DIR_SYSTEM . 'library/furmedia_cache/"
    header += 'require_once ' + location + "bootstrap.php';\n"
    trait = (ROOT / ('src/adapters/' + side + '.trait.php')).read_text(encoding='utf8').replace('<?php\n', '', 1)
    header += trait + '\n'
    name = 'FurmediaCache' if v4 else 'ControllerExtensionModuleFurmediaCache'
    base = '\\Opencart\\System\\Engine\\Controller' if v4 else '\\Controller'
    header += f'class {name} extends {base} {{\n    const FM_ROUTE = {route!r};\n    use FurMedia{side.title()}Actions;\n}}\n'
    return header

def build():
    release = ROOT/'dist'
    records = []
    for family in ['oc23','oc3','oc4']:
        v4 = family == 'oc4'
        files = {}
        prefix = '' if v4 else 'upload/'
        for src in (ROOT/'src/core').rglob('*'):
            if src.is_file():
                files[prefix + 'system/library/furmedia_cache/' + src.relative_to(ROOT/'src/core').as_posix()] = src.read_bytes()
        for side in ['admin','catalog']:
            route = 'module/furmedia_cache' if v4 else 'extension/module/furmedia_cache'
            files[prefix+side+'/controller/'+route+'.php'] = controller(side,v4).encode()
        for lang in ['en-gb','ro-ro']:
            route = 'module/furmedia_cache' if v4 else 'extension/module/furmedia_cache'
            files[prefix+'admin/language/'+lang+'/'+route+'.php'] = b"<?php\n$_['heading_title'] = 'SkyNova Cache Suite';\n$_['text_extension'] = 'Extensions';\n$_['text_success'] = 'Settings saved';\n"
        if v4:
            files['install.json'] = json.dumps(dict(name='SkyNova Cache Suite',version=VERSION,author='FurMedia',link='https://www.opencart.com/',code='furmedia_cache',license='Proprietary; bundled libraries MIT'),indent=2).encode()
        for name in ['INSTALL-RO.md','ADVANCED-RO.md','ADVANCED-06-RO.md','SUITE-07-RO.md','FEATURES.md','COMPATIBILITY.md','THIRD-PARTY.md','CHANGELOG.md']:
            p=ROOT/'docs'/name
            if p.exists(): files['docs/'+name]=p.read_bytes()
        files['LICENSE']=(ROOT/'LICENSE').read_bytes()
        # OC4 takes its extension code from ZIP basename: retain furmedia_cache.ocmod.zip exactly.
        path=release/family/'furmedia_cache.ocmod.zip';path.parent.mkdir(parents=True,exist_ok=True)
        with zipfile.ZipFile(path,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
            for name,data in sorted(files.items()):
                info=zipfile.ZipInfo(name,(2026,10,3,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o644<<16
                z.writestr(info,data)
        # Expanded package is a test/build artifact; never changes the reference stores.
        for name,data in files.items():
            p=ROOT/'build'/family/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
        records.append(dict(family=family,path=path.relative_to(ROOT).as_posix(),files=len(files),bytes=path.stat().st_size,sha256=hashlib.sha256(path.read_bytes()).hexdigest()))
    (release/'manifest.json').write_text(json.dumps(records,indent=2)+'\n')
    print(json.dumps(records,indent=2))

if __name__ == '__main__': build()
