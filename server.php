<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';


use Workerman\Worker;
use Workerman\Timer;
use Workerman\Connection\TcpConnection;

// One worker deliberately owns all subscriptions. SQL transactions remain the
// authority; adding workers requires shared publication, not per-worker lists.
TcpConnection::$defaultMaxPackageSize = 4 * 1024 * 1024 + 65536;
$worker = new Worker(getenv('CTR_LISTEN') ?: 'websocket://0.0.0.0:8080');
$worker->count = 1;
$worker->name = 'ctr-backend';
$runtime = getenv('CTR_RUNTIME_DIR') ?: sys_get_temp_dir();
Worker::$logFile = $runtime.'/ctr-backend.log';
Worker::$pidFile = $runtime.'/ctr-backend.pid';
$clients = [];
$factory ??= static function (bool $reset=false): ?ShoppingSocket {
    static $service=null;
    if ($reset) { $service=null; return null; }
    if ($service!==null) return $service;
    $private = getenv('CTR_PRIVATE') ?: '/run/ctr-private';
    $db = json_decode(file_get_contents($private.'/database.json'), true, 16, JSON_THROW_ON_ERROR);
    $firebase = json_decode(file_get_contents($private.'/firebase.json'), true, 16, JSON_THROW_ON_ERROR);
    $pdo=openDatabase(['host'=>$db['host'],'port'=>$db['port']??3306,'name'=>$db['database'],
        'user'=>$db['username'],'password'=>$db['password'],'tls'=>$db['tls']??true,
        'ca_file'=>$db['ca_file']??'/etc/ssl/certs/ca-certificates.crt']);
    $verify = static fn(string $token): array => (new FirebaseIdentity($firebase['firebase_project_id'], 'firebasePublicKeys'))->verify($token);
    return $service=new ShoppingSocket($pdo, $verify, new HomeAssistantConnections($pdo), new ShoppingSyncRequests(CTR_STORAGE.'/shopping-sync-requests'));
};
$send = static fn(TcpConnection $c, array $message) => $c->send(json_encode($message + ['server_time_ms'=>(int)(microtime(true)*1000)], JSON_THROW_ON_ERROR));
$broadcast = static function (string $household, array $data) use (&$clients, $factory, $send): void {
    foreach ($clients as $entry) {
        if (($entry['household'] ?? null) !== $household) continue;
        try {
            $factory()->authenticate($entry['token'], $household);
            $send($entry['connection'], ['type'=>'snapshot', 'household_id'=>$household, 'data'=>$data]);
        } catch (HttpError $e) {
            $send($entry['connection'], ['type'=>'error', 'status'=>$e->status, 'error'=>['code'=>$e->errorCode, 'message'=>$e->getMessage()]]);
            $entry['connection']->close();
        } catch (Throwable $e) { $entry['connection']->close(); }
    }
};
$worker->onConnect = static function (TcpConnection $c) use (&$clients): void {
    if (count($clients) >= 64) { $c->close(); return; }
    $c->onBufferFull = static function (TcpConnection $slow): void { $slow->close(); };
    $clients[$c->id] = ['connection'=>$c, 'connected'=>time(), 'last'=>time(), 'rate'=>0, 'window'=>time()];
};
$worker->onMessage = static function (TcpConnection $c, string $text) use (&$clients, $factory, $send, $broadcast): void {
    $id = null;
    try {
        $m = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($m) || ($m['v'] ?? null) !== 1) throw new HttpError(422, 'invalid_message', 'Unsupported protocol.');
        $entry = &$clients[$c->id];
        if (time() - $entry['window'] >= 60) { $entry['window']=time(); $entry['rate']=0; }
        if (++$entry['rate'] > 120) throw new HttpError(429, 'rate_limited', 'Shopping synchronization is busy.');
        $entry['last'] = time();
        if (($m['type'] ?? '') === 'auth') {
            if (!is_string($m['token'] ?? null) || strlen($m['token']) > 16384) throw new HttpError(401, 'invalid_token', 'Sign in again.');
            $household = $m['household_id'] ?? null;
            if ($household !== null && (!is_string($household) || !preg_match('/^[1-9][0-9]{0,18}$/D', $household))) throw new HttpError(422, 'invalid_household', 'Choose a household.');
            $service = $factory(); $actor = $service->authenticate($m['token'], $household);
            $entry['token']=$m['token']; $entry['household']=$actor['household'];
            $data=$service->snapshot($actor);
            $send($c, ['type'=>'ready','household_id'=>$entry['household'],'data'=>$data]);
        } else {
            if (!isset($entry['token'])) throw new HttpError(401, 'authentication_required', 'Authenticate first.');
            $service=$factory();
            if (($m['type'] ?? '') === 'ping') {
                $service->authenticate($entry['token'], $entry['household']);
                $send($c, ['type'=>'pong']); return;
            }
            $id=$m['id'] ?? null;
            if (($m['type'] ?? '') !== 'request' || !is_string($id) || strlen($id)>80 || !is_string($m['path'] ?? null)
                || (isset($m['body']) && !is_array($m['body']))) throw new HttpError(422, 'invalid_message', 'Invalid shopping request.');
            $body=$m['body'] ?? null;
            $data=$service->handle($entry['token'], $entry['household'], $m['path'], $body);
            $send($c, ['type'=>'response','id'=>$id,'data'=>$data]);
            if ($body !== null) {
                $snapshot=isset($data['items'])?$data:$service->handle($entry['token'],$entry['household'],'/households/'.$entry['household'].'/shopping-list',null);
                unset($snapshot['acknowledged_change_id']);
                $broadcast($entry['household'],$snapshot);
            }
        }
    } catch (HttpError $e) {
        $send($c, ['type'=>'error','id'=>$id,'status'=>$e->status,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]]);
        if (in_array($e->status, [401,403,404], true)) $c->close();
    } catch (JsonException $e) { $send($c, ['type'=>'error','id'=>$id,'status'=>422,'error'=>['message'=>'Invalid shopping message.']]); }
    catch (PDOException $e) { $factory(true); $send($c,['type'=>'error','id'=>$id,'status'=>503,'error'=>['message'=>'Database connection interrupted. Retry shortly.']]); }
    catch (Throwable $e) { $send($c, ['type'=>'error','id'=>$id,'status'=>503,'error'=>['message'=>'Shopping synchronization temporarily unavailable.']]); }
};
$worker->onClose = static function (TcpConnection $c) use (&$clients): void { unset($clients[$c->id]); };
$worker->onWorkerStart = static function () use (&$clients): void {
    Timer::add(5, static function () use (&$clients): void {
        foreach ($clients as $entry) {
            if ((!isset($entry['token']) && time()-$entry['connected']>10) || time()-$entry['last']>70) $entry['connection']->close();
        }
    });
};
Worker::runAll();
