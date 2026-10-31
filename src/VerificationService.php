<?php
declare(strict_types=1);

final class VerificationService
{
    public function __construct(private $generateLink, private $sendMail) {}

    public function send(array $identity): void
    {
        $email = $identity['email'] ?? '';
        $uid = $identity['uid'] ?? '';
        if (!is_string($uid) || $uid === '') throw new HttpError(401, 'authentication_required', 'Please sign in.');
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new HttpError(422, 'email_required', 'Your account needs an email address.');
        $link = ($this->generateLink)($uid, $email);
        ($this->sendMail)($email, $link);
    }
}
