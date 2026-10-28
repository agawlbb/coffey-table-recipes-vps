<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Firebase\JWT\JWT;

$count = 0;
function check(bool $condition, string $message): void {
    global $count;
    if (!$condition) throw new RuntimeException($message);
    $count++;
}
function rejected(callable $action, int $status): void {
    try { $action(); } catch (HttpError $error) { check($error->status === $status, 'Wrong rejection status.'); return; }
    throw new RuntimeException('Expected request rejection.');
}

$opensslOptions = PHP_OS_FAMILY === 'Windows' ? ['config' => 'C:/xampp/apache/conf/openssl.cnf'] : [];
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048] + $opensslOptions);
if ($key === false) throw new RuntimeException('Cannot generate test signing key.');
openssl_pkey_export($key, $privateKey, null, $opensslOptions);
$publicKey = openssl_pkey_get_details($key)['key'];
$verifier = new FirebaseIdentity('test-project', static fn() => ['test-key' => $publicKey]);
$claims = ['sub' => 'alice', 'aud' => 'test-project', 'iss' => 'https://securetoken.google.com/test-project',
    'iat' => time() - 10, 'exp' => time() + 1000, 'auth_time' => time() - 20,
    'name' => 'Alice', 'firebase' => ['sign_in_provider' => 'google.com']];
$encode = static fn(array $payload): string => JWT::encode($payload, $privateKey, 'RS256', 'test-key');
check($verifier->verify($encode($claims))['uid'] === 'alice', 'Valid signed identity rejected.');
check($verifier->verify($encode($claims))['auth_time'] === $claims['auth_time'], 'Verified login time not retained.');
check($verifier->verify($encode(array_replace($claims, ['firebase' => ['sign_in_provider' => 'password']])))['uid'] === 'alice', 'Email identity rejected.');
foreach ([['aud', 'another-project'], ['iss', 'https://attacker.example'], ['sub', ''],
    ['sub', str_repeat('x', 129)], ['exp', time() - 1], ['iat', time() + 100],
    ['auth_time', time() + 100], ['auth_time', -1], ['firebase', ['sign_in_provider' => 'anonymous']]] as [$claim, $value]) {
    rejected(static fn() => $verifier->verify($encode(array_replace($claims, [$claim => $value]))), 401);
}
foreach (['exp', 'iat', 'auth_time', 'sub'] as $claim) {
    $invalid = $claims; unset($invalid[$claim]);
    rejected(static fn() => $verifier->verify($encode($invalid)), 401);
}
$token = $encode($claims);
$parts = explode('.', $token);
$parts[1] = JWT::urlsafeB64Encode(json_encode(array_replace($claims, ['sub' => 'attacker'])));
rejected(static fn() => $verifier->verify(implode('.', $parts)), 401);
rejected(static fn() => $verifier->verify(JWT::encode($claims, str_repeat('x', 64), 'HS256', 'test-key')), 401);
rejected(static fn() => $verifier->verify(JWT::encode($claims, $privateKey, 'RS256', 'unknown-key')), 401);
rejected(static fn() => $verifier->verify('invalid'), 401);
rejected(static fn() => (new FirebaseIdentity('', static fn() => []))->verify($token), 503);

echo "$count signed identity checks passed.\n";
