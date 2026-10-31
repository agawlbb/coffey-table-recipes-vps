<?php
declare(strict_types=1);

final class AdministrationService
{
    public function __construct(private PDO $pdo, private $deleteFirebase, private $listFirebase = null, private ?CategoryImageService $categoryImages = null, private string $mediaUrl = 'https://ctr.coffeybean.com/media/recipes') {}

    public function requireAdministrator(string $actorId): void
    {
        $query = $this->pdo->prepare('SELECT account_role, disabled FROM users WHERE id = ?');
        $query->execute([$actorId]);
        $actor = $query->fetch(PDO::FETCH_ASSOC);
        if (!$actor || $actor['disabled'] || $actor['account_role'] !== 'administrator') {
            throw new HttpError(403, 'administrator_required', 'Administrator access is required.');
        }
    }

    private function authorize(string $actorId): void { $this->requireAdministrator($actorId); }

    public function users(string $actorId): array
    {
        $this->authorize($actorId);
        $rows = $this->pdo->query('SELECT id, firebase_uid, display_name, email, email_verified, account_role, disabled FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_map(static function (array $row): array {
            $row['id'] = (string) $row['id'];
            $row['disabled'] = (bool) $row['disabled'];
            $row['email_verified'] = (bool) $row['email_verified'];
            return $row;
        }, $rows);
        if ($this->listFirebase !== null) {
            $known = array_column($rows, null, 'firebase_uid');
            foreach (($this->listFirebase)() as $account) {
                if (isset($known[$account['uid']])) continue;
                $rows[] = ['id' => 'firebase:' . $account['uid'], 'firebase_uid' => $account['uid'], 'display_name' => $account['display_name'], 'email' => $account['email'], 'email_verified' => $account['email_verified'], 'disabled' => $account['disabled'], 'account_role' => 'user', 'firebase_only' => true];
            }
        }
        return $rows;
    }

    public function households(string $actorId): array
    {
        $this->authorize($actorId);
        $rows = $this->pdo->query('SELECT h.id, h.name, h.ai_images_enabled, (SELECT COUNT(*) FROM household_memberships m WHERE m.household_id = h.id) AS member_count FROM households h ORDER BY h.name, h.id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (string) $row['id'];
            $row['ai_images_enabled'] = (bool) $row['ai_images_enabled'];
            $row['member_count'] = (int) $row['member_count'];
        }
        return $rows;
    }

    public function setAiImages(string $actorId, string $householdId, mixed $enabled): array
    {
        $this->authorize($actorId);
        if (!is_bool($enabled)) throw new HttpError(422, 'invalid_permission', 'Choose whether AI image generation is enabled.');
        $query = $this->pdo->prepare('SELECT id FROM households WHERE id = ?');
        $query->execute([$householdId]);
        if ($query->fetchColumn() === false) throw new HttpError(404, 'household_not_found', 'Household not found.');
        $this->pdo->prepare('UPDATE households SET ai_images_enabled = ? WHERE id = ?')->execute([$enabled ? 1 : 0, $householdId]);
        return ['id' => $householdId, 'ai_images_enabled' => $enabled];
    }

    public function people(string $actorId): array
    {
        $this->authorize($actorId);
        $rows = $this->pdo->query('SELECT p.id, p.display_name, p.household_id, h.name AS household_name FROM household_people p JOIN households h ON h.id = p.household_id WHERE p.user_id IS NULL ORDER BY h.name, p.id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (string) $row['id'];
            $row['household_id'] = (string) $row['household_id'];
        }
        return $rows;
    }

    public function deletePerson(string $actorId, string $personId, bool $confirmed): void
    {
        $this->authorize($actorId);
        if (!$confirmed) throw new HttpError(422, 'confirmation_required', 'Confirm permanent deletion first.');
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $q->execute([$actorId]);
            $this->authorize($actorId);
            $q = $this->pdo->prepare('SELECT user_id FROM household_people WHERE id = ? FOR UPDATE');
            $q->execute([$personId]);
            $person = $q->fetch(PDO::FETCH_ASSOC);
            if (!$person) throw new HttpError(404, 'person_not_found', 'Person not found.');
            if ($person['user_id'] !== null) throw new HttpError(409, 'person_linked', 'This person now has a linked account. Refresh and delete that account instead.');
            $this->pdo->prepare('DELETE FROM household_invitations WHERE person_id = ?')->execute([$personId]);
            $this->pdo->prepare('DELETE FROM household_people WHERE id = ?')->execute([$personId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function deleteFirebaseOnly(string $actorId, string $uid, bool $confirmed): void
    {
        $this->authorize($actorId);
        if (!$confirmed) throw new HttpError(422, 'confirmation_required', 'Confirm permanent deletion first.');
        // A now-registered account must use the complete SQL cleanup path.
        $q = $this->pdo->prepare('SELECT id FROM users WHERE firebase_uid = ?');
        $q->execute([$uid]);
        $id = $q->fetchColumn();
        if ($id !== false) { $this->delete($actorId, (string) $id, true); return; }
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $q->execute([$actorId]); $this->authorize($actorId);
            $q = $this->pdo->prepare('SELECT id FROM users WHERE firebase_uid = ? FOR UPDATE');
            $q->execute([$uid]);
            if ($q->fetchColumn() !== false) throw new HttpError(409, 'account_registered', 'Account setup completed. Refresh and retry deletion.');
            $email = null;
            if ($this->listFirebase !== null) {
                foreach (($this->listFirebase)() as $account) {
                    if ($account['uid'] === $uid) { $email = $account['email']; break; }
                }
            }
            if ($email !== null && $email !== '') {
                $q = $this->pdo->prepare('SELECT DISTINCT p.id FROM household_people p JOIN household_invitations i ON i.person_id = p.id WHERE p.user_id IS NULL AND i.email = ?');
                $q->execute([$email]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $personId) {
                    $this->pdo->prepare('DELETE FROM household_invitations WHERE person_id = ?')->execute([$personId]);
                    $this->pdo->prepare('DELETE FROM household_people WHERE id = ? AND user_id IS NULL')->execute([$personId]);
                }
                $this->pdo->prepare('DELETE FROM household_invitations WHERE email = ?')->execute([$email]);
            }
            // The unique-index gap lock blocks concurrent account registration.
            ($this->deleteFirebase)($uid);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function delete(string $actorId, string $targetId, bool $confirmed): void
    {
        $this->authorize($actorId);
        if (!$confirmed) throw new HttpError(422, 'confirmation_required', 'Confirm permanent deletion first.');
        $this->pdo->beginTransaction();
        try {
            // Lock the actor as well so account permission changes cannot race deletion.
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
            $lock->execute([$actorId, $targetId]);
            $lock->fetchAll();
            $this->authorize($actorId);
            $query = $this->pdo->prepare('SELECT firebase_uid, email FROM users WHERE id = ?');
            $query->execute([$targetId]);
            $target = $query->fetch(PDO::FETCH_ASSOC);
            if (!$target) throw new HttpError(404, 'user_not_found', 'User not found.');
            // Resolve invitation-associated people before deleting the evidence that
            // associates an unlinked table entry with this account. Never match names
            // or remove an entry already linked to a different account.
            $people = $this->pdo->prepare('SELECT DISTINCT p.id FROM household_people p LEFT JOIN household_invitations i ON i.household_id = p.household_id AND i.person_id = p.id WHERE p.user_id = ? OR (p.user_id IS NULL AND (i.email = ? OR (i.status = \'accepted\' AND i.responded_by_user_id = ?)))');
            $people->execute([$targetId, $target['email'], $targetId]);
            $personIds = $people->fetchAll(PDO::FETCH_COLUMN);
            if ($personIds !== []) {
                $placeholders = implode(',', array_fill(0, count($personIds), '?'));
                $this->pdo->prepare("DELETE FROM household_invitations WHERE person_id IN ($placeholders)")->execute($personIds);
                $this->pdo->prepare("DELETE FROM household_people WHERE id IN ($placeholders)")->execute($personIds);
            }
            // Keep shared recipes and history, removing account attribution and linked people.
            foreach ([
                ['DELETE i FROM household_invitations i LEFT JOIN household_people p ON p.id = i.person_id WHERE i.invited_by_user_id = ? OR i.responded_by_user_id = ? OR p.user_id = ? OR i.email = ?', [$targetId, $targetId, $targetId, $target['email']]],
                ['UPDATE recipes SET created_by_user_id = NULL WHERE created_by_user_id = ?', [$targetId]],
                ['UPDATE recipes SET updated_by_user_id = NULL WHERE updated_by_user_id = ?', [$targetId]],
                ['UPDATE cooking_events SET recorded_by_user_id = NULL WHERE recorded_by_user_id = ?', [$targetId]],
                ['DELETE FROM household_people WHERE user_id = ?', [$targetId]],
                ['DELETE FROM household_memberships WHERE user_id = ?', [$targetId]],
                ['DELETE FROM users WHERE id = ?', [$targetId]],
            ] as [$sql, $values]) {
                $this->pdo->prepare($sql)->execute($values);
            }
            // SQL errors occur before the irreversible remote operation. Firebase failures
            // roll SQL back. If commit fails after Firebase succeeds, retry is safe.
            ($this->deleteFirebase)($target['firebase_uid']);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
    private function categoryRows(string $sql, array $parameters): array {
        $query=$this->pdo->prepare($sql);
        $query->execute($parameters);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) if (isset($row['id'])) $row['id']=(string)$row['id'];
        unset($row);
        return $rows;
    }

    /** The category catalog is shared; emptiness includes all households and trash. */
    public function parentCategories(string $actorId, ?array $body = null): array {
        $this->authorize($actorId);
        $createdId=null; $coverWarning=null;
        if ($body !== null) {
            $action = $body['action'] ?? null;
            if (!in_array($action, ['add','rename','delete'], true)) throw new HttpError(422,'invalid_action','Choose a category action.');
            $label = $body['label'] ?? null;
            if ($action !== 'delete' && (!is_string($label) || trim($label)==='' || mb_strlen(trim($label))>120)) throw new HttpError(422,'invalid_label','Enter a category name of 1 to 120 characters.');
            $id = $body['id'] ?? '';
            if ($action !== 'add' && (!is_string($id) || !preg_match('/^[1-9][0-9]{0,18}$/',$id))) throw new HttpError(422,'invalid_category','Choose a parent category.');
            $this->pdo->beginTransaction();
            try {
                $parents = $this->categoryRows('SELECT id,label FROM recipe_categories WHERE parent_id IS NULL ORDER BY id FOR UPDATE', []);
                if ($action !== 'add' && !in_array($id,array_column($parents,'id'),true)) throw new HttpError(404,'category_not_found','Parent category not found.');
                if ($action === 'delete') {
                    $used = $this->categoryRows('SELECT id FROM recipe_categories WHERE parent_id=? LIMIT 1',[$id]);
                    $assigned = $this->categoryRows('SELECT category_id FROM recipe_category_assignments WHERE category_id=? LIMIT 1',[$id]);
                    if ($used || $assigned) throw new HttpError(409,'category_not_empty','Only empty parent categories can be deleted. Remove their subcategories and recipe assignments first.');
                    $statement=$this->pdo->prepare('DELETE FROM recipe_categories WHERE id=? AND parent_id IS NULL');
                    $statement->execute([$id]);
                } else {
                    $label=trim($label);
                    $duplicate=$this->categoryRows('SELECT id FROM recipe_categories WHERE parent_id IS NULL AND label=? AND id<>? LIMIT 1',[$label,$action==='add'?'0':$id]);
                    if ($duplicate) throw new HttpError(409,'duplicate_category','A parent category with that name already exists.');
                    if ($action==='add') {
                        $statement=$this->pdo->prepare('INSERT INTO recipe_categories (parent_id,slug,label,image_key,position) VALUES (NULL,?,?,?,?)');
                        $statement->execute(['custom-'.bin2hex(random_bytes(16)),$label,'',count($parents)+1]);
                        $createdId=(string)$this->pdo->lastInsertId();
                    } else {
                        $statement=$this->pdo->prepare('UPDATE recipe_categories SET label=? WHERE id=? AND parent_id IS NULL');
                        $statement->execute([$label,$id]);
                    }
                }
                $this->pdo->commit();
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                if ($error instanceof PDOException && ($error->errorInfo[1] ?? null)===1451) throw new HttpError(409,'category_not_empty','Only empty parent categories can be deleted.');
                throw $error;
            }
        }
        if ($createdId!==null && $this->categoryImages!==null) {
            try { $this->categoryImages->ensureParent($createdId,$actorId); }
            catch (Throwable $error) { $coverWarning='Category added, but its AI cover could not be generated. Use Generate cover to try again.'; }
        }
        $categories=$this->categoryRows('SELECT c.id,c.label,c.image_key,(SELECT COUNT(*) FROM recipe_categories child WHERE child.parent_id=c.id) AS child_count,(SELECT COUNT(*) FROM recipe_category_assignments a WHERE a.category_id=c.id) AS assignment_count FROM recipe_categories c WHERE c.parent_id IS NULL ORDER BY c.position,c.label,c.id',[]);
        foreach ($categories as &$category) {
            $category['can_delete']=(int)$category['child_count']===0 && (int)$category['assignment_count']===0;
            $category['image_url']=str_starts_with($category['image_key'],'ai-') ? CategoryImageService::imageUrl((string)$category['id'],$this->mediaUrl) : null;
            $category['needs_ai_image']=$category['image_key']==='';
        }
        unset($category);
        return ['categories'=>$categories,'cover_warning'=>$coverWarning];
    }
}
