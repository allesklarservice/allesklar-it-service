<?php
/**
 * Minimalny klient Stripe API przez cURL.
 *
 * Bez Composera, bez SDK. Stripe API używa form-encoded body (NIE JSON)
 * dla zapytań POST i GET — to historyczna decyzja Stripe, nie pomyłka.
 */

function stripe_request(string $method, string $path, array $params, string $secretKey): array
{
    $ch = curl_init();
    $url = 'https://api.stripe.com' . $path;

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secretKey,
        'Stripe-Version: 2024-06-20',
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    } elseif (strtoupper($method) === 'GET' && !empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    curl_setopt($ch, CURLOPT_URL, $url);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $errstr = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException("cURL error ({$errno}): {$errstr}");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Stripe: nieprawidłowa odpowiedź HTTP {$httpCode}: " . substr((string)$body, 0, 200));
    }

    if ($httpCode >= 400) {
        $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
        throw new RuntimeException("Stripe API: {$msg}");
    }

    return $data;
}

function stripe_post(string $path, array $params, string $secretKey): array
{
    return stripe_request('POST', $path, $params, $secretKey);
}

function stripe_get(string $path, string $secretKey, array $query = []): array
{
    return stripe_request('GET', $path, $query, $secretKey);
}
