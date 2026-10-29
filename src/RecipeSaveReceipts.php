<?php
declare(strict_types=1);

/** Called only under RecipeEditorService's transaction and relationship lock. */
final class RecipeSaveReceipts
{
    private mixed $id;
    private string $hash;
    public function __construct(private PDO $pdo, private string $user, private string $household, string $recipe, array $body) {
        $this->id = $body['client_save_id'] ?? null;
        if ($this->id !== null && (!is_string($this->id) || !preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $this->id))) {
            throw new HttpError(422, 'invalid_save_id', 'Invalid save identifier.');
        }
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (!is_array($value)) return $value;
            if (array_values($value) !== $value) ksort($value);
            return array_map($canonical, $value);
        };
        $this->hash = hash('sha256', json_encode([$recipe, $canonical($body)], JSON_THROW_ON_ERROR));
    }
    public function replay(): ?array {
        if ($this->id === null) return null;
        $q = $this->pdo->prepare('SELECT body_hash,response FROM recipe_save_receipts WHERE household_id=? AND user_id=? AND save_id=?');
        $q->execute([$this->household,$this->user,$this->id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!hash_equals($row['body_hash'], $this->hash)) throw new HttpError(409, 'save_id_reused', 'This save identifier was already used for a different change.');
        return json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR);
    }
    public function record(array $response): void {
        if ($this->id === null) return;
        $q = $this->pdo->prepare('INSERT INTO recipe_save_receipts(household_id,user_id,save_id,body_hash,response) VALUES(?,?,?,?,?)');
        $q->execute([$this->household,$this->user,$this->id,$this->hash,json_encode($response, JSON_THROW_ON_ERROR)]);
    }
}
