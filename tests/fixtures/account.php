<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';

// Translate only MySQL syntax used by the real account services for disposable SQLite tests.
final class AccountFixturePDO extends PDO {
    private function sql(string $sql): string {
        $sql=str_replace(' FOR UPDATE','',$sql);
        foreach (['5 MINUTE'=>'-5 minutes','1 HOUR'=>'-1 hour'] as $interval=>$offset) {
            $sql=str_replace('UTC_TIMESTAMP(6) - INTERVAL '.$interval,"datetime('now','$offset')",$sql);
        }
        $sql=str_replace('UTC_TIMESTAMP(6) + INTERVAL 14 DAY',"datetime('now','+14 days')",$sql);
        $sql=str_replace('UTC_TIMESTAMP(6)',"datetime('now')",$sql);
        $sql=str_replace('ON DUPLICATE KEY UPDATE','ON CONFLICT(firebase_uid) DO UPDATE SET',$sql);
        $sql=preg_replace('/IF\(disabled = 0, VALUES\((\w+)\), \1\)/','CASE WHEN disabled=0 THEN excluded.$1 ELSE $1 END',$sql);
        return $sql;
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false { return parent::prepare($this->sql($query),$options); }
    public function exec(string $statement): int|false { return parent::exec($this->sql($statement)); }
}
function accountIdentity(string $token): array {
    if (!in_array($token,['new','guest','unverified','deleted','disabled','child','admin'],true)) throw new HttpError(401,'invalid_token','Invalid token.');
    return ['uid'=>$token,'name'=>ucfirst($token),'email'=>($token==='unverified'?'guest':$token).'@example.test','email_verified'=>$token!=='unverified'];
}
function accountFixture(PDO $pdo,?callable $send=null): AccountService {
    return new AccountService($pdo,Closure::fromCallable('accountIdentity'),static function($uid) {
        if ($uid==='deleted') throw new HttpError(401,'account_deleted','Account deleted.');
    },static fn($email)=>null,$send??static fn()=>null,
    static fn($uid,$email)=>'https://example.test/verify?uid='.$uid,
    $send??static fn()=>null,'https://example.test/app/');
}
function accountSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,firebase_uid TEXT UNIQUE,display_name TEXT,email TEXT,email_verified INTEGER,disabled INTEGER DEFAULT 0,account_role TEXT DEFAULT 'member',child_mode INTEGER DEFAULT 0,child_mode_pin_hash TEXT,child_mode_attempts INTEGER DEFAULT 0,child_mode_blocked_until INTEGER,child_mode_reset_after INTEGER);
    CREATE TABLE households(id INTEGER PRIMARY KEY,name TEXT);
    CREATE TABLE household_memberships(household_id INTEGER,user_id INTEGER,role TEXT);
    CREATE TABLE household_people(id INTEGER PRIMARY KEY,household_id INTEGER,user_id INTEGER,display_name TEXT);
    CREATE TABLE household_invitations(id INTEGER PRIMARY KEY,household_id INTEGER,person_id INTEGER,invited_by_user_id INTEGER,email TEXT,expires_at TEXT,status TEXT DEFAULT 'sending',created_at TEXT DEFAULT CURRENT_TIMESTAMP,sent_at TEXT,responded_at TEXT,responded_by_user_id INTEGER);
    INSERT INTO users(firebase_uid,display_name,disabled) VALUES('disabled','Disabled',1);
    INSERT INTO users(firebase_uid,display_name,account_role) VALUES('admin','Admin','administrator');");
    $pdo->prepare('INSERT INTO users(firebase_uid,display_name,child_mode,child_mode_pin_hash) VALUES(?,?,1,?)')->execute(['child','Child',password_hash('1234',PASSWORD_DEFAULT)]);
}
