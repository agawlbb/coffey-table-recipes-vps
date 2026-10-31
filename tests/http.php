<?php
declare(strict_types=1);
require __DIR__.'/fixtures/account.php';
$pdo=new AccountFixturePDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
accountSchema($pdo);
$pdo->exec('DROP TABLE recipes; DROP TABLE cooking_events');
// Real renderer queries against disposable copies of the recipe table columns.
preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\) ENGINE=/s',file_get_contents(__DIR__.'/fixtures/003_recipes.sql'),$tables,PREG_SET_ORDER);
foreach ($tables as $table) {
    $columns=[];
    foreach (explode("\n",$table[2]) as $line) {
        if (preg_match('/^\s*([a-z][a-z_]+)\s+(BIGINT|INT|VARCHAR|TEXT|LONGTEXT|DECIMAL|DATETIME|BOOLEAN|ENUM)/',$line,$m)) $columns[]=$m[1].' TEXT';
    }
    if ($table[1]==='recipes') $columns[]='deleted_at TEXT';
    $pdo->exec('CREATE TABLE '.$table[1].' ('.implode(',',$columns).')');
}
$pdo->exec("INSERT INTO recipes(id,household_id,title,source_type,revision,source_text) VALUES(1,1,'Soup <script>x</script>','manual',1,'PRIVATE');
 INSERT INTO recipes(id,title,source_type) VALUES(2,'Blank','blank');
 INSERT INTO recipes(id,title,deleted_at) VALUES(3,'Trash','2026-10-01');");
$name=str_repeat('a',64).'.jpg';
$pdo->prepare('INSERT INTO recipe_photos(id,household_id,recipe_id,position,source_url,storage_key) VALUES(1,1,1,0,?,?)')->execute(['https://coffeybean.com/CoffeyTableRecipes/media/recipes/'.$name,'media/recipes/'.$name]);
$directory=sys_get_temp_dir().'/ctr-http-'.bin2hex(random_bytes(8)); mkdir($directory);
file_put_contents($directory.'/'.$name,'image-fixture');
symlink($directory.'/'.$name,$directory.'/category-2.jpg');
$account=accountFixture($pdo);
$service=new HttpService($pdo,fn()=>$account,fn()=>['api_key'=>'public-fixture'],$directory,dirname(__DIR__).'/associations');
$checks=0;
function httpCheck(bool $ok):void {global $checks;if(!$ok)throw new RuntimeException('HTTP migration regression at check '.($checks+1));$checks++;}
function httpJson(array $response):array {return json_decode($response['body'],true,32,JSON_THROW_ON_ERROR);}
// Model a connection that the server closes while the HTTP worker is idle.
final class ExpiringPagePDO extends PDO {
    public bool $expired=false;
    public function __construct(private PDO $fixture) {}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if ($this->expired) throw new PDOException('MySQL server has gone away');
        return $this->fixture->prepare($query,$options);
    }
}
try {
    $firstConnection=new ExpiringPagePDO($pdo); $connections=0;
    $factory=static function()use(&$connections,$firstConnection,$pdo):PDO {
        return ++$connections===1?$firstConnection:$pdo;
    };
    $pages=new HttpService($factory,fn()=>$account,fn()=>[],$directory,dirname(__DIR__).'/associations');
    httpCheck($pages->handle('GET','/recipes/1')['status']===200);
    $firstConnection->expired=true;
    httpCheck($pages->handle('GET','/recipes/1')['status']===200);
    $static=new HttpService(static function(){throw new RuntimeException('Static requests must not connect to MySQL');},static function(){throw new RuntimeException('No account request expected');},fn()=>['api_key'=>'public-fixture'],$directory,dirname(__DIR__).'/associations');
    httpCheck($static->handle('GET','/health')['status']===200);
    httpCheck($static->handle('GET','/.well-known/assetlinks.json')['status']===200);
    httpCheck($static->handle('GET','/media/recipes/'.$name)['status']===200);
    $page=$service->handle('GET','/recipes/1');
    httpCheck($page['status']===200 && str_contains($page['body'],'Soup &lt;script&gt;'));
    httpCheck(!str_contains($page['body'],'PRIVATE') && !str_contains($page['body'],'<script>x'));
    httpCheck(str_contains($page['body'],'https://ctr.coffeybean.com/recipes/1') && str_contains($page['body'],'https://ctr.coffeybean.com/media/recipes/'.$name));
    httpCheck(str_contains($page['body'],'https://coffeybean.com/CoffeyTableRecipes/app/#/recipes/1'));
    $head=$service->handle('HEAD','/recipes/1');
    httpCheck($head['body']==='' && $head['headers']['Content-Length']===(string)strlen($page['body']));
    foreach(['/recipes/2','/recipes/3','/recipes/999','/recipes/0','/recipes/1 OR 1=1'] as $path) httpCheck($service->handle('GET',$path)['status']===404);
    httpCheck($service->handle('POST','/recipes/1')['status']===405);
    httpCheck($service->handle('GET','/media/recipes/'.$name)['file']===$directory.'/'.$name);
    httpCheck($service->handle('GET','/media/recipes/category-2.jpg')['status']===404);
    httpCheck($service->handle('GET','/media/recipes/../../private.json')['status']===404);
    foreach(['assetlinks.json','apple-app-site-association'] as $name) httpCheck($service->handle('GET','/.well-known/'.$name)['status']===200);
    httpCheck(httpJson($service->handle('GET','/api/v1/home-assistant/auth-config'))===['api_key'=>'public-fixture']);
    httpCheck($service->handle('GET','/api/v1/households')['status']===401);
    httpCheck($service->handle('GET','/api/v1/admin/users',['Authorization'=>'Bearer admin'])['status']===404);
    $owner=$account->handle('new','/households',['name'=>'Table','person_name'=>'Owner']); $id=$owner['household']['id'];
    $headers=['Authorization'=>'Bearer new','Content-Type'=>'application/json','Origin'=>'https://coffeybean.com'];
    $listed=$service->handle('GET','/api/v1/households',$headers);
    httpCheck(count(httpJson($listed)['households'])===1 && $listed['headers']['Access-Control-Allow-Origin']==='https://coffeybean.com');
    $path='/api/v1/households/'.$id.'/home-assistant-connections';
    httpCheck($service->handle('POST',$path,$headers,'[]')['status']===400);
    httpCheck($service->handle('POST',$path,$headers,'{')['status']===400);
    httpCheck($service->handle('POST',$path,$headers,str_repeat(' ',16385))['status']===413);
    $created=httpJson($service->handle('POST',$path,$headers,'{}'));
    httpCheck(str_starts_with($created['token'],'ctrha_'));
    $connections=httpJson($service->handle('GET',$path,$headers))['connections'];
    httpCheck(count($connections)===1 && !isset($connections[0]['token']));
    httpCheck($service->handle('DELETE','/api/v1/home-assistant/connection',['Authorization'=>'Bearer '.$created['token']])['status']===200);
    httpCheck($service->handle('DELETE','/api/v1/home-assistant/connection',['Authorization'=>'Bearer '.$created['token']])['status']===401);
    $service->handle('POST',$path,$headers,'{}');
    $row=httpJson($service->handle('GET',$path,$headers))['connections'][0];
    httpCheck($service->handle('DELETE',$path.'/'.$row['id'],$headers)['status']===200);
    httpCheck($service->handle('GET',$path,['Authorization'=>'Bearer guest'])['status']===404);
} finally {unlink($directory.'/category-2.jpg');foreach(glob($directory.'/*') as $file)unlink($file);rmdir($directory);}
echo "$checks rendered-page, media, associations and full HA bootstrap HTTP checks passed.\n";
