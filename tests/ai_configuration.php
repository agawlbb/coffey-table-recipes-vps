<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$directory=sys_get_temp_dir().'/ctr-ai-config-'.bin2hex(random_bytes(6));
mkdir($directory,0700);
$previous=getenv('CTR_PRIVATE');
try {
    file_put_contents($directory.'/recipe-ai.local.json',json_encode([
        'api_key'=>'fixture-only','model'=>'old-key-file-value','variation_access'=>'disabled',
        'pdftotext'=>'/usr/bin/pdftotext',
    ]));
    putenv('CTR_PRIVATE='.$directory);
    $config=runtimeAiConfig();
    if ($config['model']!=='gpt-5.4-mini' || $config['variation_access']!=='disabled'
        || $config['pdftotext']!=='/usr/bin/pdftotext') throw new RuntimeException('AI configuration policy changed');
    file_put_contents($directory.'/recipe-ai.local.json','{}');
    try { runtimeAiConfig(); throw new LogicException('Missing AI credential accepted'); }
    catch (RuntimeException $error) { if ($error instanceof LogicException) throw $error; }
} finally {
    putenv($previous===false?'CTR_PRIVATE':'CTR_PRIVATE='.$previous);
    unlink($directory.'/recipe-ai.local.json'); rmdir($directory);
}
echo "AI private configuration pins the model, preserves access policy and rejects missing credentials\n";
