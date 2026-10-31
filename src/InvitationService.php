<?php
declare(strict_types=1);

final class InvitationService
{
    private $lookup;
    private $send;

    public function __construct(private PDO $pdo, private HouseholdService $households, callable $lookup, callable $send, private string $appUrl)
    {
        $this->lookup = $lookup;
        $this->send = $send;
    }

    private function expire(): void
    {
        $this->pdo->exec("UPDATE household_invitations SET status = 'expired' WHERE status IN ('sending', 'pending') AND expires_at <= UTC_TIMESTAMP(6)");
        $this->pdo->exec("UPDATE household_invitations SET status = 'failed' WHERE status = 'sending' AND created_at < UTC_TIMESTAMP(6) - INTERVAL 5 MINUTE");
    }

    public function invite(array $inviter, string $householdId, string $personId, string $email): array
    {
        $this->households->membership($inviter['id'], $householdId, true);
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, 'invalid_email', 'Enter a valid email address.');
        }
        $personQuery = $this->pdo->prepare('SELECT user_id FROM household_people WHERE household_id = ? AND id = ?');
        $personQuery->execute([$householdId, $personId]);
        $person = $personQuery->fetch(PDO::FETCH_ASSOC);
        if (!$person) throw new HttpError(404, 'person_not_found', 'Household person not found.');
        if ($person['user_id'] !== null) throw new HttpError(409, 'person_already_linked', 'This person already has an account.');
        $account = ($this->lookup)($email);
        if ($account !== null) {
            $q = $this->pdo->prepare('SELECT u.disabled, m.household_id FROM users u LEFT JOIN household_memberships m ON m.user_id = u.id WHERE u.firebase_uid = ?');
            $q->execute([$account['uid']]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['household_id'] !== null || $row['disabled']) {
                    throw new HttpError(409, 'invitee_unavailable', 'This person could not be invited because their account already belongs to a household or is unavailable.');
                }
            }
            if (!empty($account['disabled'])) throw new HttpError(409, 'invitee_unavailable', 'This person could not be invited because their account is unavailable.');
        }
        $this->expire();
        $this->pdo->beginTransaction();
        try {
            // Serialize per inviter for the rate limit, and per person for duplicate invites.
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$inviter['id']]);
            $q = $this->pdo->prepare('SELECT user_id FROM household_people WHERE household_id = ? AND id = ? FOR UPDATE');
            $q->execute([$householdId, $personId]);
            $person = $q->fetch(PDO::FETCH_ASSOC);
            if (!$person || $person['user_id'] !== null) throw new HttpError(409, 'person_already_linked', 'This person already has an account.');
            $q = $this->pdo->prepare("SELECT COUNT(*) FROM household_invitations WHERE invited_by_user_id = ? AND created_at > UTC_TIMESTAMP(6) - INTERVAL 1 HOUR");
            $q->execute([$inviter['id']]);
            if ((int) $q->fetchColumn() >= 20) throw new HttpError(429, 'invitation_limit', 'Too many invitations. Please try again later.');
            $q = $this->pdo->prepare("SELECT id FROM household_invitations WHERE household_id = ? AND status IN ('sending', 'pending') AND (person_id = ? OR email = ?)");
            $q->execute([$householdId, $personId, $email]);
            if ($q->fetchColumn()) throw new HttpError(409, 'invitation_pending', 'An invitation is already pending for this person or email.');
            $q = $this->pdo->prepare("INSERT INTO household_invitations (household_id, person_id, invited_by_user_id, email, expires_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6) + INTERVAL 14 DAY)");
            $q->execute([$householdId, $personId, $inviter['id'], $email]);
            $id = $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (Throwable $error) { $this->pdo->rollBack(); throw $error; }
        $q = $this->pdo->prepare('SELECT name FROM households WHERE id = ?');
        $q->execute([$householdId]);
        $name = $q->fetchColumn();
        try {
            ($this->send)($email, InvitationMail::text($inviter['display_name'] ?? 'A household administrator', $name, $account !== null, $this->appUrl, $email));
        } catch (Throwable $error) {
            $this->pdo->prepare("UPDATE household_invitations SET status = 'failed' WHERE id = ? AND status = 'sending'")->execute([$id]);
            if ($error instanceof HttpError) throw $error;
            throw new HttpError(503, 'invitation_email_failed', 'The invitation email could not be sent. Please try again later.');
        }
        $this->pdo->prepare("UPDATE household_invitations SET status = 'pending', sent_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'sending'")->execute([$id]);
        return ['id' => $id, 'status' => 'pending'];
    }

    public function pending(array $user): array
    {
        $this->expire();
        if (empty($user['email']) || $this->households->households($user['id']) !== []) return ['invitations' => [], 'verification_required' => false];
        $q = $this->pdo->prepare("SELECT i.id, i.household_id, h.name AS household_name, u.display_name AS inviter_name FROM household_invitations i JOIN households h ON h.id = i.household_id JOIN users u ON u.id = i.invited_by_user_id JOIN household_people p ON p.id = i.person_id JOIN household_memberships m ON m.household_id = i.household_id AND m.user_id = i.invited_by_user_id WHERE i.email = ? AND i.status = 'pending' AND p.user_id IS NULL AND u.disabled = 0 AND m.role IN ('owner', 'admin') ORDER BY i.id");
        $q->execute([$user['email']]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (empty($user['email_verified'])) return ['invitations' => [], 'verification_required' => $rows !== []];
        foreach ($rows as &$row) { $row['id'] = (string) $row['id']; $row['household_id'] = (string) $row['household_id']; }
        return ['invitations' => $rows, 'verification_required' => false];
    }

    public function respond(array $user, string $id, string $decision): array
    {
        if (!in_array($decision, ['accept', 'decline'], true)) throw new HttpError(422, 'invalid_decision', 'Choose accept or decline.');
        if (empty($user['email']) || empty($user['email_verified'])) throw new HttpError(403, 'email_verification_required', 'Verify your email address before responding to an invitation.');
        $this->expire();
        $this->pdo->beginTransaction();
        try {
            // Same account-row lock as household creation prevents concurrent double membership.
            $q = $this->pdo->prepare('SELECT id FROM users WHERE id = ? AND disabled = 0 FOR UPDATE');
            $q->execute([$user['id']]);
            if (!$q->fetchColumn()) throw new HttpError(403, 'account_disabled', 'This account cannot access the recipe service.');
            $q = $this->pdo->prepare('SELECT * FROM household_invitations WHERE id = ? AND email = ? FOR UPDATE');
            $q->execute([$id, $user['email']]);
            $invite = $q->fetch(PDO::FETCH_ASSOC);
            if (!$invite) throw new HttpError(404, 'invitation_not_found', 'Invitation not found.');
            if ($invite['status'] !== 'pending') throw new HttpError(409, 'invitation_not_pending', 'This invitation is no longer pending.');
            if ($decision === 'accept') {
                if ($this->households->households($user['id']) !== []) throw new HttpError(409, 'household_already_exists', 'You could not accept because your account already belongs to a household.');
                $this->households->membership((string) $invite['invited_by_user_id'], (string) $invite['household_id'], true);
                $q = $this->pdo->prepare('SELECT disabled FROM users WHERE id = ?');
                $q->execute([$invite['invited_by_user_id']]);
                if ($q->fetchColumn()) throw new HttpError(409, 'invitation_unavailable', 'This invitation is no longer available.');
                $q = $this->pdo->prepare('SELECT user_id FROM household_people WHERE household_id = ? AND id = ? FOR UPDATE');
                $q->execute([$invite['household_id'], $invite['person_id']]);
                $person = $q->fetch(PDO::FETCH_ASSOC);
                if (!$person || $person['user_id'] !== null) throw new HttpError(409, 'person_already_linked', 'This person is already linked to an account.');
                $this->pdo->prepare("INSERT INTO household_memberships (household_id, user_id, role) VALUES (?, ?, 'member')")->execute([$invite['household_id'], $user['id']]);
                $this->pdo->prepare('UPDATE household_people SET user_id = ? WHERE household_id = ? AND id = ?')->execute([$user['id'], $invite['household_id'], $invite['person_id']]);
            }
            $status = $decision === 'accept' ? 'accepted' : 'declined';
            $this->pdo->prepare('UPDATE household_invitations SET status = ?, responded_at = UTC_TIMESTAMP(6), responded_by_user_id = ? WHERE id = ?')->execute([$status, $user['id'], $id]);
            if ($decision === 'accept') {
                $this->pdo->prepare("UPDATE household_invitations SET status = 'cancelled' WHERE email = ? AND id <> ? AND status IN ('sending', 'pending')")->execute([$user['email'], $id]);
            }
            $this->pdo->commit();
            return ['id' => $id, 'status' => $status];
        } catch (Throwable $error) { $this->pdo->rollBack(); throw $error; }
    }
}
