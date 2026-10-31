<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
try {
    $request=json_decode(stream_get_contents(STDIN,128*1024+1),true,32,JSON_THROW_ON_ERROR);
    $pdo ??=runtimeDatabase(); $verify ??=runtimeVerifier();
    if (!isset($account)) {
        $config=runtimeAccountConfig(); $directory=new FirebaseAccountDirectory($config);
        $account=new AccountService($pdo,$verify,[$directory,'requireAccount'],
            [$directory,'lookup'],[new InvitationMail($config),'send'],[$directory,'verificationLink'],
            [new VerificationMail($config),'send'],$config['app_url']);
    }
    $data=$account->handle($request['token'],$request['path'],$request['body']);
    echo json_encode(['type'=>'response','data'=>$data],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
} catch (HttpError $error) {
    echo json_encode(['type'=>'error','status'=>$error->status,'error'=>['code'=>$error->errorCode,'message'=>$error->getMessage()]],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['type'=>'error','status'=>503,'error'=>['code'=>'account_unavailable','message'=>'The account service is temporarily unavailable. Please try again.']],JSON_THROW_ON_ERROR)."\n";
}
