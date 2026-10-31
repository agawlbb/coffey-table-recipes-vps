<?php
declare(strict_types=1);

/** Each worker opens its own database connection; no inherited PDO/socket state. */
function runtimeDatabase(): PDO {
    $private=getenv('CTR_PRIVATE') ?: '/run/ctr-private';
    $db=json_decode(file_get_contents($private.'/database.json'),true,16,JSON_THROW_ON_ERROR);
    return openDatabase(['host'=>$db['host'],'port'=>$db['port']??3306,'name'=>$db['database'],
        'user'=>$db['username'],'password'=>$db['password'],'tls'=>$db['tls']??true,
        'ca_file'=>$db['ca_file']??'/etc/ssl/certs/ca-certificates.crt']);
}

function runtimeVerifier(): Closure {
    $private=getenv('CTR_PRIVATE') ?: '/run/ctr-private';
    $firebase=json_decode(file_get_contents($private.'/firebase.json'),true,16,JSON_THROW_ON_ERROR);
    return static fn(string $token): array => (new FirebaseIdentity($firebase['firebase_project_id'],'firebasePublicKeys'))->verify($token);
}

function runtimeAiConfig(?callable $progress=null): array {
    $private=getenv('CTR_PRIVATE') ?: '/run/ctr-private';
    $config=json_decode(file_get_contents($private.'/recipe-ai.local.json'),true,16,JSON_THROW_ON_ERROR);
    if (!is_array($config) || !is_string($config['api_key']??null) || $config['api_key']==='') throw new RuntimeException('AI credential unavailable');
    // Preserve the recipe app's model and access defaults, independent of old key-file model values.
    return array_replace(['variation_model'=>'gpt-5.4-mini','image_model'=>'gpt-image-2.5-flare',
        'variation_access'=>'all_members','variation_billing'=>'paid'], $config,
        ['model'=>'gpt-5.4-mini','progress'=>$progress]);
}


function runtimeAccountConfig(): array {
    $private=getenv('CTR_PRIVATE') ?: '/run/ctr-private';
    if (!is_file($private.'/smtp.json') || !is_file($private.'/firebase-admin.json')) throw new HttpError(503,'account_not_configured','The account service is not configured yet.');
    $firebase=json_decode(file_get_contents($private.'/firebase.json'),true,16,JSON_THROW_ON_ERROR);
    $smtp=json_decode(file_get_contents($private.'/smtp.json'),true,16,JSON_THROW_ON_ERROR);
    if (!is_array($smtp)) throw new HttpError(503,'mail_not_configured','Email delivery is not configured yet.');
    $smtp=array_replace(['host'=>'','port'=>465,'encryption'=>'ssl','username'=>'','password'=>'',
        'from_email'=>'CoffeyTableRecipies@coffeybean.com','from_name'=>'Coffey Table Recipes'],$smtp);
    return ['firebase_project_id'=>$firebase['firebase_project_id'],
        'firebase_service_account_file'=>$private.'/firebase-admin.json','smtp'=>$smtp,
        'app_url'=>'https://coffeybean.com/CoffeyTableRecipes/app/'];
}

function runtimeAccountService(PDO $pdo,Closure $verify,?callable $progress=null): AccountService {
    $config=runtimeAccountConfig(); $directory=new FirebaseAccountDirectory($config);
    return new AccountService($pdo,$verify,[$directory,'requireAccount'],[$directory,'lookup'],
        [new InvitationMail($config),'send'],[$directory,'verificationLink'],[new VerificationMail($config),'send'],
        $config['app_url'],[$directory,'delete'],[$directory,'all'],$progress);
}
function runtimeHomeAssistantConfig(): array {
    $private=getenv('CTR_PRIVATE') ?: '/run/ctr-private';
    $file=$private.'/home-assistant.json';
    if (!is_file($file)) throw new HttpError(503,'auth_unavailable','Home Assistant sign-in is not configured.');
    $data=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
    if (!is_string($data['api_key']??null) || $data['api_key']==='') throw new HttpError(503,'auth_unavailable','Home Assistant sign-in is not configured.');
    return ['api_key'=>$data['api_key']];
}
