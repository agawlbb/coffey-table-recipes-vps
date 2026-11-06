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
$saved=$service->save('1','1',['revision'=>0,'items'=>[]]);
checkShopping($saved['items']===[]);
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
$saved=$service->save('1','1',['departments'=>[],'items'=>[]]);
checkShopping($saved['items']===[]);
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
$service->save('2','1',['revision'=>$stale,'items'=>[memoryItem('onions','Green onions','Bakery')]]);
memorySave([]); $saved=memorySave([memoryItem('onions','Green onions')]);
checkShopping($saved['items'][0]['department']==='Bakery');
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

// Delayed removals affect only the original identity, including after retries.
$service->save('1','1',['items'=>[memoryItem('old-milk','Milk'),memoryItem('new-milk','Milk'),memoryItem('bread','Bread')]]);
$removed=$service->save('1','1',['remove_ids'=>['old-milk']]);
checkShopping(array_column($removed['items'],'id')===['new-milk','bread']);
$removed=$service->save('1','1',['remove_ids'=>['old-milk']]);
checkShopping(array_column($removed['items'],'id')===['new-milk','bread']);
rejectShopping(fn()=>$service->save('3','1',['remove_ids'=>['new-milk']]),404);
echo "Last update wins and item-specific repeatable removals passed\n";

// Photos share note semantics, including old clients and independent concurrent edits.
$photoItem = array_replace($milk, ['photo'=>str_repeat('a',64)]);
$current = $service->read('1','1');
$saved = $service->save('1','1',['revision'=>$current['revision'],'items'=>[$photoItem]]);
checkShopping($service->read('2','1')['items'][0]['photo'] === $photoItem['photo']);
$legacy = $photoItem; unset($legacy['photo']);
$saved = $service->save('1','1',['revision'=>$saved['revision'],'items'=>[$legacy]]);
checkShopping($saved['items'][0]['photo'] === $photoItem['photo']);
$saved = $service->save('1','1',['revision'=>$saved['revision'],'items'=>[array_replace($photoItem,['photo'=>''])]]);
checkShopping($saved['items'][0]['photo'] === '');
foreach ([null, true, '../outside', 'https://example.com/photo.jpg'] as $badPhoto) {
    rejectShopping(fn()=>$service->save('1','1',['revision'=>$saved['revision'],'items'=>[array_replace($photoItem,['photo'=>$badPhoto])]]),422);
}
$saved = $service->save('1','1',['revision'=>$saved['revision'],'items'=>[]]);
$saved = $service->save('1','1',['revision'=>$saved['revision'],'items'=>[array_replace($legacy,['id'=>'new-photo-item'])]]);
checkShopping(!isset($saved['items'][0]['photo']));
echo "Shopping photo metadata, legacy omission, clearing and concurrent field preservation passed\n";

// An offline item edit does not replace unrelated items added on another device.
$service->save('1','1',['items'=>[memoryItem('first','Apples'),memoryItem('remote','Bread')]]);
$patched=$service->save('1','1',['upsert_items'=>[memoryItem('first','Pears'),memoryItem('local','Milk')],'remove_ids'=>['absent'],'order'=>['local','first']]);
checkShopping(array_column($patched['items'],'id')===['local','first','remote']);
checkShopping($patched['items'][1]['name']==='Pears');
echo "Item patches preserve unrelated server additions passed\n";
