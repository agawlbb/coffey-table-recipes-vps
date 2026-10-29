<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE recipe_save_receipts(household_id INTEGER,user_id INTEGER,save_id TEXT,body_hash TEXT,response TEXT,PRIMARY KEY(household_id,user_id,save_id))');
function receiptCheck(bool $value): void { if (!$value) throw new RuntimeException('Recipe receipt regression'); }
$body = ['client_save_id'=>str_repeat('a',32),'title'=>'Soup','ingredients'=>[['id'=>'new-i','original_text'=>'water']]];
$first = new RecipeSaveReceipts($pdo,'1','1','',$body);
receiptCheck($first->replay() === null);
$pdo->beginTransaction();
$first->record(['id'=>'9','revision'=>'2','ingredients'=>[['id'=>'22']]]);
$pdo->rollBack();
receiptCheck($first->replay() === null);
$pdo->beginTransaction();
$saved = ['id'=>'9','revision'=>'2','ingredients'=>[['id'=>'22']]];
$first->record($saved);
$pdo->commit();
receiptCheck((new RecipeSaveReceipts($pdo,'1','1','',array_reverse($body,true)))->replay() === $saved);
receiptCheck((new RecipeSaveReceipts($pdo,'2','1','',$body))->replay() === null);
receiptCheck((new RecipeSaveReceipts($pdo,'1','2','',$body))->replay() === null);
foreach ([array_replace($body,['title'=>'Different']), $body] as $i=>$changed) {
    try { (new RecipeSaveReceipts($pdo,'1','1',$i ? '9' : '',$changed))->replay(); throw new RuntimeException('Reuse accepted'); }
    catch (HttpError $e) { receiptCheck($e->status === 409); }
}
foreach ([12, [], '', 'short'] as $bad) {
    try { new RecipeSaveReceipts($pdo,'1','1','',['client_save_id'=>$bad]); throw new RuntimeException('Invalid key accepted'); }
    catch (HttpError $e) { receiptCheck($e->status === 422); }
}
receiptCheck((new RecipeSaveReceipts($pdo,'1','1','',[]))->replay() === null);
echo "Recipe receipts: immutable replay, rollback, scope, canonical hashes and invalid keys passed\n";
