<?php
declare(strict_types=1);
function contextTestDatabase(string $path, bool $create=true): PDO {
    $pdo=new class('sqlite:'.$path) extends PDO {
        public function prepare(string $query,array $options=[]): PDOStatement|false {
            return parent::prepare(str_replace(['INSERT IGNORE',' FOR UPDATE'],['INSERT OR IGNORE',''],$query),$options);
        }
    };
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    contextTestLocks($pdo,$path.'-locks');
    if ($create) $pdo->exec('CREATE TABLE IF NOT EXISTS user_recipe_context(user_id TEXT PRIMARY KEY,state_json TEXT NOT NULL)');
    return $pdo;
}
function contextTestLocks(PDO $pdo,string $directory): void {
    if (!is_dir($directory)) mkdir($directory,0700,true);
    $locks=[];
    $pdo->sqliteCreateFunction('GET_LOCK',static function($name,$wait) use (&$locks,$directory) {
        if (isset($locks[$name])) return 1;
        $lock=fopen($directory.'/'.hash('sha256',$name),'c');
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); return 0; }
        $locks[$name]=$lock; return 1;
    });
    $pdo->sqliteCreateFunction('RELEASE_LOCK',static function($name) use (&$locks) {
        if (!isset($locks[$name])) return 0;
        flock($locks[$name],LOCK_UN); fclose($locks[$name]); unset($locks[$name]); return 1;
    });
}
