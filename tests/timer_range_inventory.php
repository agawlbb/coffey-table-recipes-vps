<?php
declare(strict_types=1);
require __DIR__.'/fixtures/data.php';
require dirname(__DIR__).'/src/TimerRangeInventory.php';
function inventoryCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdo->exec("UPDATE recipe_steps SET instruction='Simmer 3 to 5 minutes.' WHERE id=1;
INSERT INTO recipe_step_actions(id,household_id,recipe_id,step_id,position,action_type,label,duration_seconds) VALUES(1,1,1,1,1,'timer','Keep my label',180);
INSERT INTO recipe_steps(id,household_id,recipe_id,position,instruction) VALUES(3,1,1,2,'Rest for 1-2 hours.'),(4,1,1,3,'Check the noodles 1-2 minutes early.'),(5,1,1,4,'Cook 3 minutes, check, add up to 2 minutes.');");
$plan=TimerRangeInventory::survey($pdo);
inventoryCheck($plan['recipe_count']===2 && count($plan['candidates'])===4,'Full inventory and candidates');
inventoryCheck(count($plan['recipes'][0]['changes'])===3,'Offset excluded; missing range timers proposed');
$pdo->exec("UPDATE recipe_steps SET instruction='Edited by somebody else' WHERE id=3");
try { TimerRangeInventory::apply($pdo,$plan); throw new LogicException('Stale snapshot accepted'); }
catch (RuntimeException $e) { inventoryCheck(str_contains($e->getMessage(),'changed since survey'),'Stale review rejected'); }
inventoryCheck($pdo->query('SELECT duration_max_seconds FROM recipe_step_actions WHERE id=1')->fetchColumn()===null,'No partial updates');
$pdo->exec("UPDATE recipe_steps SET instruction='Rest for 1-2 hours.' WHERE id=3");
$counts=TimerRangeInventory::apply($pdo,$plan);
inventoryCheck($counts===['recipes'=>1,'updated'=>1,'added'=>2],'Verified change counts');
$saved=TimerRangeInventory::snapshot($pdo,'1');
inventoryCheck($saved['actions'][0]['label']==='Keep my label' && (int)$saved['actions'][0]['duration_max_seconds']===300,'Original label preserved');
inventoryCheck((int)$saved['recipe']['revision']===3,'Revision advanced exactly once');
inventoryCheck($saved['steps']===$plan['recipes'][0]['before']['steps'],'Instructions untouched');
$again=TimerRangeInventory::survey($pdo);
inventoryCheck(TimerRangeInventory::apply($pdo,$again)===['recipes'=>0,'updated'=>0,'added'=>0],'Resurvey is idempotent');
echo "Timer inventory: exclusions, missing actions, snapshots, stale-edit rollback, preservation and idempotence passed.\n";
