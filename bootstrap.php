<?php
declare(strict_types=1);
const CTR_ROOT = __DIR__;
define('CTR_STORAGE', getenv('CTR_STORAGE') ?: __DIR__.'/storage');
require_once __DIR__.'/vendor/autoload.php';
foreach (['HttpError','Database','HomeAssistantConnections','FirebaseIdentity',
    'WorkCalendar','HouseholdService','HouseholdSnapshot','RecipeService','RecipeMembership','RecipeDeletionService','RecipeSaveReceipts','RecipeEditorService','MealPlanService','RestaurantService','TimerSyncService','CookingSessions','TimerPush','ChildMode',
    'TimerRanges','RecipeImportTools','RecipeImportService','RecipeVariationService','RecipeChatService','RecipePhotoService','BlankRecipeCategoryService','CategoryImageService','AdministrationService','CookingPlans','AiService','AiJobs','Runtime',
    'FirebaseAccountDirectory','InvitationMail','VerificationMail','VerificationService','InvitationService','AccountService',
    'RecipeShare','RecipeSharePage','RecipeImageOptimizer','HttpService','DataSocket','ShoppingListService','ShoppingSyncRequests','ShoppingPhotoService','ShoppingSocket'] as $name) {
    require_once __DIR__.'/src/'.$name.'.php';
}
