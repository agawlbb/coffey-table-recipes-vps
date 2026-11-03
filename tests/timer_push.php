<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$key=openssl_pkey_new(['private_key_bits'=>2048]);
openssl_pkey_export($key,$private);
$file=tempnam(sys_get_temp_dir(),'ctr-push-test-');
try {
    chmod($file,0600);
    file_put_contents($file,json_encode(['project_id'=>'fixture','client_email'=>'fixture@example.test','private_key'=>$private],JSON_THROW_ON_ERROR));
    foreach ([3600=>1,1=>2] as $expires=>$expected) {
        $exchanges=0; $sends=0;
        $sender=new TimerPush(['firebase_project_id'=>'fixture','timer_push_service_account_file'=>$file],
            static function(string $url,string $body,array $headers) use (&$exchanges,&$sends,$expires): array {
                if ($url==='https://oauth2.googleapis.com/token') {
                    $exchanges++; return [200,['access_token'=>'fixture-token-'.$exchanges,'expires_in'=>$expires]];
                }
                if (!str_starts_with($url,'https://fcm.googleapis.com/')) throw new RuntimeException('Unexpected request');
                $sends++;
                if (!in_array('Authorization: Bearer fixture-token-'.$exchanges,$headers,true)) throw new RuntimeException('Stale token used');
                return [200,[]];
            });
        $sender->validateConfiguration();$sender->validateConfiguration();
        if ($exchanges!==$expected || $sends!==2) throw new RuntimeException('FCM token lifetime regression');
    }
    $messages=[];
    $sender=new TimerPush(['firebase_project_id'=>'fixture','timer_push_service_account_file'=>$file],
        static function(string $url,string $body,array $headers) use (&$messages): array {
            if ($url==='https://oauth2.googleapis.com/token') return [200,['access_token'=>'fixture','expires_in'=>3600]];
            $messages[]=json_decode($body,true,32,JSON_THROW_ON_ERROR)['message']; return [200,[]];
        });
    $device=['push_token'=>'fixture-token','platform'=>'ios'];
    $timer=['timer_id'=>'session:'.str_repeat('a',32).':1','session_id'=>str_repeat('a',32),'revision'=>2,'title'=>'Soup','deadline_ms'=>(int)(microtime(true)*1000)+600000,'cancelled'=>false];
    $sender->send($device,$timer);
    $timer['cancelled']=true;$sender->send($device,$timer);
    $sender->send($device,['kind'=>'cooking_invitation','session_id'=>str_repeat('a',32),'title'=>'Soup']);
    foreach(array_slice($messages,0,2) as $message) {
        if (isset($message['apns']['payload']['aps']['alert']) || $message['apns']['headers']['apns-push-type']!=='background' || $message['apns']['headers']['apns-priority']!=='5') throw new RuntimeException('Session timer update was not silent');
    }
    if ($messages[2]['apns']['payload']['aps']['alert']['title']!=='Cooking invitation' || $messages[2]['data']['kind']!=='cooking_invitation') throw new RuntimeException('Invitation is not visible');
    $device['platform']='android';$sender->send($device,$timer);
    if(isset($messages[3]['notification']) || $messages[3]['android']['priority']!=='normal') throw new RuntimeException('Android session changes must be data-only normal priority');
    echo "Long-running timer sender reuses valid tokens and renews expired tokens before delivery\n";
} finally { unlink($file); }
