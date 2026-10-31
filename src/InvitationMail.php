<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

final class InvitationMail
{
    public function __construct(private array $config) {}

    public static function text(string $inviter, string $household, bool $existingAccount, string $appUrl, string $email = ''): string
    {
        $text = "$inviter has invited you to join \"$household\" on Coffey Table Recipes.\n\n";
        if ($existingAccount) {
            $text .= "Your place at the table is waiting. Log in with this email address to accept or decline the invitation.\n";
        } else {
            $text .= "A shared table for your favorite recipes. Keep your household's recipes together, discover what to cook, and save each person's favorites.\n\nCreate an account using the link below; your email will already be filled in. We'll send a verification email automatically. Open that email to verify your address, then return to accept or decline your invitation.\n";
        }
        $appUrl .= (str_contains($appUrl, '?') ? '&' : '?') . http_build_query(['account' => $existingAccount ? 'signin' : 'create', 'email' => $email]);
        return $text . "\n$appUrl\n\nThe invitation is yours to accept or decline and expires in 14 days. You'll join the household only when you accept.\n\nSee you at the table,\nCoffey Table Recipes\n\nThis is an automated invitation. Replies to this address aren't monitored.\n";
    }

    public static function html(string $text): string
    {
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $paragraphs = preg_split('/\n\s*\n/', trim($text));
        $content = '';
        foreach ($paragraphs as $index => $paragraph) {
            if (preg_match('#^https?://[^\s]+$#', $paragraph)) {
                $label = str_contains($paragraph, 'account=create') ? 'Create your account' : 'Review your invitation';
                $url = $escape($paragraph);
                $content .= '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px 0"><tr><td bgcolor="#465537" style="border-radius:8px"><a href="' . $url . '" style="display:inline-block;padding:15px 24px;color:#ffffff;text-decoration:none;font-weight:bold">' . $label . '</a></td></tr></table>';
                $content .= '<p style="font-size:12px;line-height:1.6;color:#6c6a60;overflow-wrap:anywhere">If the button doesn’t open, use this link:<br><a href="' . $url . '" style="color:#465537">' . $url . '</a></p>';
            } else {
                $size = $index === 0 ? '20' : '15';
                $content .= '<p style="margin:0 0 20px;font-size:' . $size . 'px;line-height:1.65;color:#38392f">' . nl2br($escape($paragraph)) . '</p>';
            }
        }
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Your Coffey Table invitation</title></head><body style="margin:0;padding:0;background:#f4efe5;font-family:Arial,Helvetica,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#fffdf7;border-radius:16px"><tr><td align="center" style="padding:28px 24px 8px"><img src="cid:coffey-table-brand" width="340" alt="Coffey Table Recipes cookbook beside a warm cup of coffee" style="display:block;width:100%;max-width:340px;height:auto;border:0"><h1 style="margin:24px 0 12px;color:#465537;font-size:28px;line-height:1.25;font-family:Georgia,serif">You’re invited to the table</h1></td></tr><tr><td style="padding:16px 28px 20px">' . $content . '</td></tr></table></td></tr></table></body></html>';
    }

    public function send(string $email, string $text): void
    {
        $mail = $this->message($email, $text);
        // Certificate checks remain enabled. SMTP diagnostics must never expose credentials.
        try { $mail->send(); }
        catch (Throwable $error) {
            throw new HttpError(503, 'invitation_email_failed', 'The invitation email could not be sent. Please try again later.');
        }
    }

    public function message(string $email, string $text): PHPMailer
    {
        $settings = $this->config['smtp'];
        if (empty($settings['host']) || empty($settings['username']) || empty($settings['password'])) {
            throw new HttpError(503, 'mail_not_configured', 'Invitation email delivery is not configured yet.');
        }
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $settings['host'];
        $mail->Port = (int) $settings['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $settings['username'];
        $mail->Password = $settings['password'];
        $mail->SMTPSecure = $settings['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 12;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($settings['from_email'], $settings['from_name']);
        $mail->addAddress($email);
        $mail->Subject = "You're invited to Coffey Table Recipes";
        $mail->isHTML(true);
        $mail->Body = self::html($text);
        $mail->AltBody = $text;
        $mail->addEmbeddedImage(dirname(__DIR__) . '/assets/email/coffey_table.jpg', 'coffey-table-brand', 'coffey-table.jpg', 'base64', 'image/jpeg');
        return $mail;
    }
}
