<?php
declare(strict_types=1);
require_once __DIR__.'/RecipeUserContext.php';

/** One-time, offline import. Never called by a request or routine deploy. */
final class RecipeContextMigration {
    public static function import(PDO $pdo,string $directory): array {
        if (!is_dir($directory) || is_link($directory)) throw new RuntimeException('Context source directory unavailable.');
        $accounts=[];
        foreach ($pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $user) $accounts[hash('sha256',(string)$user)]=(string)$user;
        $profiles=[]; $facts=0;
        foreach (glob($directory.'/*.json') as $path) {
            $hash=basename($path,'.json');
            if (is_link($path) || !preg_match('/^[a-f0-9]{64}$/D',$hash) || !isset($accounts[$hash])) throw new RuntimeException('Unmatched or invalid context source; original files retained.');
            $state=json_decode(file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
            RecipeUserContext::validateState($state);
            $profiles[$accounts[$hash]]=$state; $facts+=count($state['facts']);
        }
        $pdo->beginTransaction();
        try {
            foreach ($profiles as $user=>$state) {
                $q=$pdo->prepare('SELECT state_json FROM user_recipe_context WHERE user_id=? FOR UPDATE'); $q->execute([$user]); $existing=$q->fetchColumn();
                if ($existing!==false) {
                    if (json_decode($existing,true,128,JSON_THROW_ON_ERROR)!==$state) throw new RuntimeException('Database context differs from source; refusing to overwrite it.');
                } else {
                    $q=$pdo->prepare('INSERT INTO user_recipe_context(user_id,state_json) VALUES (?,?)');
                    $q->execute([$user,json_encode($state,JSON_THROW_ON_ERROR)]);
                }
                $q=$pdo->prepare('SELECT state_json FROM user_recipe_context WHERE user_id=?'); $q->execute([$user]);
                if (json_decode($q->fetchColumn(),true,128,JSON_THROW_ON_ERROR)!==$state) throw new RuntimeException('Context import verification failed.');
            }
            $pdo->commit();
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
        return ['profiles'=>count($profiles),'facts'=>$facts,'verified'=>true];
    }
}
