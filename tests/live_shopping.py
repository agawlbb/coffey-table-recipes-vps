"""Check real WSS/SQL authentication and revocation without editing shopping data."""
import json
from pathlib import Path
import select
import subprocess

ROOT = Path(__file__).resolve().parents[1]
COMPOSE = str(ROOT / 'compose.yaml')
DEV = '/home/debian/.local/bin/dev'

PHP = r'''
require '/app/src/HttpError.php';
require '/app/src/HomeAssistantConnections.php';
try {
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $c=json_decode(file_get_contents('/run/ctr-private/database.json'),true,512,JSON_THROW_ON_ERROR);
    $o=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>10];
    if($c['tls']??true){$o[PDO::MYSQL_ATTR_SSL_CA]=$c['ca_file']??'/etc/ssl/certs/ca-certificates.crt';$o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;}
    $pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],$o);
    $connections=new HomeAssistantConnections($pdo);
    if(isset($input['token'])){$connections->revoke($input['token']);echo '{}';}
    else {
        $id=$pdo->query('SELECT id FROM households ORDER BY id LIMIT 1')->fetchColumn();
        if($id===false)throw new RuntimeException('No household');
        echo json_encode($connections->create((string)$id),JSON_THROW_ON_ERROR);
    }
}catch(Throwable $e){fwrite(STDERR,"Private verification operation failed.\n");exit(1);}
'''

CLIENT = r'''
import asyncio,json,sys
import aiohttp
credential=json.loads(sys.stdin.readline())
async def check():
    async with aiohttp.ClientSession() as session:
        for attempt in range(12):
            try:
                ws=await session.ws_connect('wss://ctr.coffeybean.com/shopping',timeout=aiohttp.ClientWSTimeout(ws_receive=15),max_msg_size=5*1024*1024)
                break
            except (aiohttp.ClientError,asyncio.TimeoutError):
                if attempt==11:raise
                await asyncio.sleep(3)
        async with ws:
            await ws.send_json({'v':1,'type':'auth','token':credential['token']})
            ready=await ws.receive_json()
            if ready.get('type')!='ready' or ready.get('household_id')!=credential['household_id']:raise RuntimeError('Authentication failed')
            if not isinstance(ready.get('data',{}).get('items'),list):raise RuntimeError('Read failed')
            await ws.send_json({'v':1,'type':'request','id':'verify','path':'/households/'+credential['household_id']+'/shopping-list'})
            reply=await ws.receive_json()
            if reply.get('type')!='response' or reply.get('id')!='verify':raise RuntimeError('Read failed')
            print('Authenticated public WSS shopping read passed.',flush=True)
            if (await asyncio.to_thread(sys.stdin.readline)).strip()!='revoked':raise RuntimeError('Verification interrupted')
            await ws.send_json({'v':1,'type':'ping'})
            denied=await ws.receive_json()
            if denied.get('type')!='error' or denied.get('status')!=401:raise RuntimeError('Revocation failed')
            print('Credential revocation on the open WSS connection passed.',flush=True)
try:asyncio.run(check())
except Exception:raise SystemExit('Public shopping verification failed (private details suppressed).') from None
'''


def database(payload):
    result = subprocess.run(['sudo', '-n', 'docker', 'compose', '-f', COMPOSE,
                             'exec', '-T', 'backend', 'php', '-r', PHP],
                            input=json.dumps(payload), text=True, capture_output=True, check=True)
    return json.loads(result.stdout)


def verify():
    credential = database({})
    client = None
    revoked = False
    try:
        client = subprocess.Popen([DEV, 'exec', '.venv/bin/python', '-c', CLIENT], cwd=ROOT,
                                  stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
        client.stdin.write(json.dumps(credential) + '\n'); client.stdin.flush()
        if not select.select([client.stdout], [], [], 60)[0]:
            raise RuntimeError('Public shopping service not ready')
        line = client.stdout.readline()
        if line != 'Authenticated public WSS shopping read passed.\n':
            raise RuntimeError('Public authentication/read failed')
        print(line.strip(), flush=True)
        database({'token': credential['token']})
        revoked = True
        client.stdin.write('revoked\n'); client.stdin.flush()
        output, _ = client.communicate(timeout=25)
        if client.returncode != 0 or 'Credential revocation on the open WSS connection passed.' not in output:
            raise RuntimeError('Open-connection revocation failed')
        print(output.strip(), flush=True)
    finally:
        if client is not None and client.poll() is None:
            client.terminate(); client.wait(timeout=10)
        if not revoked:
            database({'token': credential['token']})


if __name__ == '__main__':
    try:
        verify()
    except Exception:
        raise SystemExit('Shopping verification failed; temporary credentials were cleaned up where possible.') from None
