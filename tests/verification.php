<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
$checks = 0;
function checkVerification(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
$sent = []; $calls = []; $fail = false;
$service = new VerificationService(static function ($uid, $email) use (&$calls, &$fail) {
    $calls[] = [$uid, $email];
    if ($fail) throw new HttpError(503, 'verification_link_unavailable', 'No link');
    return 'https://example.test/verify?code=test-only';
}, static function ($email, $link) use (&$sent) { $sent[] = [$email, $link]; });
foreach ([[], ['uid'=>'test'], ['uid'=>'test','email'=>'bad']] as $identity) {
    try { $service->send($identity); throw new RuntimeException('Invalid identity accepted'); }
    catch (HttpError $error) { checkVerification(in_array($error->status, [401,422]), 'Wrong error'); }
}
checkVerification($calls === [] && $sent === [], 'Invalid request reached Firebase or SMTP');
$service->send(['uid'=>'test', 'email'=>'test@example.test']);
checkVerification($calls === [['test','test@example.test']], 'Did not use authenticated identity');
checkVerification(count($sent) === 1 && $sent[0][0] === 'test@example.test', 'Wrong mail recipient');
$fail = true;
try { $service->send(['uid'=>'test','email'=>'test@example.test']); } catch (HttpError $error) { checkVerification($error->status===503, 'Link failure hidden'); }
checkVerification(count($sent)===1, 'Sent mail without a Firebase link');
$config = ['smtp'=>['host'=>'smtp.example.test','port'=>465,'username'=>'sender@example.test','password'=>'test-only','encryption'=>'ssl','from_email'=>'sender@example.test','from_name'=>'Coffey Table Recipes']];
$mail = (new VerificationMail($config))->message('test@example.test','https://example.test/verify?code=abc&continueUrl=test');
checkVerification($mail->Subject === 'Verify your email for Coffey Table Recipes', 'Wrong subject');
checkVerification(str_contains($mail->Body, 'Verify your email') && !str_contains($mail->Body, 'Review your invitation'), 'Wrong email action');
checkVerification(str_contains($mail->Body, '&amp;continueUrl='), 'Unsafe link escaping');
checkVerification(str_contains($mail->AltBody,'https://example.test/verify?code=abc&continueUrl=test'), 'Plain text link missing');
checkVerification(str_contains($mail->Body,'cid:coffey-table-brand'), 'Brand image missing');
checkVerification($mail->getToAddresses()[0][0] === 'test@example.test', 'Wrong SMTP recipient');
echo "$checks verification checks passed. No mail sent.\n";
