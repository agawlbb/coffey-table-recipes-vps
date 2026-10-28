<?php
declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class FirebaseIdentity
{
    private $keyProvider;

    public function __construct(private string $projectId, callable $keyProvider)
    {
        $this->keyProvider = $keyProvider;
    }

    public function verify(string $token): array
    {
        if ($this->projectId === '') {
            throw new HttpError(503, 'auth_not_configured', 'Sign-in is not configured on the server.');
        }
        if (strlen($token) > 16384 || count(explode('.', $token)) !== 3) {
            throw new HttpError(401, 'invalid_token', 'Please sign in again.');
        }
        try {
            $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256'
                || !is_string($header['kid'] ?? null) || strlen($header['kid']) > 200) {
                throw new UnexpectedValueException('Unsupported token header.');
            }
        } catch (Throwable $error) {
            throw new HttpError(401, 'invalid_token', 'Please sign in again.');
        }
        $keys = ($this->keyProvider)();
        if (!isset($keys[$header['kid']])) {
            throw new HttpError(401, 'invalid_token', 'Please sign in again.');
        }
        try {
            $claims = (array) JWT::decode($token, new Key($keys[$header['kid']], 'RS256'));
            $now = time();
            if (($claims['aud'] ?? null) !== $this->projectId
                || ($claims['iss'] ?? null) !== 'https://securetoken.google.com/' . $this->projectId
                || !is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 128
                || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
                || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now
                || !is_int($claims['auth_time'] ?? null) || $claims['auth_time'] > $now
                || $claims['auth_time'] < 0 || $claims['iat'] < $claims['auth_time']
                || $claims['exp'] <= $claims['iat']) {
                throw new UnexpectedValueException('Invalid Firebase claims.');
            }
            $firebase = (array) ($claims['firebase'] ?? []);
            if (!in_array($firebase['sign_in_provider'] ?? '', ['google.com', 'password'], true)) {
                throw new UnexpectedValueException('Supported sign-in provider is required.');
            }
            $email = is_string($claims['email'] ?? null) ? strtolower(trim($claims['email'])) : null;
            if ($email !== null && (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) $email = null;
            return ['uid' => $claims['sub'], 'name' => is_string($claims['name'] ?? null)
                ? mb_substr($claims['name'], 0, 200) : null,
                'auth_time' => $claims['auth_time'], 'email' => $email, 'email_verified' => ($claims['email_verified'] ?? false) === true];
        } catch (Throwable $error) {
            throw new HttpError(401, 'invalid_token', 'Please sign in again.');
        }
    }
}

function firebasePublicKeys(): array
{
    $cachePath = CTR_STORAGE . '/firebase-public-keys.json';
    $cache = is_file($cachePath) ? json_decode(file_get_contents($cachePath), true) : null;
    if (is_array($cache) && ($cache['expires_at'] ?? 0) > time() && is_array($cache['keys'] ?? null)) {
        return $cache['keys'];
    }
    $maxAge = 300;
    $curl = curl_init('https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$maxAge): int {
            if (preg_match('/^cache-control:.*max-age=(\d+)/i', $header, $matches)) $maxAge = (int) $matches[1];
            return strlen($header);
        },
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $keys = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($keys) || $keys === []) {
        throw new HttpError(503, 'identity_service_unavailable', 'Google verification is temporarily unavailable. Please retry.');
    }
    foreach ($keys as $key) {
        if (!is_string($key) || !str_contains($key, 'BEGIN CERTIFICATE')) {
            throw new HttpError(503, 'identity_service_unavailable', 'Google verification is temporarily unavailable. Please retry.');
        }
    }
    // Write then rename so concurrent readers never receive partial JSON.
    $temporary = tempnam(dirname($cachePath), 'keys-');
    if ($temporary === false) throw new RuntimeException('Cannot write public-key cache.');
    file_put_contents($temporary, json_encode(['expires_at' => time() + min($maxAge, 86400), 'keys' => $keys], JSON_THROW_ON_ERROR));
    if (!rename($temporary, $cachePath)) { unlink($temporary); throw new RuntimeException('Cannot publish public-key cache.'); }
    return $keys;
}
