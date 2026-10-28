<?php
declare(strict_types=1);
require_once __DIR__.'/ShoppingListChanges.php';

final class ShoppingListService
{
    public const DEPARTMENTS = ['', 'Other', 'Produce', 'Dairy & eggs', 'Meat & seafood', 'Bakery', 'Pantry', 'Frozen', 'Deli', 'Drinks', 'Household'];
    public function __construct(private PDO $pdo, private Closure $authorize) {}

    public static function validate(array $body, ?array $departments = null): array
    {
        if (!is_int($body['revision'] ?? null) || $body['revision'] < 0
            || !is_array($body['items'] ?? null) || array_values($body['items']) !== $body['items'] || count($body['items']) > 500) {
            throw new HttpError(422, 'invalid_list', 'Choose up to 500 shopping items.');
        }
        $items = []; $ids = [];
        foreach ($body['items'] as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)
                || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $item['id']) || isset($ids[$item['id']])
                || !is_string($item['name'] ?? null) || trim($item['name']) === '' || mb_strlen($item['name']) > 200
                || !is_bool($item['checked'] ?? null) || !in_array($item['department'] ?? null, $departments === null ? self::DEPARTMENTS : array_merge([''], $departments), true)) {
                throw new HttpError(422, 'invalid_item', 'Enter an item of 1 to 200 characters with a valid department.');
            }
            if (array_key_exists('note', $item) && (!is_string($item['note']) || mb_strlen($item['note']) > 500)) {
                throw new HttpError(422, 'invalid_note', 'Use a note of up to 500 characters.');
            }
            $ids[$item['id']] = true;
            $items[] = ['id'=>$item['id'], 'name'=>trim($item['name']), 'checked'=>$item['checked'], 'department'=>$item['department']] + (array_key_exists('note', $item) ? ['note'=>trim($item['note'])] : []);
        }
        return $items;
    }

    public function read(string $user, string $household): array
    {
        $state = $this->readState($user, $household);
        unset($state['associations'], $state['sync']);
        return $state;
    }

    private function readState(string $user, string $household): array
    {
        ($this->authorize)($user, $household);
        $q = $this->pdo->prepare('SELECT revision, items FROM shopping_lists WHERE household_id=?');
        $q->execute([$household]); $row = $q->fetch(PDO::FETCH_ASSOC);
        $stored = $row ? json_decode($row['items'], true, 16, JSON_THROW_ON_ERROR) : [];
        // Older rows contain the item array directly; the envelope adds household
        // department settings without a schema migration or changing old clients.
        return ['revision'=>$row ? (int)$row['revision'] : 0,
            'items'=>$stored['items'] ?? $stored,
            'departments'=>$stored['departments'] ?? array_merge(array_slice(self::DEPARTMENTS, 2), ['Other']),
            'associations'=>$stored['associations'] ?? [], 'sync'=>$stored['sync'] ?? []];
    }

    private static function itemKey(string $name): string
    {
        $name = strtr(mb_strtolower($name), [
            'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','æ'=>'ae',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','œ'=>'oe',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c','ß'=>'ss',
        ]);
        $key = preg_replace('/[^\p{L}\p{N}]+/u', '', $name);
        // Prefix keeps number-only names as string keys in JSON and PHP arrays.
        return $key === '' ? '' : 'n:'.$key;
    }

    private static function matchingKeys(array $associations, string $key): array
    {
        if ($key === '') return [];
        if (array_key_exists($key, $associations)) return [$key];
        $name = substr($key, 2);
        $best = 3; $matches = [];
        foreach ($associations as $candidateKey => $_) {
            $candidate = substr($candidateKey, 2);
            // Spacing/case normalization works for all scripts. Typo matching is
            // limited to Latin names and never confuses different numeric sizes.
            if (!preg_match('/^[a-z0-9]+$/', $name) || !preg_match('/^[a-z0-9]+$/', $candidate)
                || preg_replace('/[^0-9]/', '', $name) !== preg_replace('/[^0-9]/', '', $candidate)) continue;
            $length = min(strlen($name), strlen($candidate));
            $limit = $length >= 10 ? 2 : ($length >= 5 || ($length >= 4 && strlen($name) !== strlen($candidate)) ? 1 : 0);
            if ($length < 4 || abs(strlen($name) - strlen($candidate)) > 3) continue;
            $distance = levenshtein($name, $candidate);
            // Common singular/plural changes should survive longer suffixes too.
            if ($name.'s' === $candidate || $candidate.'s' === $name
                || $name.'es' === $candidate || $candidate.'es' === $name
                || (str_ends_with($name, 'ies') && substr($name, 0, -3).'y' === $candidate)
                || (str_ends_with($candidate, 'ies') && substr($candidate, 0, -3).'y' === $name)) $distance = 1;
            if ($distance === 2 && strlen($name) === strlen($candidate)) {
                // A single adjacent transposition is a common one-character typo.
                for ($i = 0; $i < strlen($name) - 1; $i++) {
                    $swapped = $name;
                    [$swapped[$i], $swapped[$i + 1]] = [$swapped[$i + 1], $swapped[$i]];
                    if ($swapped === $candidate) { $distance = 1; $limit = max(1, $limit); break; }
                }
            }
            if ($distance > $limit || $distance > $best) continue;
            if ($distance < $best) { $best = $distance; $matches = []; }
            $matches[] = $candidateKey;
        }
        return $matches;
    }

    private static function remember(array &$associations, string $name, string $department): void
    {
        $key = self::itemKey($name);
        if ($key === '') return;
        // A correction to a spelling variant also updates its closest known names.
        unset($associations[$key]);
        foreach (self::matchingKeys($associations, $key) as $match) {
            unset($associations[$match]);
            $associations[$match] = $department;
        }
        $associations[$key] = $department;
    }

    public function save(string $user, string $household, array $body): array
    {
        $this->pdo->beginTransaction();
        try {
            // Serialize household edits, including the first list creation.
            $q = $this->pdo->prepare('SELECT id FROM households WHERE id=? FOR UPDATE');
            $q->execute([$household]);
            $old = $this->readState($user, $household);
            $changeId=$body['client_change_id'] ?? null;
            $changedAt=$body['edited_at_ms'] ?? null;
            $modern=$changeId!==null;
            $hash=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR));
            if ($modern) {
                if (!is_string($changeId) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$changeId)
                    || !is_int($changedAt) || $changedAt<946684800000 || $changedAt>(int)(microtime(true)*1000)+300000
                    || !is_array($body['base_items'] ?? null)) {
                    throw new HttpError(422,'invalid_change','The saved shopping change is invalid. Check the device clock and retry.');
                }
                if (isset($old['sync']['receipts'][$changeId])) {
                    if ($old['sync']['receipts'][$changeId]!==$hash) throw new HttpError(409,'change_id_reused','This change identifier was already used.');
                    $this->pdo->commit();
                    return ['revision'=>$old['revision'],'items'=>$old['items'],'departments'=>$old['departments'],'acknowledged_change_id'=>$changeId];
                }
            } elseif ($old['revision'] !== ($body['revision'] ?? null)) {
                // Older apps have no change ID. An exact readback still proves
                // the desired state was saved after its response was lost.
                if (is_int($body['revision'] ?? null) && $body['revision']<$old['revision']
                    && ($body['departments'] ?? $old['departments'])===$old['departments']
                    && empty($body['department_renames'])) {
                    $retry=self::validate($body,$old['departments']);
                    foreach ($retry as $i=>&$item) {
                        if (!array_key_exists('note',$item) && isset($old['items'][$i]['note'])
                            && $old['items'][$i]['id']===$item['id']) $item['note']=$old['items'][$i]['note'];
                    } unset($item);
                    if ($retry==$old['items']) {
                        $this->pdo->commit();
                        return ['revision'=>$old['revision'],'items'=>$old['items'],'departments'=>$old['departments']];
                    }
                }
                throw new HttpError(409, 'shopping_conflict', 'The shopping list changed. Review the updated list and try again.');
            }
            if ($modern && isset($body['departments']) && isset($body['base_departments'])
                && $body['base_departments'] !== $old['departments'] && $body['departments'] !== $old['departments']) {
                throw new HttpError(409, 'department_conflict', 'Departments changed on another device. Review before retrying.');
            }
            $departments = array_key_exists('departments', $body) ? $body['departments'] : $old['departments'];
            if (!is_array($departments) || array_values($departments) !== $departments || count($departments) > 50) {
                throw new HttpError(422, 'invalid_departments', 'Choose up to 50 departments.');
            }
            $names = [];
            foreach ($departments as $department) {
                if (!is_string($department) || trim($department) !== $department || $department === '' || mb_strlen($department) > 60
                    || in_array(mb_strtolower($department), $names, true)) {
                    throw new HttpError(422, 'invalid_departments', 'Use unique department names of 1 to 60 characters.');
                }
                $names[] = mb_strtolower($department);
            }
            $items = self::validate($body, $departments);
            $base=$old['items'];
            if ($modern) {
                $baseDepartments=$departments;
                foreach ($body['base_items'] as $item) {
                    if (is_array($item) && is_string($item['department'] ?? null)) $baseDepartments[]=$item['department'];
                }
                $base=self::validate(['revision'=>0,'items'=>$body['base_items']],array_unique($baseDepartments));
            }
            [$items,$sync]=ShoppingListChanges::merge($old['items'],$base,$items,$old['sync'],
                [$modern?$changedAt:(int)(microtime(true)*1000),$modern?$changeId:'legacy-'.sprintf('%020d',$old['revision']+1)]);
            // Validate the merged list too: an old operation cannot reintroduce a removed department.
            $items=self::validate(['revision'=>0,'items'=>$items],$departments);
            if ($modern) $sync['receipts'][$changeId]=$hash;
            $renames = $body['department_renames'] ?? [];
            if (!is_array($renames)) throw new HttpError(422, 'invalid_departments', 'Choose a valid department rename.');
            foreach ($renames as $from => $to) {
                if (!in_array((string)$from, $old['departments'], true) || in_array((string)$from, $departments, true)
                    || !is_string($to) || !in_array($to, $departments, true)) {
                    throw new HttpError(422, 'invalid_departments', 'Choose a valid department rename.');
                }
            }
            $associations = [];
            foreach ($old['associations'] as $key => $department) {
                $department = $renames[$department] ?? $department;
                if ($department === '' || in_array($department, $departments, true)) $associations[$key] = $department;
            }
            $oldItems = [];
            foreach ($old['items'] as $item) {
                $oldItems[$item['id']] = $item;
                // Learn existing assignments when upgrading an older household list.
                $department = $renames[$item['department']] ?? $item['department'];
                $key = self::itemKey($item['name']);
                if ($key !== '' && $department !== '' && in_array($department, $departments, true)
                    && !array_key_exists($key, $associations)) $associations[$key] = $department;
            }
            foreach ($items as $item) {
                $previous = $oldItems[$item['id']] ?? null;
                if (($previous !== null && ($previous['department'] !== $item['department'] || $previous['name'] !== $item['name']))
                    || ($previous === null && $item['department'] !== '')) {
                    self::remember($associations, $item['name'], $item['department']);
                }
            }
            foreach ($items as &$item) {
                // Missing means an older client: only an explicit empty note clears it.
                if (!array_key_exists('note', $item) && isset($oldItems[$item['id']]['note'])) {
                    $item['note'] = $oldItems[$item['id']]['note'];
                }
                if (!isset($oldItems[$item['id']]) && $item['department'] === '') {
                    $matches = self::matchingKeys($associations, self::itemKey($item['name']));
                    $choices = array_unique(array_map(fn($key) => $associations[$key], $matches));
                    if (count($choices) === 1) $item['department'] = reset($choices);
                }
            }
            unset($item);
            // Bound household metadata while retaining the most recently taught names.
            $associations = array_slice($associations, -1000, null, true);
            $revision = $old['revision'] + 1;
            $json = json_encode(['items'=>$items, 'departments'=>$departments, 'associations'=>$associations, 'sync'=>$sync], JSON_THROW_ON_ERROR);
            if ($old['revision'] === 0) {
                $this->pdo->prepare('INSERT INTO shopping_lists (household_id,revision,items) VALUES (?,?,?)')->execute([$household,$revision,$json]);
            } else {
                $this->pdo->prepare('UPDATE shopping_lists SET revision=?,items=? WHERE household_id=?')->execute([$revision,$json,$household]);
            }
            $this->pdo->commit();
            return ['revision'=>$revision, 'items'=>$items, 'departments'=>$departments]
                + ($modern?['acknowledged_change_id'=>$changeId]:[]);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
