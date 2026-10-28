<?php
declare(strict_types=1);

/** Transport-independent shopping protocol. Every operation rechecks access. */
final class ShoppingSocket
{
    public function __construct(private PDO $pdo, private Closure $verify, private Closure $childGuard,
        private HomeAssistantConnections $connections, private ShoppingSyncRequests $requests) {}

    public function authenticate(string $token, ?string $household): array
    {
        if (str_starts_with($token, 'ctrha_')) {
            $id = $this->connections->household($token);
            if ($household !== null && $household !== $id) throw new HttpError(404, 'household_not_found', 'Household not found.');
            $q = $this->pdo->prepare('SELECT id FROM households WHERE id=?'); $q->execute([$id]);
            if ($q->fetchColumn() === false) throw new HttpError(401, 'invalid_connection', 'Reconnect Home Assistant.');
            return ['household'=>$id, 'user'=>'', 'ha'=>true];
        }
        $identity = ($this->verify)($token);
        $q = $this->pdo->prepare('SELECT id, disabled FROM users WHERE firebase_uid=?'); $q->execute([$identity['uid']]);
        $user = $q->fetch(PDO::FETCH_ASSOC);
        if (!$user || $user['disabled']) throw new HttpError(403, 'account_disabled', 'Account unavailable.');
        if ($household === null) throw new HttpError(422, 'invalid_household', 'Choose a household.');
        $q=$this->pdo->prepare('SELECT role FROM household_memberships WHERE user_id=? AND household_id=?');
        $q->execute([$user['id'],$household]);
        if ($q->fetchColumn()===false) throw new HttpError(404,'household_not_found','Household not found.');
        return ['household'=>$household, 'user'=>(string)$user['id'], 'ha'=>false];
    }

    public function handle(string $token, string $household, string $path, ?array $body): array
    {
        $actor = $this->authenticate($token, $household);
        return $this->perform($actor,$path,$body,$token);
    }

    public function snapshot(array $actor): array
    {
        return $this->perform($actor,'/households/'.$actor['household'].'/shopping-list',null,'');
    }

    private function perform(array $actor,string $path,?array $body,string $token): array
    {
        $household=$actor['household'];
        $root = '/households/'.$household.'/shopping-list';
        if (!in_array($path, [$root, $root.'/sync-request'], true)) throw new HttpError(404, 'not_found', 'Shopping endpoint not found.');
        if ($body !== null && !$actor['ha']) ($this->childGuard)($token);
        if ($path === $root.'/sync-request') {
            if ($body === null || $actor['ha']) throw new HttpError(403, 'permission_denied', 'Unsupported operation.');
            $state = $this->requests->request($household);
            return ['request'=>end($state['requests'])];
        }
        if ($actor['ha'] && $body !== null && array_diff(array_keys($body),
            ['revision','items','base_items','client_change_id','edited_at_ms']) !== []) {
            throw new HttpError(422, 'invalid_list', 'Only shopping items may be changed.');
        }
        $authorize = static function (string $user, string $id) use ($actor, $household): void {
            if ($id !== $household || $user !== $actor['user']) throw new HttpError(404, 'household_not_found', 'Household not found.');
        };
        $service = new ShoppingListService($this->pdo, $authorize);
        $result = $body === null ? $service->read($actor['user'], $household) : $service->save($actor['user'], $household, $body);
        $result['sync_requests'] = $this->requests->read($household);
        return $result;
    }
}
