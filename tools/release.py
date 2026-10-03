"""Package only explicitly allowlisted project files; never stage/private references."""
from pathlib import Path
import hashlib,json,zipfile
from build import ROOT,VERSION,build

build()
files={}
for directory in ['src','docs','examples']:
    for p in (ROOT/directory).rglob('*'):
        if p.is_file():files[p.relative_to(ROOT).as_posix()]=p.read_bytes()
for directory in ['tests','tools']:
    for p in (ROOT/directory).iterdir():
        if p.is_file() and p.suffix in ['.py','.php','.mjs']:
            files[p.relative_to(ROOT).as_posix()]=p.read_bytes()
for name in ['README.md','LICENSE']:
    files[name]=(ROOT/name).read_bytes()
for p in (ROOT/'dist').rglob('*'):
    if p.is_file() and (p.name=='furmedia_cache.ocmod.zip' or p.name=='manifest.json'):
        files[p.relative_to(ROOT).as_posix()]=p.read_bytes()

# Check known test-only secrets without emitting their values.
private=ROOT/'work/local-stage/private.json'
if private.exists():
    values=json.loads(private.read_text())
    secrets=[v.encode() for k,v in values.items() if isinstance(v,str) and 'password' in k and len(v)>8]
    for name,data in files.items():
        assert not any(value in data for value in secrets),'Private fixture value found in '+name
for name in files:
    assert not name.startswith(('work/','tests/runtime/')) and '..' not in Path(name).parts

target=ROOT/'dist'/('SkyNova-Cache-Suite-'+VERSION+'-preview-kit.zip')
with zipfile.ZipFile(target,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for name,data in sorted(files.items()):
        info=zipfile.ZipInfo(name,(2026,10,3,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o644<<16
        z.writestr(info,data)
with zipfile.ZipFile(target) as z:
    assert z.testzip() is None
record={'file':target.name,'version':VERSION,'status':'development preview','files':len(files),'bytes':target.stat().st_size,'sha256':hashlib.sha256(target.read_bytes()).hexdigest()}
(ROOT/'dist/release.json').write_text(json.dumps(record,indent=2)+'\n')
print(json.dumps(record,indent=2))
