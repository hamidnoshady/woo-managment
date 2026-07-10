<?php

require_once __DIR__ . '/Settings.php';

/**
 * Sends a plain (non-OTP) SMS via Kavenegar's Send API, using a separate
 * API key/sender line from the OTP flow in auth.php (send_kavenegar_otp()).
 */
function send_kavenegar_sms(string $phone, string $message): array
{
    $apiKey = (string) get_setting('kavenegar_sms_api_key');
    $sender = (string) get_setting('kavenegar_sms_sender');

    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'Kavenegar SMS API key is not configured.'];
    }

    $url = sprintf('https://api.kavenegar.com/v1/%s/sms/send.json', rawurlencode($apiKey));

    $params = [
        'receptor' => $phone,
        'message'  => $message,
    ];
    if ($sender !== '') {
        $params['sender'] = $sender;
    }

    $ch = curl_init($url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'SMS provider error: ' . $curlError];
    }

    $data = json_decode($response, true);
    $status = $data['return']['status'] ?? 0;

    if ($httpCode !== 200 || $status !== 200) {
        $message = $data['return']['message'] ?? 'Unknown error from SMS provider.';
        return ['ok' => false, 'error' => $message];
    }

    return ['ok' => true];
}
