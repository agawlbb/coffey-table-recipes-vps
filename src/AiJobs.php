<?php
declare(strict_types=1);

/** Small nonblocking bridge to isolated PHP AI children. Socket heartbeats keep running. */
final class AiJobs {
    private array $jobs=[];
    public function __construct(private array $command=[PHP_BINARY,__DIR__.'/../ai-worker.php']) {}

    public function start(string $key,array $request,callable $emit,callable $done): void {
        if (isset($this->jobs[$key]) || count($this->jobs)>=4) throw new HttpError(409,'ai_busy','An AI request is already running. Please wait.');
        $process=proc_open($this->command,[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start AI worker');
        $input=json_encode($request,JSON_THROW_ON_ERROR); $offset=0;
        while ($offset<strlen($input)) {
            $written=fwrite($pipes[0],substr($input,$offset));
            if ($written===false || $written===0) { fclose($pipes[0]); fclose($pipes[1]); proc_terminate($process); proc_close($process); throw new RuntimeException('Cannot submit AI request'); }
            $offset+=$written;
        }
        fclose($pipes[0]); stream_set_blocking($pipes[1],false);
        $this->jobs[$key]=['process'=>$process,'pipe'=>$pipes[1],'buffer'=>'','started'=>microtime(true),'last'=>microtime(true),'emit'=>$emit,'done'=>$done,'message'=>'Preparing the request…'];
        $emit(['type'=>'progress','message'=>'Preparing the request…','elapsed_seconds'=>0]);
    }

    public function poll(): void {
        foreach (array_keys($this->jobs) as $key) {
            $key=(string)$key;
            if (!isset($this->jobs[$key])) continue;
            $job=&$this->jobs[$key];
            $job['buffer'].=stream_get_contents($job['pipe']);
            if (strlen($job['buffer'])>16*1024*1024) { $this->fail($key,'AI returned too much data. Please try a smaller request.'); continue; }
            while (($end=strpos($job['buffer'],"\n"))!==false) {
                $line=substr($job['buffer'],0,$end); $job['buffer']=substr($job['buffer'],$end+1);
                try { $event=json_decode($line,true,32,JSON_THROW_ON_ERROR); }
                catch (Throwable $e) { $this->fail($key,'AI could not complete this request. Please try again.'); break; }
                if (($event['type']??'')==='progress') { $job['message']=$event['message']; $job['last']=microtime(true); $event['elapsed_seconds']=(int)(microtime(true)-$job['started']); ($job['emit'])($event); }
                elseif (in_array($event['type']??'', ['response','error'],true)) {
                    ($job['emit'])($event); $done=$job['done']; $success=$event['type']==='response'; $this->stop($key); $done($success); break;
                }
            }
            unset($job);
            if (!isset($this->jobs[$key])) continue;
            $job=&$this->jobs[$key]; $now=microtime(true);
            if ($now-$job['started']>600) { $this->fail($key,'AI took too long. Your input is still here; please try again.'); }
            elseif (!proc_get_status($job['process'])['running']) { $this->fail($key,'AI was interrupted. Your input is still here; please try again.'); }
            elseif ($now-$job['last']>=10) { $job['last']=$now; ($job['emit'])(['type'=>'progress','message'=>$job['message'],'elapsed_seconds'=>(int)($now-$job['started'])]); }
            unset($job);
        }
    }

    private function fail(string $key,string $message): void {
        ($this->jobs[$key]['emit'])(['type'=>'error','status'=>503,'error'=>['code'=>'ai_interrupted','message'=>$message]]);
        $done=$this->jobs[$key]['done']; $this->stop($key); $done(false);
    }

    public function stop(string $key): void {
        if (!isset($this->jobs[$key])) return;
        $job=$this->jobs[$key]; unset($this->jobs[$key]);
        fclose($job['pipe']);
        if (proc_get_status($job['process'])['running']) proc_terminate($job['process']);
        proc_close($job['process']);
    }
}
