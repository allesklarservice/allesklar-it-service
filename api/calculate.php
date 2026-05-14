<?php
/**
 * Endpoint: liczy szansę i kwotę Wohngeld/Kinderzuschlag dla danych z formularza.
 *
 * Używany przez iter. 1 (bez Stripe) — po dopięciu Stripe ten endpoint jest
 * używany przez get-result.php pośrednio (przez bibliotekę). Tu zostawiamy
 * jako standalone do testów lokalnych.
 */

require_once __DIR__ . '/lib/calculator.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metoda niedozwolona']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nieprawidłowe dane wejściowe (brak JSON).']);
    exit;
}

$result = calculate_eligibility($input);
$result['success'] = true;

echo json_encode($result, JSON_UNESCAPED_UNICODE);
