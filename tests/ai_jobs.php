<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
function jobCheck(bool $ok): void { if (!$ok) throw new RuntimeException('AI job lifecycle regression'); }
function finishJob(AiJobs $jobs,array &$events): void {
    $deadline=microtime(true)+2;
    while (microtime(true)<$deadline && !array_filter($events,fn($event)=>in_array($event['type'],['response','error'],true))) { $jobs->poll(); usleep(10000); }
}
$events=[]; $finished=[];
$jobs=new AiJobs([PHP_BINARY,'-r','stream_get_contents(STDIN); exit(1);']);
$jobs->start('1',[],function($event) use (&$events){$events[]=$event;},function($ok) use (&$finished){$finished[]=$ok;});
finishJob($jobs,$events);
jobCheck(end($events)['type']==='error' && $finished===[false]);
// A broken child never leaves its connection busy forever.
$events=[];
$jobs->start('1',[],function($event) use (&$events){$events[]=$event;},static function(){});
finishJob($jobs,$events); jobCheck(end($events)['type']==='error');
$jobs=new AiJobs([PHP_BINARY,'-r','stream_get_contents(STDIN); usleep(1000000);']);
$jobs->start('1',[],static function(){},static function(){});
try { $jobs->start('1',[],static function(){},static function(){}); throw new RuntimeException('Concurrent run accepted'); }
catch (HttpError $error) { jobCheck($error->errorCode==='ai_busy'); }
$start=microtime(true); $jobs->stop('1'); $jobs->poll();
jobCheck(microtime(true)-$start<0.8);
echo "AI worker crash recovery, duplicate-run protection and cancellation passed\n";
