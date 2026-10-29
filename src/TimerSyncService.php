<?php
declare(strict_types=1);

/** Account-private timer state. A push is a hint; GET is the recovery path. */
final class TimerSyncService
{
    public function __construct(private PDO $pdo, private $send = null) {}

    public static function identifier(mixed $value, bool $timer = false): string
    {
        $pattern = $timer ? '/^[A-Za-z0-9:_-]{1,160}$/D' : '/^[a-f0-9]{32}$/D';
        if (!is_string($value) || !preg_match($pattern, $value)) {
            throw new HttpError(422, 'invalid_timer_id', 'Invalid timer or device identifier.');
        }
        return $value;
    }

    public function device(string $user, array $body): array
    {
        $id = self::identifier($body['installation_id'] ?? null);
        $platform = $body['platform'] ?? null;
        $receive = $body['receive_timers'] ?? null;
        $token = $body['push_token'] ?? null;
        if (!in_array($platform, ['android', 'ios'], true) || !is_bool($receive)
            || ($token !== null && (!is_string($token) || strlen($token) > 4096 || strlen($token) < 20))
            || ($receive && $token === null)) {
            throw new HttpError(422, 'invalid_timer_device', 'Choose a supported device and allow notifications.');
        }
        // Installation IDs are random per installation. Account switches deliberately
        // rebind this installation, replacing its token and opt-in atomically.
        $this->pdo->prepare('INSERT INTO timer_devices (installation_id,user_id,platform,push_token,receive_timers,updated_at)
            VALUES (?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),platform=VALUES(platform),
            push_token=VALUES(push_token),receive_timers=VALUES(receive_timers),updated_at=CURRENT_TIMESTAMP')
            ->execute([$id,$user,$platform,$token,(int)$receive]);
        // A refreshed token must not remain associated with another installation/account.
        if ($token !== null) $this->pdo->prepare('UPDATE timer_devices SET push_token=NULL,receive_timers=0 WHERE push_token=? AND installation_id<>?')->execute([$token,$id]);
        return ['receive_timers'=>$receive];
    }

    public function unregister(string $user, array $body): array
    {
        $id = self::identifier($body['installation_id'] ?? null);
        $this->pdo->prepare('DELETE FROM timer_devices WHERE user_id=? AND installation_id=?')->execute([$user,$id]);
        return ['removed'=>true];
    }

    public function list(string $user): array
    {
        // Cancellations remain as tombstones so a missed cancellation is reconciled.
        // Retain recent cancellation tombstones even after the deadline so a
        // missed stop can silence an already elapsed/ringing local alarm.
        $q = $this->pdo->prepare('SELECT timer_id,source_device,title,deadline_ms,cancelled,revision FROM shared_timers WHERE user_id=? AND (deadline_ms>? OR (cancelled=1 AND updated_at>DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY))) ORDER BY timer_id');
        $q->execute([$user,(int)floor(microtime(true)*1000)]);
        return ['timers'=>array_map([self::class,'row'],$q->fetchAll(PDO::FETCH_ASSOC))];
    }

    private static function row(array $row): array
    {
        foreach (['deadline_ms','revision'] as $key) $row[$key]=(int)$row[$key];
        $row['cancelled']=(bool)$row['cancelled'];
        return $row;
    }

    public function change(string $user, string $uid, array $body): array
    {
        $id=self::identifier($body['timer_id']??null,true);
        $device=self::identifier($body['installation_id']??null);
        $operation=self::identifier($body['operation_id']??null);
        $cancel=$body['cancelled']??false;
        $expected=$body['revision']??0;
        if (!is_bool($cancel) || !is_int($expected) || $expected<0) throw new HttpError(422,'invalid_timer','Invalid timer state.');
        $now=(int)floor(microtime(true)*1000);
        if (!$cancel && (!is_int($body['deadline_ms']??null) || $body['deadline_ms']<=$now || $body['deadline_ms']>$now+7*86400000
            || !is_string($body['title']??null) || trim($body['title'])==='' || mb_strlen($body['title'])>300)) {
            throw new HttpError(422,'invalid_timer','Choose a timer between now and seven days from now.');
        }
        $this->pdo->beginTransaction();
        try {
            // Serialize requests from this installation, including first-create retries.
            $q=$this->pdo->prepare('SELECT installation_id FROM timer_devices WHERE user_id=? AND installation_id=? FOR UPDATE');
            $q->execute([$user,$device]);
            if (!$q->fetchColumn()) throw new HttpError(409,'timer_device_missing','Register this device again.');
            $q=$this->pdo->prepare('SELECT * FROM shared_timers WHERE user_id=? AND timer_id=? FOR UPDATE');
            $q->execute([$user,$id]); $old=$q->fetch(PDO::FETCH_ASSOC);
            if ($old && $old['last_operation']===$operation) {
                $revision=(int)$old['revision']; $title=$old['title']; $deadline=(int)$old['deadline_ms'];
                $source=$old['source_device']; $cancel=(bool)$old['cancelled'];
            } else {
            if (($old ? (int)$old['revision'] : 0)!==$expected) throw new HttpError(409,'timer_conflict','This timer changed on another device. Reopen the timers and try again.');
            if ($cancel && !$old) throw new HttpError(404,'timer_missing','Timer not found.');
            $revision=$expected+1;
            $title=$cancel ? $old['title'] : trim($body['title']);
            $deadline=$cancel ? (int)$old['deadline_ms'] : $body['deadline_ms'];
            $source=$old ? $old['source_device'] : $device;
            if ($old) {
                $this->pdo->prepare('UPDATE shared_timers SET title=?,deadline_ms=?,cancelled=?,revision=?,last_operation=?,updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND timer_id=?')
                    ->execute([$title,$deadline,(int)$cancel,$revision,$operation,$user,$id]);
            } else {
                // New timers are namespaced by their origin installation.
                if (!str_starts_with($id,$device.':')) throw new HttpError(422,'invalid_timer_id','Invalid timer origin.');
                $this->pdo->prepare('INSERT INTO shared_timers (user_id,timer_id,source_device,title,deadline_ms,cancelled,revision,last_operation,updated_at) VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)')
                    ->execute([$user,$id,$source,$title,$deadline,(int)$cancel,$revision,$operation]);
            }
            }
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        $timer=['timer_id'=>$id,'source_device'=>$source,'title'=>$title,'deadline_ms'=>$deadline,'cancelled'=>$cancel,'revision'=>$revision];
        $q=$this->pdo->prepare('SELECT installation_id,push_token,platform FROM timer_devices WHERE user_id=? AND installation_id<>? AND (receive_timers=1 OR installation_id=?) AND push_token IS NOT NULL AND updated_at>DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 60 DAY) LIMIT 20');
        $q->execute([$user,$device,$source]); $failed=false; $started=microtime(true);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $target) {
            if (microtime(true)-$started>8) { $failed=true; continue; }
            try {
                if ($this->send===null) throw new RuntimeException('Push unavailable');
                $valid=($this->send)($target, $timer+['account_uid'=>$uid,'target_device'=>$target['installation_id']]);
                if ($valid===false) $this->pdo->prepare('UPDATE timer_devices SET push_token=NULL,receive_timers=0 WHERE installation_id=? AND push_token=?')->execute([$target['installation_id'],$target['push_token']]);
            } catch (Throwable $error) { $failed=true; }
        }
        // Never roll back a saved timer or claim remote scheduling on push acceptance.
        return ['timer'=>$timer,'delivery'=>$failed?'pending':'sent'];
    }
}
