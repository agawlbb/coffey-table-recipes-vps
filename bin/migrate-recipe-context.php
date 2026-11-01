<?php
declare(strict_types=1);
// Explicit maintenance: stop the old backend and its AI workers before running.
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/src/RecipeContextMigration.php';
try {
    if ($argc!==2) throw new RuntimeException('Supply the existing preference-file directory.');
    $pdo=runtimeDatabase();
    echo json_encode(RecipeContextMigration::import($pdo,$argv[1]),JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    // Provider output, preference contents and credentials never belong in logs.
    fwrite(STDERR,"Context migration failed; original files retained. Review source validation and database access.\n");
    exit(1);
}
