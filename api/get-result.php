<?php
/**
 * Endpoint: po powrocie z Stripe Checkout zwraca wynik.
 *
 * Frontend wywołuje to z parametrem ?session_id={CHECKOUT_SESSION_ID}
 * (placeholder podstawiany przez Stripe). Backend:
 *   1. Weryfikuje session_id w Stripe API
 *   2. Sprawdza payment_status === 'paid'
 *   3. Wyciąga dane formularza z metadata
 *   4. Liczy wynik (lib/calculator.php)
 *   5. Wywołuje Claude API z danymi + wynikiem żeby wygenerować
 *      spersonalizowany komentarz po polsku
 *   6. Zwraca JSON {success, wohngeld, kinderzuschlag, meta, ai_comment}
 *
 * Brak płatności = 402 Payment Required. Bez backdoorów.
 */

require_once __DIR__ . '/lib/calculator.php';
require_once __DIR__ . '/lib/stripe.php';
require_once __DIR__ . '/lib/claude.php';

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
    error_log('[get-result] Stripe verify failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Nie udało się zweryfikować płatności w Stripe.']);
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

// Wyciągamy dane wejściowe z metadata i normalizujemy
$meta = $session['metadata'] ?? [];
$input = [
    'sytuacja'      => $meta['sytuacja']      ?? '',
    'mieszkanie'    => $meta['mieszkanie']    ?? '',
    'czynsz'        => (float)($meta['czynsz']     ?? 0),
    'region_typ'    => $meta['region_typ']    ?? 'srednie',
    'miasto_nazwa'  => $meta['miasto_nazwa']  ?? '',
    'dorosli'       => (int)($meta['dorosli']      ?? 1),
    'liczba_dzieci' => (int)($meta['liczba_dzieci']?? 0),
    'kindergeld'    => ($meta['kindergeld']   ?? '0') === '1',
    'dzieci_de'     => $meta['dzieci_de']     ?? 'de',
    'dochod_1'      => (float)($meta['dochod_1']   ?? 0),
    'dochod_2'      => (float)($meta['dochod_2']   ?? 0),
    'dochod_inne'   => (float)($meta['dochod_inne']?? 0),
    'buergergeld'   => ($meta['buergergeld']  ?? '0') === '1',
];

$result = calculate_eligibility($input);

// AI komentarz przez Claude — jeśli klucz dostępny i wywołanie się powiedzie.
// Jeśli nie — fallback do lokalnego szablonu, żeby klient zawsze coś dostał.
$result['ai_comment'] = null;
if (!empty($keys['anthropic_api_key'])) {
    try {
        $result['ai_comment'] = claude_generate_comment(
            $keys['anthropic_api_key'],
            $input,
            $result
        );
    } catch (Throwable $e) {
        error_log('[get-result] Claude error: ' . $e->getMessage());
        // Nie wywalamy całego endpointa — wynik bez AI też ma wartość
    }
}

$result['success'] = true;
$result['session_id'] = $sessionId;
$result['amount_paid'] = ($session['amount_total'] ?? 0) / 100;
$result['currency'] = strtoupper($session['currency'] ?? 'eur');

echo json_encode($result, JSON_UNESCAPED_UNICODE);
