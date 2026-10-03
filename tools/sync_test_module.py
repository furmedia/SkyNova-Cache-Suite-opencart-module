"""Copy only generated module files into the two existing isolated fixture shops."""
from pathlib import Path
import shutil
ROOT=Path(__file__).resolve().parents[1]
for family,stage in [('oc3','local-stage'),('oc4','oc4-stage')]:
    v4=family=='oc4';shop=(ROOT/'work'/stage/'shop').resolve(strict=True)
    if not shop.is_relative_to((ROOT/'work'/stage).resolve(strict=True)):raise RuntimeError('Fixture root escaped workspace')
    source=ROOT/'build'/family/('' if v4 else 'upload')
    target=shop/('extension/furmedia_cache' if v4 else '')
    selected=['system/library/furmedia_cache','admin/controller/'+('module' if v4 else 'extension/module')+'/furmedia_cache.php','catalog/controller/'+('module' if v4 else 'extension/module')+'/furmedia_cache.php']
    for relative in selected:
        src=source/relative;dest=target/relative
        if not dest.resolve().is_relative_to(shop):raise RuntimeError('Module destination escaped own fixture')
        if src.is_dir():
            for file in src.rglob('*'):
                if file.is_file():
                    output=dest/file.relative_to(src)
                    if not output.resolve().is_relative_to(shop):raise RuntimeError('Module file escaped own fixture')
                    output.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(file,output)
        else:dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(src,dest)
print('Updated generated module files in own OC3/OC4 fixtures')
