<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';


use Workerman\Worker;
use Workerman\Timer;
use Workerman\Connection\TcpConnection;

// One worker deliberately owns all subscriptions. SQL transactions remain the
// authority; adding workers requires shared publication, not per-worker lists.
TcpConnection::$defaultMaxPackageSize = 32 * 1024 * 1024;
$worker = new Worker(getenv('CTR_LISTEN') ?: 'websocket://0.0.0.0:8080');
$worker->count = 1;
$worker->name = 'ctr-backend';
$runtime = getenv('CTR_RUNTIME_DIR') ?: sys_get_temp_dir();
Worker::$logFile = $runtime.'/ctr-backend.log';
Worker::$pidFile = $runtime.'/ctr-backend.pid';
$clients = [];
$aiJobs ??=new AiJobs();
$accountJobs ??=new AiJobs([PHP_BINARY,__DIR__.'/account-worker.php'],'Account',85);
$factory ??= static function (bool $reset=false): ?DataSocket {
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
    $push=new TimerPush(['firebase_project_id'=>$firebase['firebase_project_id'],
        'timer_push_service_account_file'=>$private.'/timer-push.json']);
    return $service=new DataSocket($pdo,$verify,new ShoppingSocket($pdo,$verify,new HomeAssistantConnections($pdo),
        new ShoppingSyncRequests(CTR_STORAGE.'/shopping-sync-requests')),Closure::fromCallable([$push,'send']));
};
$send = static fn(TcpConnection $c, array $message) => $c->send(json_encode($message + ['server_time_ms'=>(int)(microtime(true)*1000)], JSON_THROW_ON_ERROR));
$broadcast = static function (array $source) use (&$clients, $factory, $send): void {
    foreach ($clients as $entry) {
        if (!isset($entry['token'])) continue;
        $scope=$entry['scope'] ?? 'shopping';
        if ($scope==='public') continue;
        if ($source['scope']==='timers') {
            if ($scope!=='timers' || $entry['actor']['user']!==$source['actor']['user']) continue;
        } elseif (!in_array($scope,['shopping','household'],true)) continue;
        // Canonical recipes can belong to multiple households. Refresh each
        // subscribed household independently; never send another user's snapshot.
        elseif ($source['scope']==='shopping' && $entry['household']!==$source['household']) continue;
        try {
            $service=$factory();
            $actor=$service->authenticate($entry['token'],$entry['household'],$scope);
            $send($entry['connection'],['type'=>'snapshot','household_id'=>$entry['household'],'data'=>$service->snapshot($actor)]);
        } catch (HttpError $e) {
            $send($entry['connection'],['type'=>'error','status'=>$e->status,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]]);
            $entry['connection']->close();
        } catch (Throwable $e) { $entry['connection']->close(); }
    }
};
$scheduleImages=static function(array $source,array $data) use ($factory,$aiJobs,$broadcast): void {
    if (($source['scope']??'')!=='household' || !isset($data['recipe']['id'])) return;
    try {
        $ids=$factory()->missingCategoryImages($source['actor'],$data);
        if (!$ids) return;
        $aiJobs->start('artwork-'.$source['household'].'-'.$data['recipe']['id'],
            ['token'=>$source['token'],'household'=>$source['household'],'scope'=>'ai',
                'path'=>'/households/'.$source['household'].'/category-image','body'=>null,'category_ids'=>$ids],
            static function(array $event): void { if (($event['type']??'')==='error') error_log('Automatic category artwork generation failed.'); },
            static function(bool $success) use ($source,$broadcast): void { if ($success) $broadcast($source); });
    } catch (HttpError $error) {
        if ($error->errorCode!=='ai_busy') error_log('Could not start automatic category artwork.');
    } catch (Throwable $error) { error_log('Could not start automatic category artwork.'); }
};
$worker->onConnect = static function (TcpConnection $c) use (&$clients): void {
    if (count($clients) >= 64) { $c->close(); return; }
    $c->maxSendBufferSize=32 * 1024 * 1024;
    $c->onBufferFull = static function (TcpConnection $slow): void { $slow->close(); };
    $clients[$c->id] = ['connection'=>$c, 'connected'=>time(), 'last'=>time(), 'rate'=>0, 'window'=>time()];
};
$worker->onMessage = static function (TcpConnection $c, string $text) use (&$clients, $factory, $send, $broadcast, $aiJobs,$scheduleImages,$accountJobs): void {
    $id = null;
    try {
        $m = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($m) || ($m['v'] ?? null) !== 1) throw new HttpError(422, 'invalid_message', 'Unsupported protocol.');
        $entry = &$clients[$c->id];
        if (time() - $entry['window'] >= 60) { $entry['window']=time(); $entry['rate']=0; }
        if (++$entry['rate'] > 120) throw new HttpError(429, 'rate_limited', 'Data synchronization is busy.');
        $entry['last'] = time();
        if (($m['type'] ?? '') === 'auth') {
            if (!is_string($m['token'] ?? null) || strlen($m['token']) > 16384) throw new HttpError(401, 'invalid_token', 'Sign in again.');
            $household = $m['household_id'] ?? null;
            if ($household !== null && (!is_string($household) || !preg_match('/^[1-9][0-9]{0,18}$/D', $household))) throw new HttpError(422, 'invalid_household', 'Choose a household.');
            $scope=$m['scope']??'shopping';
            if (!is_string($scope)) throw new HttpError(422,'invalid_scope','Invalid scope.');
            $service = $factory(); $actor = $service->authenticate($m['token'], $household, $scope);
            $accountJobs->stop((string)$c->id);
            $entry['scope']=$scope; $entry['actor']=$actor;
            $entry['token']=$m['token']; $entry['household']=$actor['household'];
            $data=in_array($scope,['ai','account'],true) ? (object)[] : $service->snapshot($actor);
            $send($c, ['type'=>'ready','household_id'=>$entry['household'],'data'=>$data]);
        } else {
            if (!isset($entry['token'])) throw new HttpError(401, 'authentication_required', 'Authenticate first.');
            $service=$factory();
            if (($m['type'] ?? '') === 'ping') {
                $service->authenticate($entry['token'], $entry['household'], $entry['scope']);
                $send($c, ['type'=>'pong']); return;
            }
            $id=$m['id'] ?? null;
            if (($m['type'] ?? '') !== 'request' || !is_string($id) || strlen($id)>80 || !is_string($m['path'] ?? null)
                || (isset($m['body']) && !is_array($m['body']))) throw new HttpError(422, 'invalid_message', 'Invalid data request.');
            $body=$m['body'] ?? null;
            if (AccountService::matches($m['path'])) {
                if ($entry['scope']!=='account') throw new HttpError(403,'permission_denied','Use an account connection.');
                if (strlen($text)>128*1024) throw new HttpError(413,'request_too_large','Account request is too large.');
                $service->authenticate($entry['token'],null,'account');
                $accountJobs->start((string)$c->id,['token'=>$entry['token'],'path'=>$m['path'],'body'=>$body],
                    static function(array $event) use ($c,$id,$send): void { $send($c,$event+['id'=>$id]); },static function(){});
                return;
            }
            if ($entry['scope']==='account') throw new HttpError(404,'not_found','Account endpoint not found.');
            if (AiService::matches($m['path']) || AiService::categorizesSave($m['path'],$body)) {
                if ($entry['scope']!=='ai' && !AiService::categorizesSave($m['path'],$body)) throw new HttpError(403,'permission_denied','Use an AI connection.');
                if ($entry['scope']==='shopping' || $entry['scope']==='public' || $entry['scope']==='timers') throw new HttpError(403,'permission_denied','AI access denied.');
                $service->authenticate($entry['token'],$entry['household'],$entry['scope']);
                $source=$entry;
                $aiJobs->start((string)$c->id,['token'=>$entry['token'],'household'=>$entry['household'],'scope'=>$entry['scope'],'path'=>$m['path'],'body'=>$body],
                    static function(array $event) use ($c,$id,$send): void { $send($c,$event+['id'=>$id]); },
                    static function(bool $success,?array $data=null) use ($source,$broadcast,$body,$scheduleImages): void {
                        if ($success && $body!==null) { $broadcast($source); if ($data!==null) $scheduleImages($source,$data); }
                    });
                return;
            }
            if ($entry['scope']==='ai') throw new HttpError(404,'not_found','AI endpoint not found.');
            $data=$service->handle($entry['token'], $entry['household'], $m['path'], $body, $entry['scope']);
            $send($c, ['type'=>'response','id'=>$id,'data'=>$data]);
            if ($body !== null) {
                $broadcast($entry);
                $scheduleImages($entry,$data);
            }
        }
    } catch (HttpError $e) {
        $send($c, ['type'=>'error','id'=>$id,'status'=>$e->status,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]]);
        if (in_array($e->status, [401,403,404], true)) $c->close();
    } catch (JsonException $e) { $send($c, ['type'=>'error','id'=>$id,'status'=>422,'error'=>['message'=>'Invalid data message.']]); }
    catch (PDOException $e) { $factory(true); $send($c,['type'=>'error','id'=>$id,'status'=>503,'error'=>['message'=>'Database connection interrupted. Retry shortly.']]); }
    catch (Throwable $e) { $send($c, ['type'=>'error','id'=>$id,'status'=>503,'error'=>['message'=>'Data synchronization temporarily unavailable.']]); }
};
$worker->onClose = static function (TcpConnection $c) use (&$clients,$aiJobs,$accountJobs): void { $aiJobs->stop((string)$c->id); $accountJobs->stop((string)$c->id); unset($clients[$c->id]); };
$worker->onWorkerStart = static function () use (&$clients,$aiJobs,$accountJobs): void {
    Timer::add(0.1,static fn()=>$accountJobs->poll());
    Timer::add(0.1,static fn()=>$aiJobs->poll());
    Timer::add(5, static function () use (&$clients): void {
        foreach ($clients as $entry) {
            if ((!isset($entry['token']) && time()-$entry['connected']>10) || time()-$entry['last']>70) $entry['connection']->close();
        }
    });
};
$media = new Worker(getenv('CTR_MEDIA_LISTEN') ?: 'http://0.0.0.0:8081');
$media->count=1;
$media->onMessage=static function(TcpConnection $c, Workerman\Protocols\Http\Request $request): void {
    $path=$request->path();
    if ($request->method()!=='GET' || !preg_match('#^/media/recipes/((?:[a-f0-9]{48}\.(?:jpg|png|webp)|category-[1-9][0-9]*\.jpg))$#D',$path,$m)
        || !is_file($file=CTR_STORAGE.'/media/recipes/'.$m[1])) {
        $c->send(new Workerman\Protocols\Http\Response(404,[],'Not found')); return;
    }
    $response=new Workerman\Protocols\Http\Response(200,['Access-Control-Allow-Origin'=>'*','X-Content-Type-Options'=>'nosniff']);
    $response->withFile($file); $c->send($response);
};
Worker::runAll();
