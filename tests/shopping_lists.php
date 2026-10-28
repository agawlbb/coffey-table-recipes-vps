<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
final class ShoppingDatabase extends PDO {
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
$pdo = new ShoppingDatabase('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE households (id INTEGER PRIMARY KEY);
CREATE TABLE household_memberships (household_id INTEGER,user_id INTEGER,role TEXT);
CREATE TABLE shopping_lists (household_id INTEGER PRIMARY KEY,revision INTEGER,items TEXT);
INSERT INTO households VALUES (1),(2);
INSERT INTO household_memberships VALUES (1,1,'owner'),(1,2,'member'),(2,3,'owner');");
$membership = static function (string $user, string $id) use ($pdo): void {
    $q=$pdo->prepare('SELECT role FROM household_memberships WHERE user_id=? AND household_id=?');
    $q->execute([$user,$id]);
    if ($q->fetchColumn()===false) throw new HttpError(404,'household_not_found','Household not found.');
};
$service = new ShoppingListService($pdo, $membership);
$connectionService = new ShoppingListService($pdo, static function (string $user,string $id): void {
    if ($id !== '1') throw new HttpError(401, 'invalid_connection', 'Wrong household.');
});
function checkShopping(bool $ok): void { if (!$ok) throw new RuntimeException('Shopping list assertion failed'); }
function rejectShopping(callable $call, int $status): void {
    try { $call(); throw new RuntimeException('Expected rejection'); }
    catch (HttpError $error) { checkShopping($error->status === $status); }
}
checkShopping($service->read('1','1')['revision'] === 0 && $service->read('1','1')['items'] === []);
checkShopping($connectionService->read('', '1')['items'] === []);
rejectShopping(fn()=>$connectionService->read('', '2'),401);
$milk = ['id'=>'milk','name'=>' Milk ','department'=>'Dairy & eggs','checked'=>false];
$apple = ['id'=>'apple','name'=>'Apples','department'=>'Produce','checked'=>false];
$saved = $service->save('1','1',['revision'=>0,'items'=>[$milk,$apple]]);
checkShopping($saved['items'][0]['name'] === 'Milk');
checkShopping($service->read('2','1') === $saved);
$apple['checked'] = true;
$saved = $service->save('2','1',['revision'=>1,'items'=>[$apple,$milk]]);
checkShopping($saved['revision']===2 && $saved['items'][0]['checked']);
rejectShopping(fn()=>$service->save('1','1',['revision'=>1,'items'=>[]]),409);
checkShopping($service->read('1','1') === $saved);
rejectShopping(fn()=>$service->read('3','1'),404);
rejectShopping(fn()=>$service->save('3','1',['revision'=>2,'items'=>[]]),404);
checkShopping($service->read('3','2')['items']===[]);
foreach ([[$milk,$milk], [array_replace($milk,['checked'=>'true'])], [array_replace($milk,['department'=>'Unknown'])], [array_replace($milk,['name'=>' '])], array_fill(0,501,$milk)] as $invalid) {
    rejectShopping(fn()=>$service->save('1','1',['revision'=>2,'items'=>$invalid]),422);
}
$saved = $service->save('1','1',['revision'=>2,'items'=>[$milk]]);
checkShopping(count($saved['items'])===1 && $saved['items'][0]['id']==='milk');
checkShopping($service->save('2','1',['revision'=>3,'items'=>[]])['items']===[]);
$unassigned = array_replace($apple, ['name'=>'Unassigned test item', 'department'=>'', 'checked'=>false]);
$saved = $service->save('1','1',['revision'=>4,'items'=>[$unassigned,$milk]]);
checkShopping($service->read('2','1')['items'][0]['department']==='');
checkShopping($saved['items'][1]['department']==='Dairy & eggs');
$assigned = array_replace($unassigned, ['department'=>'Produce']);
$service->save('2','1',['revision'=>5,'items'=>[$assigned,$milk]]);
checkShopping($service->read('1','1')['items'][0]['department']==='Produce');
$service->save('1','1',['revision'=>6,'items'=>[$unassigned,$milk]]);
checkShopping($service->read('2','1')['items'][0]['department']==='');
rejectShopping(fn()=>$service->save('1','1',['revision'=>7,'items'=>[array_replace($apple,['department'=>null])]]),422);
echo "Shopping list sharing, ordering, validation, isolation and conflict checks passed\n";

// Department configuration is shared and survives writes from older clients.
$saved = $service->save('1','1',['revision'=>7,'departments'=>['Farm stand','Dairy & eggs'], 'items'=>[array_replace($apple,['department'=>'Farm stand']),$milk]]);
checkShopping($service->read('2','1')['departments'] === ['Farm stand','Dairy & eggs']);
$saved = $service->save('2','1',['revision'=>8,'items'=>$saved['items']]);
checkShopping($saved['departments'] === ['Farm stand','Dairy & eggs']);
rejectShopping(fn()=>$service->save('1','1',['revision'=>9,'departments'=>['Farm stand','farm stand'],'items'=>[]]),422);
rejectShopping(fn()=>$service->save('1','1',['revision'=>9,'departments'=>[''],'items'=>[]]),422);
rejectShopping(fn()=>$service->save('1','1',['revision'=>9,'departments'=>null,'items'=>[]]),422);
rejectShopping(fn()=>$service->save('3','1',['revision'=>9,'departments'=>[],'items'=>[]]),404);
rejectShopping(fn()=>$service->save('1','1',['revision'=>9,'departments'=>['Dairy & eggs'],'items'=>$saved['items']]),422);
$saved['items'][0]['department']='';
$saved=$service->save('2','1',['revision'=>9,'departments'=>['Dairy & eggs'],'items'=>$saved['items']]);
checkShopping($saved['items'][0]['department']==='' && $saved['departments']===['Dairy & eggs']);
rejectShopping(fn()=>$service->save('1','1',['revision'=>9,'departments'=>[],'items'=>[]]),409);
checkShopping($service->read('1','1')===$saved);
// Explicitly verify legacy storage remains readable.
$pdo->prepare('UPDATE shopping_lists SET items=? WHERE household_id=1')->execute([json_encode([$milk])]);
checkShopping($service->read('1','1')['items'][0]['name']===' Milk ');
checkShopping(in_array('Produce',$service->read('1','1')['departments'],true));
$legacy=$service->save('1','1',['revision'=>$service->read('1','1')['revision'],'items'=>[['id'=>'legacy-new','name'=>'mILK','department'=>'','checked'=>false]]]);
checkShopping($legacy['items'][0]['department']==='Dairy & eggs');
echo "Department configuration and legacy compatibility checks passed\n";

// Durable household item-to-department memory (all data is an isolated fixture).
$pdo->exec('DELETE FROM shopping_lists');
function memoryItem(string $id, string $name, string $department = ''): array {
    return ['id'=>$id,'name'=>$name,'department'=>$department,'checked'=>false];
}
function memorySave(array $items, array $extra = [], string $user = '1', string $household = '1'): array {
    global $service;
    $revision = $service->read($user, $household)['revision'];
    return $service->save($user, $household, ['revision'=>$revision,'items'=>$items]+$extra);
}
$saved = memorySave([memoryItem('onions','Green onions')]);
checkShopping($saved['items'][0]['department']==='');
$saved['items'][0]['department']='Produce';
memorySave($saved['items']);
memorySave([]);
foreach (['gReEnOnions','green-onions','Green onion','Green oninos','gréen onions'] as $variant) {
    $saved = memorySave([memoryItem('new', $variant)], [], '2');
    if ($saved['items'][0]['department'] !== 'Produce') throw new RuntimeException('Variant failed: '.$variant);
    memorySave([]);
}
// Learning does not cross households, and strangers cannot teach associations.
$saved=memorySave([memoryItem('onions','Green onions')], [], '3', '2');
checkShopping($saved['items'][0]['department']==='');
rejectShopping(fn()=>memorySave([memoryItem('bad','Green onions','Pantry')], [], '3'),404);
// Corrections to a typo replace the prior association, including its canonical name.
$saved=memorySave([memoryItem('onions','Green oninos')]);
$saved['items'][0]['department']='Deli'; memorySave($saved['items']); memorySave([]);
$saved=memorySave([memoryItem('onions','Green onions')]);
checkShopping($saved['items'][0]['department']==='Deli');
// Stale writes cannot replace a confirmed association.
$stale=$saved['revision']; $saved['items'][0]['department']='Dairy & eggs'; memorySave($saved['items']);
rejectShopping(fn()=>$service->save('2','1',['revision'=>$stale,'items'=>[memoryItem('onions','Green onions','Bakery')]]),409);
memorySave([]); $saved=memorySave([memoryItem('onions','Green onions')]);
checkShopping($saved['items'][0]['department']==='Dairy & eggs');
// Explicitly moving to the unassigned group is remembered too.
$saved['items'][0]['department']=''; memorySave($saved['items']); memorySave([]);
$saved=memorySave([memoryItem('onions','GREENONIONS')]);
checkShopping($saved['items'][0]['department']==='');
// Ignore reordering and check-offs: they must not teach a new department.
$saved=memorySave([memoryItem('rice','Rice','Pantry'),memoryItem('milk','Milk 2%','Dairy & eggs')]);
$saved['items'][0]['checked']=true; memorySave(array_reverse($saved['items'])); memorySave([]);
$saved=memorySave([memoryItem('rice','RICE'),memoryItem('milk','Milk 1%'),memoryItem('dice','Dice')]);
checkShopping($saved['items'][0]['department']==='Pantry');
checkShopping($saved['items'][1]['department']==='' && $saved['items'][2]['department']==='');
// Adjacent short-word transpositions and common plural endings are recognized.
memorySave([memoryItem('plainmilk','Milk','Dairy & eggs'), memoryItem('tomato','Tomato','Produce'), memoryItem('berry','Strawberry','Produce')]); memorySave([]);
$saved=memorySave([memoryItem('mikl','Mikl'),memoryItem('tomatoes','Tomatoes'),memoryItem('berries','Strawberries')]);
checkShopping($saved['items'][0]['department']==='Dairy & eggs');
checkShopping($saved['items'][1]['department']==='Produce' && $saved['items'][2]['department']==='Produce');
// Equally close names in different departments remain unassigned.
memorySave([memoryItem('beans','Beans','Pantry'),memoryItem('beets','Beets','Produce')]); memorySave([]);
$saved=memorySave([memoryItem('ambiguous','Beens'),memoryItem('exact','BEANS')]);
checkShopping($saved['items'][0]['department']==='' && $saved['items'][1]['department']==='Pantry');
// Rename memory even when its items are no longer on the list.
memorySave([memoryItem('onions','Green onions','Produce')]); memorySave([]);
$departments=$service->read('1','1')['departments'];
$departments=array_map(fn($d)=>$d==='Produce'?'Farm stand':$d,$departments);
memorySave([],['departments'=>$departments,'department_renames'=>['Produce'=>'Farm stand']]);
$saved=memorySave([memoryItem('onions','GreenOnions')],[],'2');
checkShopping($saved['items'][0]['department']==='Farm stand');
memorySave([],['departments'=>array_values(array_diff($departments,['Farm stand']))]);
$saved=memorySave([memoryItem('onions','Green onions')]);
checkShopping($saved['items'][0]['department']==='');
rejectShopping(fn()=>memorySave([],['department_renames'=>['Pantry'=>'Missing']]),422);
// Bound memory; retain recently taught entries without leaking metadata to clients.
for($batch=0;$batch<3;$batch++) {
    $items=[];
    for($i=0;$i<400;$i++) $items[]=memoryItem('bound'.($batch*400+$i),'Bound item '.($batch*400+$i),'Pantry');
    memorySave($items);
}
$stored=json_decode($pdo->query('SELECT items FROM shopping_lists WHERE household_id=1')->fetchColumn(),true);
checkShopping(count($stored['associations'])===1000);
checkShopping(!array_key_exists('associations',$service->read('1','1')));
memorySave([]); $saved=memorySave([memoryItem('last','Bound item 1199')]);
checkShopping($saved['items'][0]['department']==='Pantry');
echo "Household association normalization, typo matching, correction, isolation, rename and retention checks passed\n";

// Notes belong to an entry, not the name-to-department association.
$noted = memoryItem('joe-deodorant','Deodorant','Household');
$noted['note'] = ' For Joe · 3 bottles ';
$saved = memorySave([$noted]);
checkShopping($service->read('2','1')['items'][0]['note'] === 'For Joe · 3 bottles');
$legacy = $saved['items'][0]; unset($legacy['note']); $legacy['checked'] = true;
$saved = memorySave([$legacy], [], '2');
checkShopping($saved['items'][0]['note'] === 'For Joe · 3 bottles');
$saved['items'][0]['note'] = '';
checkShopping(memorySave($saved['items'])['items'][0]['note'] === '');
memorySave([$noted]); memorySave([]);
$saved = memorySave([memoryItem('new-deodorant','deodorant')]);
checkShopping($saved['items'][0]['department'] === 'Household' && !isset($saved['items'][0]['note']));
foreach ([null, 12, [], str_repeat('n',501)] as $invalidNote) {
    rejectShopping(fn()=>memorySave([array_replace($noted,['note'=>$invalidNote])]),422);
}
echo "Entry notes sharing, legacy preservation, clearing, validation and association isolation passed\n";

// Repeat-safe changes: one user's lost response must not become a revision conflict.
$pdo->exec("INSERT INTO households VALUES (3); INSERT INTO household_memberships VALUES (3,1,'owner')");
$seed=$service->save('1','3',['revision'=>0,'items'=>[
    memoryItem('a','Milk','Dairy & eggs'),memoryItem('b','Bread','Bakery')]]);
$when=(int)(microtime(true)*1000)+10;
$deletion=['revision'=>$seed['revision'],'items'=>[$seed['items'][1]],'base_items'=>$seed['items'],
    'client_change_id'=>'delete-once','edited_at_ms'=>$when+20];
$deleted=$service->save('1','3',$deletion); // Imagine the HTTP response was lost.
$restarted=new ShoppingListService($pdo,$membership);
$replayed=$restarted->save('1','3',$deletion);
checkShopping($replayed['revision']===$deleted['revision'] && count($replayed['items'])===1);
checkShopping($replayed['acknowledged_change_id']==='delete-once');
$oldMove=$deletion; $oldMove['client_change_id']='older-move'; $oldMove['edited_at_ms']=$when;
$oldMove['items']=$seed['items']; $oldMove['items'][0]['department']='Produce';
checkShopping(count($restarted->save('1','3',$oldMove)['items'])===1);
$current=$restarted->read('1','3');
$move=['revision'=>$current['revision'],'base_items'=>$current['items'],'items'=>$current['items'],
    'client_change_id'=>'newer-move','edited_at_ms'=>$when+40];
$move['items'][0]['department']='Pantry';
$moved=$restarted->save('1','3',$move);
$rename=$move; $rename['client_change_id']='rename-item'; $rename['edited_at_ms']=$when+50;
$rename['items']=$current['items']; $rename['items'][0]['name']='Whole wheat bread';
$renamed=$restarted->save('1','3',$rename);
checkShopping($renamed['items'][0]['department']==='Pantry' && $renamed['items'][0]['name']==='Whole wheat bread');
$late=$move; $late['client_change_id']='late-old-move'; $late['edited_at_ms']=$when+30;
$late['items'][0]['department']='Deli';
checkShopping($restarted->save('1','3',$late)['items'][0]['department']==='Pantry');
$again=$restarted->save('1','3',$deletion);
checkShopping($again['items'][0]['name']==='Whole wheat bread');
$collision=$deletion; $collision['items']=[];
rejectShopping(fn()=>$restarted->save('1','3',$collision),409);
checkShopping(!isset($restarted->read('1','3')['sync']));
echo "Shopping delete durability, receipt replay, older-edit suppression and independent field merging passed\n";

$pdo->exec("INSERT INTO households VALUES (4); INSERT INTO household_memberships VALUES (4,1,'owner')");
$oldClient=$service->save('1','4',['revision'=>0,'items'=>[memoryItem('old','Old client item')]]);
$oldDelete=['revision'=>$oldClient['revision'],'items'=>[]];
$confirmed=$service->save('1','4',$oldDelete);
$retried=$service->save('1','4',$oldDelete);
checkShopping($retried===$confirmed);
rejectShopping(fn()=>$service->save('1','4',['revision'=>$oldClient['revision'],'items'=>[memoryItem('old','Old client item')]]),409);
echo "Legacy single-device lost-delete-response retry is acknowledged without overwriting any newer data\n";
