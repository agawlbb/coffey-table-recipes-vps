<?php
declare(strict_types=1);

final class HouseholdService
{
    public function __construct(private PDO $pdo, private $requireFirebaseAccount = null, private ?ChildMode $childModes = null) {}

    public function bootstrap(array $identity): array
    {
        $this->pdo->beginTransaction();
        try {
            // Serialize provisioning with deletion so a token cannot recreate a removed UID.
            $exists = $this->pdo->prepare('SELECT id FROM users WHERE firebase_uid = ? FOR UPDATE');
            $exists->execute([$identity['uid']]);
            if ($exists->fetchColumn() === false && $this->requireFirebaseAccount !== null) {
                ($this->requireFirebaseAccount)($identity['uid']);
            }
            $statement = $this->pdo->prepare('INSERT INTO users (firebase_uid, display_name, email, email_verified) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE display_name = IF(disabled = 0, VALUES(display_name), display_name), email = IF(disabled = 0, VALUES(email), email), email_verified = IF(disabled = 0, VALUES(email_verified), email_verified)');
            $statement->execute([$identity['uid'], $identity['name'], $identity['email'] ?? null, !empty($identity['email_verified']) ? 1 : 0]);
            $statement = $this->pdo->prepare('SELECT id, display_name, email, email_verified, disabled, account_role FROM users WHERE firebase_uid = ?');
            $statement->execute([$identity['uid']]);
            $user = $statement->fetch(PDO::FETCH_ASSOC);
            if ($user['disabled']) throw new HttpError(403, 'account_disabled', 'This account cannot access the recipe service.');
            $this->pdo->commit();
            return ['id' => (string) $user['id'], 'display_name' => $user['display_name'], 'email' => $user['email'], 'email_verified' => (bool) $user['email_verified'], 'account_role' => $user['account_role']];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function households(string $userId): array
    {
        $statement = $this->pdo->prepare('SELECT h.id, h.name, m.role FROM households h JOIN household_memberships m ON m.household_id = h.id WHERE m.user_id = ? ORDER BY h.id');
        $statement->execute([$userId]);
        return array_map(static function (array $row): array { $row['id'] = (string) $row['id']; return $row; }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function membership(string $userId, string $householdId, bool $admin = false): array
    {
        $statement = $this->pdo->prepare('SELECT role FROM household_memberships WHERE household_id = ? AND user_id = ?');
        $statement->execute([$householdId, $userId]);
        $member = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$member) throw new HttpError(404, 'household_not_found', 'Household not found.');
        if ($admin && !in_array($member['role'], ['owner', 'admin'], true)) {
            throw new HttpError(403, 'permission_denied', 'An owner or administrator must make this change.');
        }
        return $member;
    }

    public function requireAiImages(string $userId, string $householdId): void
    {
        if (!$this->aiImagesEnabled($userId, $householdId)) {
            throw new HttpError(403, 'ai_images_disabled', 'AI image generation is disabled for this household. Ask the application administrator to enable it.');
        }
    }

    public function aiImagesEnabled(string $userId, string $householdId): bool
    {
        $this->membership($userId, $householdId);
        $query = $this->pdo->prepare('SELECT ai_images_enabled FROM households WHERE id = ?');
        $query->execute([$householdId]);
        return (bool) $query->fetchColumn();
    }

    public function create(string $userId, string $name, string $personName): array
    {
        $this->pdo->beginTransaction();
        try {
            // Serialize creation for this account without restricting future memberships.
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$userId]);
            if ($this->households($userId) !== []) {
                throw new HttpError(409, 'household_already_exists', 'Your account already belongs to a household.');
            }
            $statement = $this->pdo->prepare('INSERT INTO households (name) VALUES (?)');
            $statement->execute([$name]);
            $id = $this->pdo->lastInsertId();
            $statement = $this->pdo->prepare("INSERT INTO household_memberships (household_id, user_id, role) VALUES (?, ?, 'owner')");
            $statement->execute([$id, $userId]);
            $statement = $this->pdo->prepare('INSERT INTO household_people (household_id, user_id, display_name) VALUES (?, ?, ?)');
            $statement->execute([$id, $userId, $personName]);
            $this->pdo->commit();
            return ['id' => $id, 'name' => $name, 'role' => 'owner'];
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function rename(string $userId, string $householdId, string $name): array
    {
        $membership = $this->membership($userId, $householdId, true);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 200) {
            throw new HttpError(400, 'invalid_name', 'Enter a household name of up to 200 characters.');
        }
        $statement = $this->pdo->prepare('UPDATE households SET name = ? WHERE id = ?');
        $statement->execute([$name, $householdId]);
        return ['id' => $householdId, 'name' => $name, 'role' => $membership['role']];
    }

    public function people(string $userId, string $householdId): array
    {
        $this->membership($userId, $householdId);
        $childFields = $this->childModes === null ? '' : ', u.firebase_uid';
        $childJoin = $this->childModes === null ? '' : ' LEFT JOIN users u ON u.id = p.user_id';
        $statement = $this->pdo->prepare("SELECT p.id, p.display_name, p.user_id, i.email AS invitation_email$childFields FROM household_people p$childJoin LEFT JOIN household_invitations i ON i.person_id = p.id AND i.status = 'pending' AND i.expires_at > UTC_TIMESTAMP(6) WHERE p.household_id = ? ORDER BY p.id");
        $statement->execute([$householdId]);
        return array_map(function (array $row): array {
            $row['id'] = (string) $row['id'];
            $row['user_id'] = $row['user_id'] === null ? null : (string) $row['user_id'];
            if ($this->childModes !== null) {
                $row['child_mode'] = false;
                $uid = $row['firebase_uid'];
                if (is_string($uid) && $uid !== '') $row['child_mode'] = $this->childModes->state(['uid'=>$uid])['enabled'];
                unset($row['firebase_uid']);
            }
            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function renameOwnPerson(string $userId, string $householdId, string $name): array
    {
        $this->membership($userId, $householdId);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 200) {
            throw new HttpError(400, 'invalid_name', 'Enter a name of up to 200 characters.');
        }
        $statement = $this->pdo->prepare('SELECT id FROM household_people WHERE household_id = ? AND user_id = ?');
        $statement->execute([$householdId, $userId]);
        $personId = $statement->fetchColumn();
        if ($personId === false) {
            throw new HttpError(404, 'person_not_found', 'Your name at this table was not found.');
        }
        $statement = $this->pdo->prepare('UPDATE household_people SET display_name = ? WHERE id = ? AND household_id = ? AND user_id = ?');
        $statement->execute([$name, $personId, $householdId, $userId]);
        return ['id' => (string) $personId, 'display_name' => $name, 'user_id' => $userId];
    }

    public function addPerson(string $userId, string $householdId, string $name): array
    {
        $this->membership($userId, $householdId, true);
        $statement = $this->pdo->prepare('INSERT INTO household_people (household_id, display_name) VALUES (?, ?)');
        $statement->execute([$householdId, $name]);
        return ['id' => $this->pdo->lastInsertId(), 'display_name' => $name, 'user_id' => null];
    }
}
