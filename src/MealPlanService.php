<?php
declare(strict_types=1);

/** Household calendar and its implicit preparation history share durable entries. */
final class MealPlanService
{
    public function __construct(private PDO $pdo, private HouseholdService $households,
        private string $mediaUrl = 'https://ctr.coffeybean.com/media/recipes',
        private ?Closure $clock = null) {}

    private function rows(string $sql, array $args = []): array {
        $q = $this->pdo->prepare($sql); $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function state(string $household): array {
        return $this->rows('SELECT revision,time_zone FROM meal_plans WHERE household_id=?', [$household])[0]
            ?? ['revision'=>0, 'time_zone'=>'America/New_York'];
    }

    private function today(array $state): string {
        $now = $this->clock ? ($this->clock)() : new DateTimeImmutable('now');
        return $now->setTimezone(new DateTimeZone($state['time_zone']))->format('Y-m-d');
    }

    public static function date(mixed $date, bool $nullable = false): ?string {
        if ($nullable && $date === null) return null;
        if (!is_string($date) || !preg_match('/^(20|21)\d{2}-\d{2}-\d{2}$/D', $date)
            || !($parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date)) || $parsed->format('Y-m-d') !== $date) {
            throw new HttpError(422, 'invalid_date', 'Choose a valid date.');
        }
        return $date;
    }

    private function range(string $from, string $to, int $maximum = 366): void {
        self::date($from); self::date($to);
        if ($to < $from || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > $maximum)
            throw new HttpError(422, 'invalid_range', 'Choose up to one year of meals.');
    }

