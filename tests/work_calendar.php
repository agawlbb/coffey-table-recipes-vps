<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE household_work_calendars(household_id INTEGER PRIMARY KEY,dates_json TEXT,color TEXT);
CREATE TABLE households(id INTEGER PRIMARY KEY);
CREATE TABLE shopping_connections(token_hash TEXT PRIMARY KEY,household_id INTEGER,created_at TEXT);
INSERT INTO households VALUES(1),(2)");
function check($condition,$message) { if (!$condition) throw new RuntimeException($message); }
function fails($action,$status) { try { $action(); } catch (HttpError $e) { check($e->status===$status,'wrong status');return; } throw new RuntimeException('Expected denial'); }
$connections=new HomeAssistantConnections($pdo);$token=$connections->create('1')['token'];
$socket=new ShoppingSocket($pdo,static fn()=>throw new RuntimeException('Unexpected Firebase authentication'),$connections,new ShoppingSyncRequests(sys_get_temp_dir().'/ctr-work-calendar-unused'));
$calendar=new WorkCalendar($pdo);
check($calendar->read('1')===['dates'=>[],'color'=>'#E09F3E'],'default');
$calendar->save('1',['color'=>'#1976d2'],false);
$result=$socket->handle($token,'1','/households/1/work-calendar',['dates'=>['2026-11-12','2026-11-10','2026-11-10']]);
check($result===['dates'=>['2026-11-10','2026-11-12'],'color'=>'#1976D2'],'dates preserve color');
$socket->handle($token,'1','/households/1/work-calendar',['dates'=>['2026-11-11']]);
check($calendar->read('1')['dates']===['2026-11-11'],'moved/deleted events replace dates');
$calendar->save('1',['color'=>'#388E3C'],false);
check($calendar->read('1')['dates']===['2026-11-11'],'color preserves dates');
check($calendar->read('2')['dates']===[],'household isolation');
fails(fn()=>$socket->handle($token,'2','/households/2/work-calendar',['dates'=>[]]),404);
fails(fn()=>$socket->handle($token,'1','/households/1/work-calendar',['color'=>'#1976D2']),422);
fails(fn()=>$calendar->save('1',['dates'=>[]],false),422);
fails(fn()=>$calendar->save('1',['dates'=>['2026-02-30']],true),422);
$socket->handle($token,'1','/households/1/work-calendar',['dates'=>[]]);
check($calendar->read('1')['dates']===[],'clear calendar');
$connections->revoke($token);
fails(fn()=>$socket->handle($token,'1','/households/1/work-calendar',['dates'=>[]]),401);
echo "work calendar checks passed\n";
