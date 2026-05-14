<?php
/**
 * Endpoint: po powrocie z Stripe (service payment) zwraca podsumowanie.
 *
 * Używany przez dziekujemy.html — pokazuje klientowi co kupił i kwotę.
 * Brak Claude, brak liczeń — to tylko potwierdzenie zamówienia.
 */

require_once __DIR__ . '/lib/stripe.php';

header('Content-Type: application/json; charset=utf-8');

$sessionId = (string)($_GET['session_id'] ?? '');
if ($sessionId === '' || !preg_match('/^cs_(test|live)_[a-zA-Z0-9]+$/', $sessionId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Brak lub nieprawidłowy session_id.']);
    exit;
}

$keys = require __DIR__ . '/api_keys.php';
if (empty($keys['stripe_secret_key'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Konfiguracja Stripe nie jest gotowa.']);
    exit;
}

try {
    $session = stripe_get('/v1/checkout/sessions/' . $sessionId, $keys['stripe_secret_key']);
} catch (Throwable $e) {
    error_log('[get-order] Stripe verify failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Nie udało się zweryfikować płatności.']);
    exit;
}

if (($session['payment_status'] ?? '') !== 'paid') {
    http_response_code(402);
    echo json_encode([
        'success' => false,
        'message' => 'Płatność nie została potwierdzona.',
        'payment_status' => $session['payment_status'] ?? 'unknown',
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'session_id' => $sessionId,
    'amount_paid' => ($session['amount_total'] ?? 0) / 100,
    'currency' => strtoupper($session['currency'] ?? 'eur'),
    'email' => $session['customer_details']['email'] ?? ($session['customer_email'] ?? ''),
    'service' => $session['metadata']['service'] ?? '',
    'service_name' => $session['metadata']['service_name'] ?? '',
], JSON_UNESCAPED_UNICODE);
