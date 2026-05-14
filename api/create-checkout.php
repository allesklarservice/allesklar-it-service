<?php
/**
 * Endpoint: tworzy Stripe Checkout Session za 4,99 €.
 *
 * Frontend wysyła dane formularza JSON-em. Backend sanityzuje, zapisuje
 * je do Stripe metadata (limit 500 znaków na klucz — surowe input mieści się),
 * tworzy Session i zwraca URL do Stripe Checkout. Frontend robi window.location.
 *
 * Wynik (procenty, kwoty, komentarz Claude) NIE jest liczony tutaj — dopiero
 * w get-result.php po potwierdzeniu płatności. To bezpieczne: nawet jeśli
 * ktoś manipuluje request, bez zapłacenia nie dostanie wyniku.
 */

require_once __DIR__ . '/lib/calculator.php';
require_once __DIR__ . '/lib/stripe.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metoda niedozwolona']);
    exit;
}

$keys = require __DIR__ . '/api_keys.php';
if (empty($keys['stripe_secret_key'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Konfiguracja Stripe nie jest gotowa (brak klucza).']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nieprawidłowe dane wejściowe (brak JSON).']);
    exit;
}

$clean = sanitize_input($input);

// Stripe Checkout Session
$payload = [
    'mode' => 'payment',
    'payment_method_types[]' => 'card',
    'line_items[0][price_data][currency]' => $keys['stripe_currency'] ?? 'eur',
    'line_items[0][price_data][product_data][name]' => 'Analiza AI: Wohngeld + Kinderzuschlag',
    'line_items[0][price_data][product_data][description]' => 'Spersonalizowany raport AI po polsku, stan prawny 2026',
    'line_items[0][price_data][unit_amount]' => (int)($keys['stripe_price_amount'] ?? 499),
    'line_items[0][quantity]' => 1,
    'success_url' => ($keys['site_url'] ?? 'https://allesklar-it-service.de')
                     . '/kalkulator.html?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'  => ($keys['site_url'] ?? 'https://allesklar-it-service.de')
                     . '/kalkulator.html?canceled=1',
    'locale' => 'pl',
];

// E-mail klienta (jeśli wpisał w formularzu) — Stripe przefiltruje do faktury
if (!empty($clean['email']) && filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) {
    $payload['customer_email'] = $clean['email'];
}

// Zapis surowych danych użytkownika w metadata. Stripe limit: max 500 znaków
// na klucz. Boole konwertujemy na '1'/'0' bo Stripe oczekuje string.
foreach ($clean as $k => $v) {
    if (is_bool($v)) $v = $v ? '1' : '0';
    $payload['metadata[' . $k . ']'] = (string)$v;
}

try {
    $response = stripe_post('/v1/checkout/sessions', $payload, $keys['stripe_secret_key']);
    echo json_encode([
        'success' => true,
        'url' => $response['url'],
        'session_id' => $response['id'],
    ]);
} catch (Throwable $e) {
    error_log('[create-checkout] Stripe error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Nie udało się utworzyć sesji płatności.',
        'debug' => $e->getMessage(),
    ]);
}