    public function read(string $user, string $household, string $from, string $to): array {
        $this->households->membership($user, $household); $this->range($from,$to,73049);
        $state = $this->state($household);
        // Do not expose current recipe content after household access is removed.
        $entries = $this->rows('SELECT e.*,r.title AS current_title,
            CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END AS available,
            (SELECT source_url FROM recipe_photos p WHERE p.recipe_id=r.id ORDER BY p.is_cover DESC,p.position,p.id LIMIT 1) AS photo_url,
            (SELECT storage_key FROM recipe_photos p WHERE p.recipe_id=r.id ORDER BY p.is_cover DESC,p.position,p.id LIMIT 1) AS photo_storage_key
            FROM meal_plan_entries e LEFT JOIN recipe_households h ON h.recipe_id=e.recipe_id AND h.household_id=e.household_id AND h.removed_at IS NULL
            LEFT JOIN recipes r ON r.id=h.recipe_id AND r.deleted_at IS NULL
            WHERE e.household_id=? AND (e.plan_date IS NULL OR e.plan_date BETWEEN ? AND ?)
            ORDER BY e.plan_date,e.created_at,e.id', [$household,$from,$to]);
        foreach ($entries as &$entry) {
            $entry['available'] = (bool)$entry['available'];
            if ($entry['current_title'] !== null && ($entry['plan_date'] === null || $entry['plan_date'] >= $this->today($state))) $entry['title']=$entry['current_title'];
            if (preg_match('#^(?:media|vps)/recipes/((?:[a-f0-9]{48}|[a-f0-9]{64})\.(?:jpg|png|webp))$#', (string)($entry['photo_storage_key'] ?? ''), $match)) $entry['photo_url']=rtrim($this->mediaUrl,'/').'/'.$match[1];
            foreach (['household_id','current_title','photo_storage_key','created_at'] as $key) unset($entry[$key]);
            foreach (['recipe_id','recipe_identity'] as $key) if ($entry[$key] !== null) $entry[$key]=(string)$entry[$key];
        } unset($entry);
        return ['revision'=>(int)$state['revision'], 'time_zone'=>$state['time_zone'], 'today'=>$this->today($state), 'entries'=>$entries];
    }

    /** One operation per revision: prevents one member overwriting another's plan. */
    public function change(string $user, string $household, array $body): array {
        $this->households->membership($user,$household);
        $revision=$body['revision'] ?? null; $operation=$body['operation'] ?? null;
        if (!is_int($revision) || $revision < 0 || !in_array($operation,['add','move','remove'],true)) throw new HttpError(422,'invalid_plan','Invalid meal plan change.');
        $id=$body['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$id)) throw new HttpError(422,'invalid_entry','Invalid meal entry.');
        $this->pdo->beginTransaction();
        try {
            // Atomic compare-and-swap also serializes first-time household writes.
            $insert=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
            $this->pdo->prepare("$insert INTO meal_plans (household_id,revision,time_zone) VALUES (?,0,'America/New_York')")->execute([$household]);
            $q=$this->pdo->prepare('UPDATE meal_plans SET revision=revision+1 WHERE household_id=? AND revision=?');$q->execute([$household,$revision]);
            if ($q->rowCount() !== 1) throw new HttpError(409,'plan_conflict','The meal plan changed. Review the updated plan and try again.');
            $today=$this->today($this->state($household));
            $existing=$this->rows('SELECT * FROM meal_plan_entries WHERE household_id=? AND id=?',[$household,$id])[0] ?? null;
            $date=$operation==='remove' ? null : self::date($body['date'] ?? null);
            if ($operation!=='add' && !$existing) throw new HttpError(404,'meal_not_found','Meal no longer on the plan.');
            if ($operation==='add' && $existing) throw new HttpError(409,'entry_exists','This meal was already added. Refresh the plan.');
            if ((($date !== null && $date < $today) || ($existing && $existing['plan_date'] !== null && $existing['plan_date'] < $today)) && ($body['confirm_past'] ?? false) !== true)
                throw new HttpError(409,'past_confirmation_required','Change this past meal? This will update your cooking history.');
            if ($operation==='add') {
                $kind=$body['kind'] ?? 'recipe';$recipe=null;
                if (!in_array($kind,['recipe','leftovers','restaurant'],true)) throw new HttpError(422,'invalid_kind','Choose a recipe or placeholder.');
                if ($kind==='recipe') $recipe=$this->recipe($household,$body['recipe_id'] ?? null);
                $restaurant=null;
                if ($kind==='restaurant' && ($body['restaurant_id']??null)!==null) {
                    foreach ((new RestaurantService($this->pdo,$this->households))->read($user,$household)['items'] as $item) if ($item['id']===$body['restaurant_id']) $restaurant=$item;
                    if ($restaurant===null) throw new HttpError(422,'restaurant_not_found','Choose an available household restaurant.');
                }
                $title=$recipe['title'] ?? ($kind==='leftovers' ? 'Leftovers' : ($restaurant['name']??'Restaurant'));
                $this->pdo->prepare('INSERT INTO meal_plan_entries (household_id,id,plan_date,kind,recipe_id,recipe_identity,title,recipe_revision,restaurant_id,restaurant_logo_reference) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$household,$id,$date,$kind,$recipe['id'] ?? null,$recipe['id'] ?? null,$title,$recipe['revision'] ?? null,$restaurant['id']??null,$restaurant['logo_reference']??null]);
            } elseif ($operation==='move') {
                $this->pdo->prepare('UPDATE meal_plan_entries SET plan_date=? WHERE household_id=? AND id=?')->execute([$date,$household,$id]);
            } else $this->pdo->prepare('DELETE FROM meal_plan_entries WHERE household_id=? AND id=?')->execute([$household,$id]);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        return ['revision'=>$revision+1,'today'=>$today];
    }

    private function recipe(string $household, mixed $id): array {
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,18}$/D',$id)) throw new HttpError(422,'invalid_recipe','Choose a recipe.');
        $recipe=$this->rows('SELECT r.id,r.title,r.revision FROM recipes r JOIN recipe_households h ON h.recipe_id=r.id WHERE h.household_id=? AND r.id=? AND h.removed_at IS NULL AND r.deleted_at IS NULL',[$household,$id])[0] ?? null;
        if (!$recipe) throw new HttpError(404,'recipe_not_found','Recipe not in this household.');
        return $recipe;
    }

    public function pairings(string $user,string $household,string $id): array {
        $this->households->membership($user,$household);$this->recipe($household,$id);
        $rows=$this->rows('SELECT DISTINCT r.id,r.title,
            (SELECT source_url FROM recipe_photos ph WHERE ph.recipe_id=r.id ORDER BY ph.is_cover DESC,ph.position,ph.id LIMIT 1) AS photo_url,
            (SELECT storage_key FROM recipe_photos ph WHERE ph.recipe_id=r.id ORDER BY ph.is_cover DESC,ph.position,ph.id LIMIT 1) AS photo_storage_key
            FROM recipe_pairings p JOIN recipes r ON r.id=CASE WHEN p.recipe_id=? THEN p.paired_recipe_id ELSE p.recipe_id END
            JOIN recipe_households h ON h.recipe_id=r.id AND h.household_id=? AND h.removed_at IS NULL
            WHERE (p.recipe_id=? OR p.paired_recipe_id=?) AND r.deleted_at IS NULL ORDER BY r.title,r.id',[$id,$household,$id,$id]);
        foreach ($rows as &$row) {
            $row['id']=(string)$row['id'];
            if (preg_match('#^(?:media|vps)/recipes/((?:[a-f0-9]{48}|[a-f0-9]{64})\.(?:jpg|png|webp))$#',(string)($row['photo_storage_key'] ?? ''),$match)) $row['photo_url']=rtrim($this->mediaUrl,'/').'/'.$match[1];
            unset($row['photo_storage_key']);
        }
        return ['recipes'=>$rows];
    }

    public function ingredients(string $user,string $household,string $from,string $to): array {
        $this->households->membership($user,$household);$this->range($from,$to);
        if ($from < $this->today($this->state($household))) throw new HttpError(422,'past_ingredients','Choose today or upcoming dates.');
        $missing=$this->rows("SELECT e.id FROM meal_plan_entries e LEFT JOIN recipe_households h ON h.recipe_id=e.recipe_id AND h.household_id=e.household_id AND h.removed_at IS NULL LEFT JOIN recipes r ON r.id=h.recipe_id AND r.deleted_at IS NULL WHERE e.household_id=? AND e.plan_date BETWEEN ? AND ? AND e.kind='recipe' AND r.id IS NULL",[$household,$from,$to]);
        if ($missing) throw new HttpError(409,'unavailable_meal','A planned recipe is unavailable. Update the plan before collecting ingredients.');
        $rows=$this->rows("SELECT i.*,e.id AS meal_entry_id,e.plan_date,r.title AS recipe_title FROM meal_plan_entries e JOIN recipes r ON r.id=e.recipe_id AND r.deleted_at IS NULL JOIN recipe_households h ON h.recipe_id=r.id AND h.household_id=e.household_id AND h.removed_at IS NULL JOIN recipe_ingredients i ON i.recipe_id=r.id WHERE e.household_id=? AND e.kind='recipe' AND e.plan_date BETWEEN ? AND ? ORDER BY e.plan_date,e.id,i.position,i.id",[$household,$from,$to]);
        foreach ($rows as &$row) unset($row['household_id']);
        $blank=$this->rows("SELECT DISTINCT r.id,r.title FROM meal_plan_entries e JOIN recipes r ON r.id=e.recipe_id AND r.deleted_at IS NULL WHERE e.household_id=? AND e.kind='recipe' AND e.plan_date BETWEEN ? AND ? AND r.source_type='blank' ORDER BY r.title,r.id",[$household,$from,$to]);
        return ['ingredients'=>$rows,'blank_recipes'=>$blank];
    }

    public function history(string $user,string $household,string $from,string $to): array {
        $this->households->membership($user,$household);$this->range($from,$to,73049);
        $state=$this->state($household);$today=$this->today($state);
        $zone=new DateTimeZone($state['time_zone']);$utc=new DateTimeZone('UTC');
        $start=(new DateTimeImmutable($from,$zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $end=(new DateTimeImmutable($to,$zone))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $planned=$this->rows("SELECT id,plan_date AS cooked_date,recipe_identity AS recipe_id,title AS recipe_title,recipe_revision FROM meal_plan_entries WHERE household_id=? AND kind='recipe' AND plan_date BETWEEN ? AND ? AND plan_date<? ORDER BY plan_date,id",[$household,$from,$to,$today]);
        // Existing explicitly recorded events remain intact and visible too.
        $legacy=$this->rows('SELECT e.id,e.cooked_at,c.recipe_id,r.title AS recipe_title,c.recipe_revision FROM cooking_events e JOIN cooking_event_recipes c ON c.event_id=e.id AND c.household_id=e.household_id JOIN recipes r ON r.id=c.recipe_id WHERE e.household_id=? AND e.cooked_at>=? AND e.cooked_at<?',[$household,$start,$end]);
        $deleted=$this->rows('SELECT e.id,e.cooked_at,c.recipe_id,c.recipe_title,c.recipe_revision FROM cooking_events e JOIN deleted_recipe_cooking_history c ON c.event_id=e.id AND c.household_id=e.household_id WHERE e.household_id=? AND e.cooked_at>=? AND e.cooked_at<?',[$household,$start,$end]);
        return ['planned_history'=>$planned,'recorded_history'=>array_merge($legacy,$deleted),'today'=>$today];
    }
}
