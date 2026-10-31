<?php
declare(strict_types=1);

final class VerificationMail
{
    public function __construct(private array $config) {}

    public function message(string $email, string $link): PHPMailer\PHPMailer\PHPMailer
    {
        $text = "Welcome to Coffey Table Recipes.\n\nVerify your email address to keep your household's recipes and favorites together.\n\n$link\n\nAfter verification, return to Coffey Table and sign in using this email address. If you have a household invitation, you'll be able to accept or decline it.\n\nIf you didn't request this email, you can ignore it. Replies to this address aren't monitored.\n";
        $mail = (new InvitationMail($this->config))->message($email, $text);
        $mail->Subject = 'Verify your email for Coffey Table Recipes';
        $mail->Body = str_replace(['Your Coffey Table invitation', 'You’re invited to the table', 'Review your invitation'], ['Verify your Coffey Table email', 'Welcome to Coffey Table', 'Verify your email'], $mail->Body);
        return $mail;
    }

    public function send(string $email, string $link): void
    {
        try { $this->message($email, $link)->send(); }
        catch (Throwable $error) {
            throw new HttpError(503, 'verification_email_failed', 'The verification email could not be sent. Please try again later.');
        }
    }
}
