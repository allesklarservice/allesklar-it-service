<?php
/**
 * Endpoint: tworzy Stripe Checkout dla usług (konsultacja / wnioski).
 *
 * Frontend wysyła POST z polem `service` (whitelist). Cena, nazwa, opis są
 * ustalane PO STRONIE SERWERA na podstawie service ID — żeby klient nie mógł
 * w DevToolsach zmienić ceny.
 *
 * Po zapłaceniu Stripe wraca na /dziekujemy.html?session_id=... gdzie
 * pokazujemy potwierdzenie i info kiedy się skontaktujemy.
 */

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
    echo json_encode(['success' => false, 'message' => 'Konfiguracja Stripe nie jest gotowa.']);
    exit;
}

// Whitelista usług. Cena/nazwa/opis tutaj — nie do modyfikacji z frontu.
const SERVICES = [
    'konsultacja' => [
        'name' => 'Konsultacja po polsku (AllesKlarService)',
        'description' => '30-minutowa rozmowa: pytania, plan działania, weryfikacja dokumentów',
        'amount' => 2500, // 25,00 €
    ],
    'wniosek_wohngeld' => [
        'name' => 'Wniosek o Wohngeld (AllesKlarService)',
        'description' => 'Wypełnienie i złożenie wniosku o dopłatę do mieszkania',
        'amount' => 5000, // 50,00 €
    ],
    'wniosek_kinderzuschlag' => [
        'name' => 'Wniosek o Kinderzuschlag (AllesKlarService)',
        'description' => 'Wypełnienie i złożenie wniosku o dodatek na dzieci',
        'amount' => 5000, // 50,00 €
    ],
    'wnioski_oba' => [
        'name' => 'Pakiet: Wohngeld + Kinderzuschlag (AllesKlarService)',
        'description' => 'Oba wnioski w jednym pakiecie — oszczędność 20 €',
        'amount' => 8000, // 80,00 €
    ],
    'kindergeld' => [
        'name' => 'Wniosek o Kindergeld (AllesKlarService)',
        'description' => 'Wypełnienie i złożenie wniosku o świadczenie rodzinne',
        'amount' => 5000, // 50,00 €
    ],
    'wynajem_auta' => [
        'name' => 'Wynajem Auta w Niemczech — sprawdzone firmy i kod (AllesKlarService)',
        'description' => 'Po płatności otrzymasz nazwy 2 sprawdzonych firm + kod rabatowy dający szansę na 100 € bonusu',
        'amount' => 1000, // 10,00 €
    ],
];

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$serviceId = trim((string)($input['service'] ?? ''));

if (!isset(SERVICES[$serviceId])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Nieznana usługa.',
        'allowed' => array_keys(SERVICES),
    ]);
    exit;
}

$service = SERVICES[$serviceId];
$email = trim((string)($input['email'] ?? ''));

$payload = [
    'mode' => 'payment',
    'payment_method_types[]' => 'card',
    'line_items[0][price_data][currency]' => $keys['stripe_currency'] ?? 'eur',
    'line_items[0][price_data][product_data][name]' => $service['name'],
    'line_items[0][price_data][product_data][description]' => $service['description'],
    'line_items[0][price_data][unit_amount]' => $service['amount'],
    'line_items[0][quantity]' => 1,
    'success_url' => ($keys['site_url'] ?? 'https://allesklar-it-service.de')
                     . '/dziekujemy.html?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'  => ($keys['site_url'] ?? 'https://allesklar-it-service.de')
                     . ($serviceId === 'wynajem_auta' ? '/wynajem-auta.html' : '/kalkulator.html'),
    'locale' => 'pl',
    'metadata[service]' => $serviceId,
    'metadata[service_name]' => $service['name'],
];

// Zachęcamy klienta do podania adresu zamówienia + telefonu — bardzo przyda się
// żebyśmy mogli go potem złapać i pomóc z dokumentami
$payload['phone_number_collection[enabled]'] = 'true';
$payload['billing_address_collection'] = 'auto';

if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $payload['customer_email'] = $email;
}

try {
    $response = stripe_post('/v1/checkout/sessions', $payload, $keys['stripe_secret_key']);
    echo json_encode([
        'success' => true,
        'url' => $response['url'],
        'session_id' => $response['id'],
        'service' => $serviceId,
    ]);
} catch (Throwable $e) {
    error_log('[create-service-checkout] Stripe error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Nie udało się utworzyć sesji płatności.',
        'debug' => $e->getMessage(),
    ]);
}
