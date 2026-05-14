<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metoda niedozwolona']);
    exit;
}

// Honeypot — bot wypełni ukryte pole, prawdziwy użytkownik nie
if (!empty($_POST['_honey'])) {
    echo json_encode(['success' => true]);
    exit;
}

$cfg = require __DIR__ . '/smtp_config.php';

$name    = trim($_POST['name']    ?? '');
$contact = trim($_POST['contact'] ?? '');
$topic   = trim($_POST['topic']   ?? '');
$message = trim($_POST['message'] ?? '');

if ($name === '' || $contact === '' || $topic === '') {
    http_response_code(400);
    $missing = [];
    if ($name === '')    $missing[] = 'name';
    if ($contact === '') $missing[] = 'contact';
    if ($topic === '')   $missing[] = 'topic';
    echo json_encode([
        'success' => false,
        'message' => 'Uzupełnij wymagane pola.',
        'debug'   => ['missing' => $missing, 'received_keys' => array_keys($_POST)],
    ]);
    exit;
}

if (strlen($name) > 200 || strlen($contact) > 200 || strlen($topic) > 200 || strlen($message) > 5000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Zbyt długa zawartość pola.']);
    exit;
}

// Blokada nagłówkowych prób injection
foreach ([$name, $contact, $topic] as $v) {
    if (preg_match('/[\r\n]/', $v)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nieprawidłowe dane.']);
        exit;
    }
}

$subjectRaw = 'Nowa wiadomość ze strony AllesKlarService — ' . $topic;
$subject    = '=?UTF-8?B?' . base64_encode($subjectRaw) . '?=';

$body  = "Nowa wiadomość z formularza kontaktowego AllesKlarService\r\n";
$body .= "------------------------------------------------------------\r\n\r\n";
$body .= "Imię i nazwisko:    {$name}\r\n";
$body .= "Telefon lub e-mail: {$contact}\r\n";
$body .= "Temat:              {$topic}\r\n\r\n";
$body .= "Wiadomość:\r\n{$message}\r\n\r\n";
$body .= "------------------------------------------------------------\r\n";
$body .= 'Wysłano: ' . date('Y-m-d H:i:s') . "\r\n";
$body .= 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'n/d') . "\r\n";

$replyTo  = filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : $cfg['from'];
$fromName = '=?UTF-8?B?' . base64_encode($cfg['fromName']) . '?=';

$headers = [
    'Date'                      => date('r'),
    'From'                      => "{$fromName} <{$cfg['from']}>",
    'To'                        => $cfg['to'],
    'Reply-To'                  => $replyTo,
    'Subject'                   => $subject,
    'Message-ID'                => '<' . bin2hex(random_bytes(8)) . '@' . preg_replace('/[^a-z0-9.\-]/i', '', explode('@', $cfg['from'])[1]) . '>',
    'MIME-Version'              => '1.0',
    'Content-Type'              => 'text/plain; charset=UTF-8',
    'Content-Transfer-Encoding' => '8bit',
    'X-Mailer'                  => 'AllesKlar SMTP/PHP ' . phpversion(),
];

try {
    smtp_send($cfg, $cfg['from'], [$cfg['to']], $headers, $body);
    echo json_encode(['success' => true, 'message' => 'Wiadomość wysłana']);
} catch (Throwable $e) {
    error_log('[send.php] SMTP error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Nie udało się wysłać wiadomości.',
        'debug'   => $e->getMessage(),
    ]);
}

// ---------------------------------------------------------------------------
// Minimalny klient SMTP (AUTH LOGIN, SSL/TLS). Bez zewnętrznych zależności.
// ---------------------------------------------------------------------------
function smtp_send(array $cfg, string $from, array $rcpts, array $headers, string $body): void
{
    $scheme = ($cfg['secure'] === 'ssl') ? 'ssl://' : '';
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        $scheme . $cfg['host'] . ':' . $cfg['port'],
        $errno,
        $errstr,
        $cfg['timeout'],
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
    );
    if (!$fp) {
        throw new RuntimeException("Connect failed: {$errstr} ({$errno})");
    }
    stream_set_timeout($fp, $cfg['timeout']);

    smtp_expect($fp, 220);

    $ehloHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
    smtp_cmd($fp, "EHLO {$ehloHost}");
    smtp_expect($fp, 250);

    if ($cfg['secure'] === 'tls') {
        smtp_cmd($fp, 'STARTTLS');
        smtp_expect($fp, 220);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS handshake failed');
        }
        smtp_cmd($fp, "EHLO {$ehloHost}");
        smtp_expect($fp, 250);
    }

    smtp_cmd($fp, 'AUTH LOGIN');
    smtp_expect($fp, 334);
    smtp_cmd($fp, base64_encode($cfg['username']));
    smtp_expect($fp, 334);
    smtp_cmd($fp, base64_encode($cfg['password']));
    smtp_expect($fp, 235);

    smtp_cmd($fp, "MAIL FROM:<{$from}>");
    smtp_expect($fp, 250);

    foreach ($rcpts as $rcpt) {
        smtp_cmd($fp, "RCPT TO:<{$rcpt}>");
        smtp_expect($fp, [250, 251]);
    }

    smtp_cmd($fp, 'DATA');
    smtp_expect($fp, 354);

    $data = '';
    foreach ($headers as $k => $v) {
        $data .= "{$k}: {$v}\r\n";
    }
    $data .= "\r\n";

    // Dot-stuffing: linie zaczynające się od kropki
    $bodyNorm = preg_replace("/\r\n|\r|\n/", "\r\n", $body);
    $bodyNorm = preg_replace('/^\./m', '..', $bodyNorm);
    $data .= $bodyNorm;
    if (substr($data, -2) !== "\r\n") {
        $data .= "\r\n";
    }
    $data .= ".\r\n";

    fwrite($fp, $data);
    smtp_expect($fp, 250);

    smtp_cmd($fp, 'QUIT');
    fclose($fp);
}

function smtp_cmd($fp, string $line): void
{
    fwrite($fp, $line . "\r\n");
}

function smtp_expect($fp, $expected): void
{
    $expected = (array) $expected;
    $response = '';
    while (!feof($fp)) {
        $line = fgets($fp, 8192);
        if ($line === false) {
            $info = stream_get_meta_data($fp);
            throw new RuntimeException($info['timed_out'] ? 'SMTP timeout' : 'SMTP read error');
        }
        $response .= $line;
        // Wieloliniowa odpowiedź: kod-... continuation, kod  ostatnia
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('SMTP unexpected response: ' . trim($response));
    }
}
