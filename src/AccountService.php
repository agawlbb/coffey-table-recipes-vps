<?php
declare(strict_types=1);

/** Firebase owns credentials. This service owns local account/setup and mail actions. */
final class AccountService {
    public function __construct(private PDO $pdo,private Closure $verify,private $requireAccount,
        private $policy,private $lookup,private $sendInvitation,private $generateVerification,
        private $sendVerification,private string $appUrl) {}

    public static function matches(string $path): bool {
        return preg_match('#^/(?:me(?:/verification-email)?|households|households/[1-9][0-9]*/people/[1-9][0-9]*/invitations|invitations/[1-9][0-9]*/respond)$#D',parse_url($path,PHP_URL_PATH)?:'')===1;
    }

    public function handle(string $token,string $path,?array $body): array {
        $route=parse_url($path,PHP_URL_PATH);
        if (!self::matches($path)) throw new HttpError(404,'not_found','Account endpoint not found.');
        $identity=($this->verify)($token);
        $households=new HouseholdService($this->pdo,$this->requireAccount);
        $user=$households->bootstrap($identity);
        // PIN storage and administrator resets remain on InMotion. Never assume disabled on failure.
        $policy=($this->policy)($token);
        if (!is_array($policy) || !is_bool($policy['enabled']??null)) throw new HttpError(503,'account_policy_unavailable','Account policy is temporarily unavailable. Please try again.');
        if ($body!==null && $policy['enabled']) throw new HttpError(403,'child_mode','Child mode allows viewing and favorites only.');
        $invitations=new InvitationService($this->pdo,$households,$this->lookup,$this->sendInvitation,$this->appUrl);
        if ($route==='/me' && $body===null) {
            $user['child_mode']=$policy['enabled'];
            $pending=$invitations->pending($user);
            return ['user'=>$user,'households'=>$households->households($user['id']),
                'invitations'=>$pending['invitations'],'invitation_verification_required'=>$pending['verification_required']];
        }
        if ($route==='/households') {
            return $body===null ? ['households'=>$households->households($user['id'])]
                : ['household'=>$households->create($user['id'],$this->name($body,'name'),$this->name($body,'person_name'))];
        }
        if ($body===null) throw new HttpError(405,'method_not_allowed','Use a write request.');
        if ($route==='/me/verification-email') {
            (new VerificationService($this->generateVerification,$this->sendVerification))->send($identity);
            return ['sent'=>true];
        }
        if (preg_match('#^/households/([1-9][0-9]*)/people/([1-9][0-9]*)/invitations$#D',$route,$match)) {
            if (!is_string($body['email']??null)) throw new HttpError(422,'invalid_email','Enter a valid email address.');
            return ['invitation'=>$invitations->invite($user,$match[1],$match[2],$body['email'])];
        }
        if (preg_match('#^/invitations/([1-9][0-9]*)/respond$#D',$route,$match)) {
            if (!is_string($body['decision']??null)) throw new HttpError(422,'invalid_decision','Choose accept or decline.');
            return ['invitation'=>$invitations->respond($user,$match[1],$body['decision'])];
        }
        throw new HttpError(405,'method_not_allowed','Unsupported account request.');
    }

    private function name(array $body,string $key): string {
        $name=$body[$key]??null;
        if (!is_string($name) || trim($name)==='' || mb_strlen(trim($name))>200) throw new HttpError(422,'invalid_name','Enter a name of 1 to 200 characters.');
        return trim($name);
    }
}
