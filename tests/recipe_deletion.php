<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/src/RecipeShare.php';

// In-memory fixtures only: never connects to a local or hosted recipe database.
// SQL adaptations cover timestamps/locking syntax, not MySQL concurrency or DDL.
final class TrashFixtureStatement extends PDOStatement {
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        try { return parent::execute($params); }
        catch (PDOException $error) {
            // Translate only the production import-key uniqueness constraint.
            if (str_contains($error->getMessage(), 'recipes.household_id, recipes.import_key')) $error->errorInfo[1] = 1062;
            throw $error;
        }
    }
}
final class TrashFixtureDatabase extends PDO {
    private function sql(string $sql): string {
        return str_replace(['DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 DAY)','UTC_TIMESTAMP(6)',' FOR UPDATE',' <=> '], ["datetime('now','+30 days')","datetime('now')",'',' IS '], $sql);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare($this->sql($query),$options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { return parent::query($this->sql($query),$fetchMode,...$args); }
}
$pdo = new TrashFixtureDatabase('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[TrashFixtureStatement::class]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec("CREATE TABLE household_memberships (household_id INTEGER,user_id INTEGER,role TEXT);
 CREATE TABLE households (id INTEGER,ai_images_enabled INTEGER);
 INSERT INTO households VALUES (1,0),(2,0);
 INSERT INTO household_memberships VALUES (1,1,'owner'),(1,3,'member'),(2,2,'owner');");
// Retain the real recipe column names and child foreign keys in a SQLite fixture.
foreach (['003_recipes.sql','005_recipe_categories.sql'] as $name) {
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\) ENGINE=/s',file_get_contents(__DIR__.'/fixtures/'.$name),$tables,PREG_SET_ORDER);
    foreach ($tables as $table) {
        $columns = [];
        foreach (explode("\n",$table[2]) as $line) {
            if (!preg_match('/^\s*([a-z][a-z_]+)\s+(BIGINT|INT|VARCHAR|TEXT|LONGTEXT|DECIMAL|DATETIME|BOOLEAN|ENUM)/',$line,$m)) continue;
            $columns[] = $m[1].' '.($m[2]==='BIGINT' || $m[2]==='INT' || $m[2]==='BOOLEAN' ? 'INTEGER' : 'TEXT').($m[1]==='revision' ? ' DEFAULT 1' : '');
        }
        if ($table[1]==='recipes') {
            $columns = array_filter($columns,fn($c)=>!str_starts_with($c,'id '));
            array_unshift($columns,'id INTEGER PRIMARY KEY');
            array_push($columns,'deleted_at TEXT','purge_after TEXT','deleted_by_user_id INTEGER','trash_household_id INTEGER','UNIQUE(household_id,id)','FOREIGN KEY (parent_recipe_id) REFERENCES recipes(id)');
        } elseif (!in_array($table[1],['recipe_categories','cooking_events'],true)) {
            // Fixture cleanup mirrors the production recipe cascades.
            $columns[] = 'FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE';
        }
        $pdo->exec('CREATE TABLE '.$table[1].' ('.implode(',',$columns).')');
    }
}
$pdo->exec('CREATE UNIQUE INDEX household_import_key ON recipes(household_id,import_key)');
$pdo->exec('CREATE TABLE deleted_recipe_cooking_history (household_id INTEGER,event_id INTEGER,recipe_id INTEGER,recipe_title TEXT,recipe_revision INTEGER,servings_made TEXT,notes TEXT,PRIMARY KEY(event_id,recipe_id))');
$pdo->exec("INSERT INTO recipes(id,household_id,title,revision) VALUES (1,1,'Soup',1),(2,1,'Bread',1),(3,2,'Private',1);
 INSERT INTO recipe_steps(id,household_id,recipe_id,position,instruction) VALUES (1,1,1,0,'Simmer');
 INSERT INTO recipe_categories VALUES(1,NULL,'meals','Meals','meals',0),(2,1,'soups','Soups','soups',0);
 INSERT INTO recipe_category_assignments VALUES(1,1,2);
 INSERT INTO recipe_pairings VALUES(1,1,2,'Serve together');
 INSERT INTO cooking_event_recipes VALUES(1,1,1,1,'2','Good soup');
 UPDATE recipes SET parent_recipe_id=1 WHERE id=2;");
$pdo->exec('CREATE TABLE recipe_relationship_lock(id INTEGER PRIMARY KEY); INSERT INTO recipe_relationship_lock VALUES(1)');
$pdo->exec('CREATE TABLE recipe_households(recipe_id INTEGER,household_id INTEGER,removed_at TEXT,PRIMARY KEY(recipe_id,household_id),FOREIGN KEY(recipe_id) REFERENCES recipes(id) ON DELETE CASCADE)');
$pdo->exec('INSERT INTO recipe_households(recipe_id,household_id) SELECT id,household_id FROM recipes');
$households = new HouseholdService($pdo); $trash = new RecipeDeletionService($pdo,$households); $read = new RecipeService($pdo,$households);
$checks=0;
function checkTrash(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function rejectTrash(callable $action,int $status): void { try { $action(); } catch (HttpError $e) { checkTrash($e->status===$status,'Wrong error status'); return; } throw new RuntimeException('Expected rejection'); }
rejectTrash(fn()=>$trash->delete('2','1','1',['revision'=>'1']),404);
rejectTrash(fn()=>$trash->delete('1','1','3',['revision'=>'1']),404);
rejectTrash(fn()=>$trash->delete('1','1','1',['revision'=>'0']),409);
$deleted=$trash->delete('3','1','1',['revision'=>'1']);
checkTrash($deleted['deleted_recipe_id']==='1' && str_ends_with($deleted['purge_after'],'Z'),'Delete response');
checkTrash(abs(strtotime($deleted['purge_after'])-time()-30*86400)<3,'Recovery deadline');
checkTrash($trash->delete('1','1','1',['revision'=>'1'])===$deleted,'Retry restarted expiry');
checkTrash(count($trash->recentlyDeleted('1','1')['recipes'])===1,'Trash inventory');
checkTrash(count($trash->recentlyDeleted('2','2')['recipes'])===0,'Cross-household inventory');
checkTrash(count($read->browse('1','1')['recipes'])===1,'Deleted recipe browsable');
checkTrash($read->browse('1','1',tree:true)['category_nodes'][0]['recipe_count']==0,'Category count includes trash');
checkTrash(count($read->browse('1','1',scope:'1',tree:true)['category_nodes'])===0,'Empty subcategory visible');
checkTrash(count($read->editOptions('1','1','2')['recipes'])===0,'Deleted recipe selectable');
rejectTrash(fn()=>$read->detail('1','1','1'),404);
rejectTrash(fn()=>RecipeShare::load($pdo,'1'),404);
$editor=new RecipeEditorService($pdo,$households,'unused','https://example.test/media');
rejectTrash(fn()=>$editor->save('1','1','1',['revision'=>'1']),404);
$restored=$trash->restore('1','1','1')['recipe'];
checkTrash($restored['id']==='1' && count($restored['steps'])===1 && count($restored['pairings'])===1,'Restore lost content or relationships');
checkTrash($restored['revision']==='3','Restore must invalidate stale edits');
checkTrash(count($trash->recentlyDeleted('1','1')['recipes'])===0,'Restored recipe stays in trash');
checkTrash($trash->restore('1','1','1')['recipe']['revision']==='3','Restore retry changes revision');
$media=dirname(__DIR__).'/storage/trash-fixture-'.bin2hex(random_bytes(8));
if (!mkdir($media,0700)) throw new RuntimeException('Fixture directory unavailable');
$shared=str_repeat('a',48).'.jpg'; $owned=str_repeat('b',64).'.png';
file_put_contents($media.'/'.$shared,'shared fixture'); file_put_contents($media.'/'.$owned,'owned fixture');
$q=$pdo->prepare('INSERT INTO recipe_photos(id,household_id,recipe_id,position,storage_key) VALUES (?,?,?,?,?)');
$q->execute([1,1,1,0,'media/recipes/'.$shared]); $q->execute([2,2,3,0,'media/recipes/'.$shared]);
$q->execute([3,1,1,1,'vps/recipes/'.$owned]);
$trash->delete('1','1','1',['revision'=>'3']);
$pdo->exec("UPDATE recipes SET purge_after=datetime('now','-1 second') WHERE id=1");
rejectTrash(fn()=>$trash->restore('1','1','1'),410);
checkTrash(count($trash->recentlyDeleted('1','1')['recipes'])===0,'Expired entry remains restorable');
checkTrash($trash->purgeExpired($media)===1,'Expiry did not purge');
checkTrash($pdo->query('SELECT COUNT(*) FROM recipes WHERE id=1')->fetchColumn()==0,'Recipe retained after expiry');
checkTrash($pdo->query('SELECT COUNT(*) FROM recipe_steps WHERE recipe_id=1')->fetchColumn()==0,'Child content retained');
checkTrash($pdo->query('SELECT parent_recipe_id FROM recipes WHERE id=2')->fetchColumn()===null,'Dangling parent');
checkTrash($pdo->query('SELECT recipe_title FROM deleted_recipe_cooking_history')->fetchColumn()==='Soup','Cooking history lost');
checkTrash($trash->purgeExpired('unused')===0,'Cleanup is not repeatable');
checkTrash(is_file($media.'/'.$shared),'Shared photo was removed');
checkTrash(!is_file($media.'/'.$owned),'Owned expired photo was retained');
unlink($media.'/'.$shared); rmdir($media);
$draft=['title'=>'Soup','original_language'=>'en','measurement_system'=>'unspecified',
    'source_text'=>'Original soup instructions','import_key'=>hash('sha256','reimport-fixture'),
    'ingredients'=>[], 'groups'=>[], 'steps'=>[], 'actions'=>[], 'photos'=>[], 'tags'=>[], 'category_assignments'=>[], 'pairings'=>[]];
$original=$editor->save('1','1','',$draft);
rejectTrash(fn()=>$editor->save('1','1','',$draft),409);
$other=$editor->save('2','2','',$draft);
$beforeDelete=$trash->delete('1','1',$original['id'],['revision'=>$original['revision']]);
$bad=$draft; $bad['title']='';
rejectTrash(fn()=>$editor->save('1','1','',$bad),422);
$q=$pdo->prepare('SELECT import_key FROM recipes WHERE id=?'); $q->execute([$original['id']]);
checkTrash($q->fetchColumn()===$draft['import_key'],'Failed reimport did not roll back key transfer');
$replacement=$editor->save('1','1','',$draft);
checkTrash($replacement['id']!==$original['id'] && $replacement['title']===$original['title'],'Reimport of deleted recipe was not created');
$q->execute([$original['id']]); checkTrash($q->fetchColumn()===null,'Deleted reservation retained');
$q->execute([$other['id']]); checkTrash($q->fetchColumn()===$draft['import_key'],'Other household reservation changed');
checkTrash($trash->recentlyDeleted('1','1')['recipes'][0]['purge_after']===$beforeDelete['purge_after'],'Reimport changed recovery deadline');
rejectTrash(fn()=>$editor->save('1','1','',$draft),409);
$recovered=$trash->restore('1','1',$original['id'])['recipe'];
checkTrash($recovered['id']===$original['id'] && $recovered['title']==='Soup','Original cannot be recovered after reimport');
checkTrash($read->detail('1','1',$replacement['id'])['title']==='Soup','Restoring original replaced the new recipe');
$distinct=$draft; $distinct['import_key']=hash('sha256','different-soup-source');
checkTrash($editor->save('1','1','',$distinct)['title']==='Soup','Distinct source with same title rejected');
// Category review regressions use this disposable in-memory database too.
$categoryDraft=$draft; $categoryDraft['import_key']=hash('sha256','category-choice-fixture');
$categoryDraft['category_assignments']=[['category_id'=>'2','label'=>'Rejected AI category','parent_label'=>'Meals']];
$categorySaved=$editor->save('1','1','',$categoryDraft);
checkTrash((string)$categorySaved['category_assignments'][0]['category_id']==='2','Explicit existing category selection was overwritten by proposal text');
$categoryDraft['import_key']=hash('sha256','self-category-fixture');
$categoryDraft['category_assignments']=[['label'=>'Meals','parent_label'=>'Meals']];
$categorySaved=$editor->save('1','1','',$categoryDraft);
checkTrash((string)$categorySaved['category_assignments'][0]['category_id']==='1','Self-named category proposal did not save as the root');
checkTrash((int)$pdo->query('SELECT COUNT(*) FROM recipe_categories')->fetchColumn()===2,'Self-named category created a child');
echo "$checks recipe deletion fixture checks passed.\n";
