<?php
declare(strict_types=1);
const CTR_ROOT = __DIR__;
define('CTR_STORAGE', getenv('CTR_STORAGE') ?: __DIR__.'/storage');
require_once __DIR__.'/vendor/autoload.php';
foreach (['HttpError','Database','HomeAssistantConnections','FirebaseIdentity',
    'ShoppingListChanges','ShoppingListService','ShoppingSyncRequests','ShoppingSocket'] as $name) {
    require_once __DIR__.'/src/'.$name.'.php';
}
