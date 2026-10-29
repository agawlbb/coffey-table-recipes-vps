<?php
declare(strict_types=1);

use Firebase\JWT\JWT;

final class TimerPush
{
    private ?string $token=null;
    private int $tokenExpires=0;
    public function __construct(private array $config, private ?Closure $transport=null) {}

    public function validateConfiguration(): void
    {
        // FCM validate_only checks authorization and payload without delivering
        // any notification, and does not require an installed app/device token.
        [$status]=$this->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($this->config['firebase_project_id']).'/messages:send',
            json_encode(['validate_only'=>true,'message'=>['topic'=>'timer-sync-configuration-check',
                'data'=>['kind'=>'configuration_check']]],JSON_THROW_ON_ERROR),
            ['Content-Type: application/json','Authorization: Bearer '.$this->accessToken()]);
        if ($status!==200) throw new RuntimeException('Timer push configuration check failed');
    }

    public function send(array $device, array $timer): bool
    {
        $data=['kind'=>'recipe_timer'];
        foreach ($timer as $key=>$value) $data[$key]=is_bool($value)?($value?'1':'0'):(string)$value;
        // Keep cancellations deliverable after the old deadline, but never replay
        // an expired start. Native clients also check deadlines and revisions.
        $ttl=$timer['cancelled']?86400:max(0,min(604800,(int)ceil(($timer['deadline_ms']-microtime(true)*1000)/1000)));
        $message=['token'=>$device['push_token'],'data'=>$data,
            'android'=>['priority'=>'high','ttl'=>$ttl.'s','collapse_key'=>hash('sha256',$timer['timer_id'])]];
        if ($device['platform']==='ios') {
            $aps=['content-available'=>1];
            if (!$timer['cancelled']) $aps['alert']=['title'=>'Cooking timer from another device','body'=>$timer['title'].' — open to confirm your timer is ready.'];
            $message['apns']=['headers'=>['apns-push-type'=>$timer['cancelled']?'background':'alert','apns-priority'=>$timer['cancelled']?'5':'10',
                'apns-expiration'=>(string)(time()+$ttl),'apns-collapse-id'=>hash('sha256',$timer['timer_id'])],'payload'=>['aps'=>$aps]];
        }
        [$status,$body]=$this->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($this->config['firebase_project_id']).'/messages:send',
            json_encode(['message'=>$message],JSON_THROW_ON_ERROR),['Content-Type: application/json','Authorization: Bearer '.$this->accessToken()]);
        foreach ($body['error']['details']??[] as $detail) if (($detail['errorCode']??'')==='UNREGISTERED') return false;
        if ($status!==200) throw new RuntimeException('Timer push unavailable');
        return true;
    }

    private function accessToken(): string
    {
        if ($this->token!==null && time()<$this->tokenExpires) return $this->token;
        // Separate least-privilege sender credential; never broaden the auth lookup key.
        $file=$this->config['timer_push_service_account_file']??'';
        if (!$file || !is_file($file)) throw new RuntimeException('Timer push not configured');
        $key=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
        if (($key['project_id']??'')!==$this->config['firebase_project_id']) throw new RuntimeException('Wrong push project');
        $now=time();
        $assertion=JWT::encode(['iss'=>$key['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.messaging',
            'aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+300],$key['private_key'],'RS256');
        [$status,$body]=$this->post('https://oauth2.googleapis.com/token',http_build_query([
            'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$assertion]),['Content-Type: application/x-www-form-urlencoded']);
        if ($status!==200 || empty($body['access_token'])) throw new RuntimeException('Push authorization unavailable');
        $this->tokenExpires=time()+max(0,(int)($body['expires_in']??3600)-60);
        return $this->token=$body['access_token'];
    }

    private function post(string $url,string $body,array $headers): array
    {
        if ($this->transport!==null) return ($this->transport)($url,$body,$headers);
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $response=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        // Responses/credentials/tokens must never be logged.
        return [$status,is_string($response)?(json_decode($response,true)??[]):[]];
    }
}
