<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$checks = 0;
function expectMail(bool $value, string $description): void {
    global $checks;
    if (!$value) throw new RuntimeException($description);
    $checks++;
}
$text = InvitationMail::text('Alex <script>alert(1)</script>', 'Family & friends', false, 'https://example.test/app/', 'guest+family@example.test');
$html = InvitationMail::html($text);
expectMail(str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'), 'Inviter name was not escaped.');
expectMail(str_contains($html, 'Family &amp; friends'), 'Household name was not escaped.');
expectMail(str_contains($html, 'Create your account'), 'New-account action missing.');
expectMail(str_contains($html, 'email=guest%2Bfamily%40example.test'), 'Recipient encoding lost.');
expectMail(str_contains($html, '&amp;email='), 'Link attribute not escaped.');
expectMail(str_contains($html, 'cid:coffey-table-brand'), 'Embedded brand image missing.');
expectMail(str_contains($html, 'verification email automatically'), 'Automatic verification wording missing.');
expectMail(str_contains($html, 'expires in 14 days'), 'Expiry wording missing.');
$existing = InvitationMail::html(InvitationMail::text('Alex', 'Family', true, 'https://example.test/app/', 'guest@example.test'));
expectMail(str_contains($existing, 'Review your invitation') && !str_contains($existing, 'Create your account'), 'Existing-account action wrong.');
$config = ['smtp' => ['host' => 'smtp.example.test', 'port' => 465, 'username' => 'sender@example.test', 'password' => 'test-only', 'encryption' => 'ssl', 'from_email' => 'sender@example.test', 'from_name' => 'Coffey Table Recipes']];
$mail = (new InvitationMail($config))->message('guest@example.test', $text);
expectMail($mail->AltBody === $text, 'Plain-text fallback lost.');
expectMail($mail->preSend(), 'MIME assembly failed.');
$mime = $mail->getSentMIMEMessage();
expectMail(str_contains($mime, 'Content-Type: multipart/alternative;'), 'Alternative MIME missing.');
expectMail(str_contains($mime, 'Content-ID: <coffey-table-brand>'), 'Image content ID missing.');
expectMail(str_contains($mime, 'Content-Type: image/jpeg;'), 'JPEG MIME missing.');
expectMail(str_contains($mime, 'Content-Disposition: inline;'), 'Image is not inline.');
echo "$checks invitation email checks passed. No email sent.\n";
