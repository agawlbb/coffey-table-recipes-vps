<?php
declare(strict_types=1);

/** Each AI child opens its own database connection; no inherited PDO/socket state. */
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
