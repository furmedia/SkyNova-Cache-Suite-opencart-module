"""Local fixture browser helper; suppress ephemeral session tokens from CLI output."""
import json,subprocess,sys,re
sys.stdout.reconfigure(encoding='utf-8')
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];WORK=ROOT/'work/local-stage'
auth=json.loads((WORK/'browser-session.json').read_text())
private=json.loads((WORK/'private.json').read_text())
state={'cookies':[{'name':k,'value':v,'domain':'127.0.0.1','path':'/','expires':-1,'httpOnly':False,'secure':False,'sameSite':'Lax'} for k,v in auth['cookies'].items()],'origins':[]}
(WORK/'browser-state.json').write_text(json.dumps(state))
args=sys.argv[1:]
args=[a.replace('LOCAL_ADMIN','http://127.0.0.1:8796/admin/index.php?route=extension/module/furmedia_cache&user_token='+auth['token']) for a in args]
args=[a.replace('LOCAL_USERNAME',private['admin_user']).replace('LOCAL_PASSWORD',private['admin_password']) for a in args]
stdin=None
if args and args[0]=='eval':
    stdin=args[1];args=['eval','--stdin']
r=subprocess.run(['C:/Program Files/nodejs/npx.cmd','--yes','agent-browser','--session','furmedia-cache',*args],input=stdin,capture_output=True,text=True,encoding='utf8',errors='replace')
out=r.stdout+r.stderr
for value in [auth['token'],auth['nonce'],*auth['cookies'].values(),private['admin_password']]:out=out.replace(value,'[redacted]')
out=re.sub(r'user_token=[A-Za-z0-9]+','user_token=[redacted]',out)
print(out);sys.exit(r.returncode)
