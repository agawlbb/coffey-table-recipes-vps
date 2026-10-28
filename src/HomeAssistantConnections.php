<?php
declare(strict_types=1);

/** Household-owned integration credentials; stored hashes never authenticate. */
final class HomeAssistantConnections
{
    public function __construct(private PDO $pdo) {}

    private static function hash(string $token): string
    {
        if (!preg_match('/^ctrha_[a-f0-9]{64}$/D', $token)) throw new HttpError(401, 'invalid_connection', 'Reconnect Home Assistant.');
        return hash('sha256', $token);
    }

    public function create(string $household): array
    {
        $token = 'ctrha_' . bin2hex(random_bytes(32));
        $q = $this->pdo->prepare('INSERT INTO shopping_connections (token_hash,household_id,created_at) VALUES (?,?,?)');
        $q->execute([self::hash($token), $household, gmdate('Y-m-d H:i:s')]);
        return ['token'=>$token, 'household_id'=>$household];
    }

    public function household(string $token): string
    {
        $q = $this->pdo->prepare('SELECT household_id FROM shopping_connections WHERE token_hash=?');
        $q->execute([self::hash($token)]); $id = $q->fetchColumn();
        if ($id === false) throw new HttpError(401, 'invalid_connection', 'Reconnect Home Assistant.');
        return (string)$id;
    }

    public function revoke(string $token): void
    {
        $q = $this->pdo->prepare('DELETE FROM shopping_connections WHERE token_hash=?');
        $q->execute([self::hash($token)]);
    }

    public function list(string $household): array
    {
        $q = $this->pdo->prepare('SELECT token_hash AS id, created_at FROM shopping_connections WHERE household_id=? ORDER BY created_at,token_hash');
        $q->execute([$household]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function revokeById(string $household, string $id): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new HttpError(404, 'connection_not_found', 'Connection not found.');
        $q = $this->pdo->prepare('DELETE FROM shopping_connections WHERE household_id=? AND token_hash=?');
        $q->execute([$household, $id]);
        if ($q->rowCount() === 0) throw new HttpError(404, 'connection_not_found', 'Connection not found.');
    }
}
