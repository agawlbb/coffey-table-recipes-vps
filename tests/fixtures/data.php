<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
final class SnapshotPDO extends PDO {
    public int $reads=0;
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $this->reads++;
        $query=str_replace([' FOR UPDATE',' <=> ','DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 60 DAY)','DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)'],['',' IS ',"datetime('now','-60 days')","datetime('now','-7 days')"],$query);
        $query=str_replace('ON DUPLICATE KEY UPDATE','ON CONFLICT(installation_id) DO UPDATE SET',$query);
        $query=preg_replace('/VALUES\((\w+)\)/','excluded.$1',$query);
        return parent::prepare($query,$options);
    }
}
$pdo=new SnapshotPDO('sqlite:'.(getenv('CTR_TEST_DATABASE') ?: ':memory:')); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn($p)=>'2026-10-23');
foreach (['003_recipes.sql','005_recipe_categories.sql'] as $name) {
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\) ENGINE=/s',file_get_contents(__DIR__.'/'.$name),$tables,PREG_SET_ORDER);
    foreach ($tables as $table) {
        $columns=[];
        foreach (explode("\n",$table[2]) as $line) {
            if (!preg_match('/^\s*([a-z][a-z_]+)\s+(BIGINT|INT|VARCHAR|TEXT|LONGTEXT|DECIMAL|DATETIME|BOOLEAN|ENUM)/',$line,$m)) continue;
            $columns[]=$m[1].' '.($m[1]==='id'?'INTEGER PRIMARY KEY':($m[1]==='revision'?'INTEGER DEFAULT 1':(in_array($m[2],['BIGINT','INT','BOOLEAN'])?'INTEGER':'TEXT')));
        }
        if ($table[1]==='recipes') array_push($columns,'deleted_at TEXT','trash_household_id INTEGER','deleted_by_user_id INTEGER');
        $pdo->exec('CREATE TABLE '.$table[1].' ('.implode(',',$columns).')');
    }
}
$pdo->exec("CREATE TABLE recipe_households (recipe_id INTEGER,household_id INTEGER,removed_at TEXT);
CREATE TABLE household_memberships (household_id INTEGER,user_id INTEGER,role TEXT);
CREATE TABLE households (id INTEGER PRIMARY KEY,ai_images_enabled INTEGER,name TEXT);
INSERT INTO households VALUES (1,1,'One'),(2,0,'Two');
CREATE TABLE household_people (id INTEGER PRIMARY KEY,household_id INTEGER,user_id INTEGER,display_name TEXT);
CREATE TABLE household_invitations (person_id INTEGER,status TEXT,expires_at TEXT,email TEXT);

CREATE TABLE shopping_lists (household_id INTEGER PRIMARY KEY,revision INTEGER,items TEXT);
INSERT INTO household_memberships VALUES (1,1,'owner'),(2,2,'owner');
INSERT INTO household_people VALUES (10,1,1,'Joe');
INSERT INTO recipes (id,household_id,title,revision,source_text) VALUES (1,1,'Soup',2,'PRIVATE SOURCE'),(2,2,'Other',1,'OTHER PRIVATE');
INSERT INTO recipe_households VALUES (1,1,NULL),(2,2,NULL);
INSERT INTO recipe_categories (id,label,position) VALUES (10,'Meals',1);
INSERT INTO recipe_categories (id,label,parent_id,position) VALUES (11,'Soups',10,2);
INSERT INTO recipe_category_assignments (household_id,recipe_id,category_id) VALUES (1,1,11);
INSERT INTO recipe_favorites (recipe_id,household_id,person_id) VALUES (1,1,10);
INSERT INTO recipe_photos(id,household_id,recipe_id,position,source_url,storage_key,is_cover) VALUES(1,1,1,1,'https://coffeybean.com/CoffeyTableRecipes/media/recipes/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg','media/recipes/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg',1);
INSERT INTO recipe_steps (id,household_id,recipe_id,position,instruction) VALUES (1,1,1,1,'Simmer');");
if (class_exists('MealPlanService')) $pdo->exec("CREATE TABLE meal_plans(household_id INTEGER PRIMARY KEY,revision INTEGER,time_zone TEXT);
CREATE TABLE meal_plan_entries(household_id INTEGER,id TEXT,plan_date TEXT,kind TEXT,recipe_id INTEGER,recipe_identity INTEGER,title TEXT,recipe_revision INTEGER,created_at TEXT);
CREATE TABLE IF NOT EXISTS cooking_events(id INTEGER,household_id INTEGER,cooked_at TEXT);
CREATE TABLE IF NOT EXISTS cooking_event_recipes(household_id INTEGER,event_id INTEGER,recipe_id INTEGER,recipe_revision INTEGER);
CREATE TABLE deleted_recipe_cooking_history(household_id INTEGER,event_id INTEGER,recipe_id INTEGER,recipe_title TEXT,recipe_revision INTEGER);
INSERT INTO meal_plans VALUES (1,0,'America/New_York');");
$pdo->exec('CREATE TABLE household_restaurant_lists(household_id INTEGER PRIMARY KEY,revision INTEGER,items_json TEXT)');
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,firebase_uid TEXT,disabled INTEGER);
INSERT INTO users VALUES(1,'alice',0),(2,'bob',0),(3,'disabled',1);
CREATE TABLE shopping_connections(token_hash TEXT PRIMARY KEY,household_id INTEGER,created_at TEXT);
CREATE TABLE recipe_relationship_lock(id INTEGER PRIMARY KEY);
INSERT INTO recipe_relationship_lock VALUES(1);
CREATE TABLE recipe_save_receipts(household_id INTEGER,user_id INTEGER,save_id TEXT,body_hash TEXT,response TEXT,PRIMARY KEY(household_id,user_id,save_id));
ALTER TABLE meal_plan_entries ADD COLUMN restaurant_id TEXT;
ALTER TABLE meal_plan_entries ADD COLUMN restaurant_logo_reference TEXT;
CREATE TABLE timer_devices(installation_id TEXT PRIMARY KEY,user_id TEXT,platform TEXT,push_token TEXT,receive_timers INTEGER,updated_at TEXT);
CREATE TABLE shared_timers(user_id TEXT,timer_id TEXT,source_device TEXT,title TEXT,deadline_ms INTEGER,cancelled INTEGER,revision INTEGER,last_operation TEXT,updated_at TEXT,PRIMARY KEY(user_id,timer_id));");
$verify=static function(string $token): array {
    if (!in_array($token,['alice','bob','disabled'],true)) throw new HttpError(401,'invalid_token','Invalid token.');
    return ['uid'=>$token];
};
$connections=new HomeAssistantConnections($pdo);
$requests=new ShoppingSyncRequests((getenv('CTR_TEST_DATABASE') ?: sys_get_temp_dir().'/ctr-data-fixture').'-requests');
$socket=new DataSocket($pdo,$verify,new ShoppingSocket($pdo,$verify,$connections,$requests),static fn()=>true);
