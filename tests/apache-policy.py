"""Real Apache response-header verification in an isolated loopback, single-process fixture."""
from pathlib import Path
import subprocess,time,json,requests
ROOT=Path(__file__).resolve().parents[1]
WORK=ROOT/'work/apache-policy';WEB=WORK/'web';WEB.mkdir(parents=True,exist_ok=True)
APACHE=Path('C:/laragon/bin/apache/httpd-2.4.66-260223-Win64-VS18')
PHP='C:/laragon/bin/php/php-8.3.32-Win32-vs16-x64/php.exe'
script=WORK/'profile.php'
script.write_text("<?php require "+repr((ROOT/'src/core/bootstrap.php').as_posix())+";echo FurMedia\\Cache\\Delivery::profile(FurMedia\\Cache\\Settings::normalize(array('browser_css'=>120,'browser_js'=>180)));",encoding='utf8')
profile=subprocess.run([PHP,str(script)],capture_output=True,check=True).stdout.decode('utf8').split('\n# Alternative:')[0]
(WEB/'.htaccess').write_text(profile,encoding='utf8')
for ext in ['css','js','jpg','pdf','mp4','woff2','html','php']:(WEB/('fixture.'+ext)).write_text('public static test fixture',encoding='utf8')
config=WORK/'httpd.conf'
lines=[f'ServerRoot "{APACHE.as_posix()}"','Listen 127.0.0.1:8798','ServerName 127.0.0.1',f'PidFile "{(WORK/"apache.pid").as_posix()}"',f'ErrorLog "{(WORK/"error.log").as_posix()}"']
for module in ['authz_core','authz_host','mime','expires','dir']:
 lines.append(f'LoadModule {module}_module modules/mod_{module}.so')
lines.extend([f'TypesConfig "{(APACHE/"conf/mime.types").as_posix()}"',f'DocumentRoot "{WEB.as_posix()}"',f'<Directory "{WEB.as_posix()}">','Require all granted','AllowOverride All','Options -Indexes','</Directory>'])
config.write_text('\n'.join(lines)+'\n',encoding='utf8')
binary=str(APACHE/'bin/httpd.exe')
result=subprocess.run([binary,'-t','-f',str(config)],capture_output=True,text=True)
if result.returncode:raise RuntimeError('Isolated Apache configuration failed: '+result.stderr)
process=subprocess.Popen([binary,'-X','-f',str(config)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW)
checks=[]
try:
 for attempt in range(20):
  if process.poll() is not None:raise RuntimeError('Own Apache process exited')
  try:
   requests.get('http://127.0.0.1:8798/fixture.html',timeout=1);break
  except requests.ConnectionError:time.sleep(.2)
 for ext,ttl in [('css',120),('js',180),('jpg',31536000),('pdf',86400),('mp4',2592000),('woff2',31536000)]:
  response=requests.get('http://127.0.0.1:8798/fixture.'+ext,timeout=5)
  assert response.status_code==200 and response.headers.get('Cache-Control')=='max-age='+str(ttl),(ext,response.headers)
  checks.append('Real Apache static '+ext+' max-age '+str(ttl))
 for ext in ['html','php']:
  response=requests.get('http://127.0.0.1:8798/fixture.'+ext,timeout=5)
  assert 'Expires' not in response.headers and 'Cache-Control' not in response.headers
  checks.append('Real Apache '+ext+' excluded from static expiration policy')
 (ROOT/'docs/validation/apache-policy.json').write_text(json.dumps({'version':'0.5.0','checks':checks,'environment':'Own Apache 2.4.66 single-process loopback fixture','production_changed':False},indent=2),encoding='utf8')
 print('PASS',len(checks),'real Apache browser-policy checks')
finally:
 process.terminate()
 try:process.wait(timeout=10)
 except subprocess.TimeoutExpired:process.kill();process.wait(timeout=5)
