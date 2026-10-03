"""Run PHP compatibility checks; captures evidence without private runtime/config files."""
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import subprocess,json,time
ROOT=Path(__file__).resolve().parents[1]
PHP=Path(r'C:\laragon\bin\php')
RUNTIMES={
 '5.6.40':PHP/'php-5.6.40-Win32-VC11-x64/php.exe',
 '7.4.33':PHP/'php-7.4.33-Win32-vc15-x64/php.exe',
 '8.2.32':PHP/'php-8.2.32-Win32-vs16-x64/php.exe',
 '8.3.32':PHP/'php-8.3.32-Win32-vs16-x64/php.exe',
}
files=sorted((ROOT/'src').rglob('*.php'))+sorted((ROOT/'build').rglob('*.php'))+sorted((ROOT/'tools').glob('*.php'))+sorted((ROOT/'tests').glob('*.php'))
def lint(item):
 version,binary=item;fail=[]
 for path in files:
  r=subprocess.run([str(binary),'-l',str(path)],capture_output=True,text=True,timeout=15)
  if r.returncode:fail.append({'file':path.relative_to(ROOT).as_posix(),'error':r.stdout+r.stderr})
 r=subprocess.run([str(binary),str(ROOT/'tests/core.php')],capture_output=True,text=True,timeout=30)
 return {'php':version,'lint_files':len(files),'lint_failures':fail,'core_exit':r.returncode,'core_output':r.stdout+r.stderr}
with ThreadPoolExecutor(max_workers=4) as pool:results=list(pool.map(lint,RUNTIMES.items()))
native=[]
for family,version,source,runtime in [('oc23','2.3.0.2','oc23','5.6.40'),('oc3','3.0.5.0','oc305','8.3.32'),('oc3','3.0.5.1','opencart-3.0.5.1/upload','8.3.32'),('oc4','4.0.2.3','oc4023','8.3.32'),('oc4','4.1.0.3','oc4103','8.3.32')]:
 r=subprocess.run([str(RUNTIMES[runtime]),'tests/native.php',family,version,'C:/Users/PC/Desktop/Opencart/'+source],cwd=ROOT,capture_output=True,text=True,timeout=30)
 native.append({'opencart':version,'php':runtime,'exit':r.returncode,'output':r.stdout+r.stderr})
report={'checked_at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'runtimes':results,'native':native}
(ROOT/'docs/validation').mkdir(parents=True,exist_ok=True)
(ROOT/'docs/validation/php-matrix.json').write_text(json.dumps(report,indent=2))
for r in results:print('PHP',r['php'],'lint',r['lint_files'],'failures',len(r['lint_failures']),r['core_output'].strip())
for r in native:print(r['output'].strip())
if any(r['lint_failures'] or r['core_exit'] for r in results) or any(r['exit'] for r in native):raise SystemExit(1)
