<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$directory=sys_get_temp_dir().'/ctr-account-config-'.bin2hex(random_bytes(8));
mkdir($directory,0700); $previous=getenv('CTR_PRIVATE'); putenv('CTR_PRIVATE='.$directory);
try {
    try { runtimeAccountConfig(); throw new RuntimeException('Missing credential accepted.'); }
    catch (HttpError $error) { if ($error->errorCode!=='account_not_configured') throw $error; }
    file_put_contents($directory.'/firebase.json',json_encode(['firebase_project_id'=>'test-project']));
    file_put_contents($directory.'/firebase-admin.json','{}');
    file_put_contents($directory.'/smtp.json',json_encode(['host'=>'smtp.example.test','username'=>'test','password'=>'fixture-only']));
    $config=runtimeAccountConfig();
    if ($config['smtp']['port']!==465 || $config['smtp']['encryption']!=='ssl' || $config['smtp']['password']!=='fixture-only'
        || $config['firebase_service_account_file']!==$directory.'/firebase-admin.json') throw new RuntimeException('Existing SMTP defaults or private paths lost.');
    // The existing Firebase project check must reject mismatched credentials before any network request.
    try { (new FirebaseAccountDirectory($config))->requireAccount('test'); throw new RuntimeException('Wrong-project credential accepted.'); }
    catch (HttpError $error) { if ($error->status!==503) throw $error; }
    echo "Private account configuration/defaults and credential rejection passed. No network used.\n";
} finally {
    foreach (glob($directory.'/*') as $file) unlink($file);
    rmdir($directory); putenv($previous===false?'CTR_PRIVATE':'CTR_PRIVATE='.$previous);
}
