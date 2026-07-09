<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';

/**
 * Standard browser Web Push (RFC 8291 message encryption + RFC 8292 VAPID),
 * implemented with PHP's built-in openssl/hash extensions only — there's no
 * Composer in this project, so a library like minishlink/web-push-php isn't
 * installable.
 */

function webpush_b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webpush_b64url_decode(string $data): string
{
    $padded = str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=');
    return base64_decode(strtr($padded, '-_', '+/'));
}

/**
 * Builds an openssl public key resource for a raw uncompressed P-256 point
 * (0x04 || X(32) || Y(32), 65 bytes) — the format browsers send as the
 * subscription's `p256dh` key. Wraps it in the fixed SubjectPublicKeyInfo
 * DER prefix for id-ecPublicKey + prime256v1.
 */
function webpush_ec_public_key_resource(string $rawPoint)
{
    $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($prefix . $rawPoint), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

/**
 * Returns [publicKeyB64Url, privateKeyPem], generating and persisting a
 * VAPID EC keypair the first time this is called. The private key is
 * stored as a full PEM (from openssl_pkey_export) rather than a raw
 * scalar, so it can be reloaded with openssl_pkey_get_private() directly
 * with no hand-rolled ASN.1 on the private-key side.
 */
function webpush_vapid_keypair(): array
{
    $publicKey = (string) get_setting('vapid_public_key');
    $privatePem = (string) get_setting('vapid_private_key_pem');

    if ($publicKey !== '' && $privatePem !== '') {
        return [$publicKey, $privatePem];
    }

    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($res === false) {
        throw new RuntimeException('Failed to generate VAPID keypair.');
    }

    $details = openssl_pkey_get_details($res);
    $publicKey = webpush_b64url_encode("\x04" . $details['ec']['x'] . $details['ec']['y']);

    openssl_pkey_export($res, $privatePem);

    update_settings(['vapid_public_key' => $publicKey, 'vapid_private_key_pem' => $privatePem]);

    return [$publicKey, $privatePem];
}

function get_vapid_public_key(): string
{
    [$publicKey, ] = webpush_vapid_keypair();
    return $publicKey;
}

/**
 * Converts an ASN.1 DER ECDSA-Sig-Value (SEQUENCE of two INTEGERs) into
 * the raw r||s format (64 bytes) required by JOSE/JWS ES256 signatures.
 */
function webpush_der_int_to_fixed(string $bytes, int $size = 32): string
{
    if (strlen($bytes) > $size && ord($bytes[0]) === 0) {
        $bytes = substr($bytes, 1);
    }
    return str_pad($bytes, $size, "\x00", STR_PAD_LEFT);
}

function webpush_der_to_raw_signature(string $der): string
{
    $offset = 3; // SEQUENCE tag+length (short-form) + INTEGER tag for r
    $rLen = ord($der[$offset]);
    $offset += 1;
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;

    $offset += 1; // INTEGER tag for s
    $sLen = ord($der[$offset]);
    $offset += 1;
    $s = substr($der, $offset, $sLen);

    return webpush_der_int_to_fixed($r) . webpush_der_int_to_fixed($s);
}

/**
 * Builds a VAPID JWT (ES256) for the given push service origin.
 */
function webpush_vapid_jwt(string $audience, string $privatePem): string
{
    $header = webpush_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
    $payload = webpush_b64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => 'mailto:admin@' . parse_url($audience, PHP_URL_HOST),
    ], JSON_UNESCAPED_SLASHES));

    $signingInput = $header . '.' . $payload;

    $privRes = openssl_pkey_get_private($privatePem);
    if ($privRes === false) {
        throw new RuntimeException('Failed to load VAPID private key.');
    }
    $signed = openssl_sign($signingInput, $derSignature, $privRes, OPENSSL_ALGO_SHA256);
    if ($signed === false) {
        throw new RuntimeException('VAPID JWT signing failed.');
    }

    return $signingInput . '.' . webpush_b64url_encode(webpush_der_to_raw_signature($derSignature));
}

/**
 * Derives the content-encryption key and nonce per RFC 8291 section 3.4,
 * given the ECDH shared secret, the subscription's auth secret, this
 * message's random salt, and both parties' raw public key points.
 *
 * @return array{0: string, 1: string} [cek (16 bytes), nonce (12 bytes)]
 */
function webpush_derive_content_encryption_key(string $ecdhSecret, string $authSecret, string $salt, string $uaPublic, string $asPublic): array
{
    $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
    $ikm = hash_hkdf('sha256', $ecdhSecret, 32, $keyInfo, $authSecret);

    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    return [$cek, $nonce];
}

/**
 * Sends one Web Push message to one subscription. Returns
 * ['ok' => bool, 'error' => ?string, 'gone' => bool] — 'gone' is true on a
 * 404/410 from the push service, meaning the caller should delete the
 * subscription (device unsubscribed or expired).
 */
function send_web_push(array $subscription, array $payload): array
{
    try {
        [$vapidPublic, $vapidPrivatePem] = webpush_vapid_keypair();

        $endpoint = $subscription['endpoint'];
        $uaPublic = webpush_b64url_decode($subscription['p256dh']);
        $authSecret = webpush_b64url_decode($subscription['auth']);

        $asRes = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($asRes === false) {
            return ['ok' => false, 'error' => 'Failed to generate ephemeral key.', 'gone' => false];
        }
        $asDetails = openssl_pkey_get_details($asRes);
        $asPublic = "\x04" . $asDetails['ec']['x'] . $asDetails['ec']['y'];

        $uaPubRes = webpush_ec_public_key_resource($uaPublic);
        $sharedSecret = openssl_pkey_derive($uaPubRes, $asRes, 32);
        if ($sharedSecret === false) {
            return ['ok' => false, 'error' => 'ECDH key derivation failed.', 'gone' => false];
        }

        $salt = random_bytes(16);
        [$cek, $nonce] = webpush_derive_content_encryption_key($sharedSecret, $authSecret, $salt, $uaPublic, $asPublic);

        $plaintext = json_encode($payload, JSON_UNESCAPED_UNICODE) . "\x02";
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            return ['ok' => false, 'error' => 'Payload encryption failed.', 'gone' => false];
        }

        $recordSize = 4096;
        $body = $salt . pack('N', $recordSize) . chr(strlen($asPublic)) . $asPublic . $ciphertext . $tag;

        $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $jwt = webpush_vapid_jwt($audience, $vapidPrivatePem);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 60',
            'Authorization: vapid t=' . $jwt . ', k=' . $vapidPublic,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 404 || $httpCode === 410) {
            return ['ok' => false, 'error' => 'Subscription expired.', 'gone' => true];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'error' => $curlError !== '' ? $curlError : "Push service returned HTTP {$httpCode}.", 'gone' => false];
        }

        return ['ok' => true, 'error' => null, 'gone' => false];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'gone' => false];
    }
}

function add_push_subscription(int $userId, string $endpoint, string $p256dh, string $auth): void
{
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, created_at) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth)'
    );
    $stmt->execute([$userId, $endpoint, $p256dh, $auth, time()]);
}

function remove_push_subscription(string $endpoint): void
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
    $stmt->execute([$endpoint]);
}

function get_push_subscriptions_for_user(int $userId): array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT * FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
