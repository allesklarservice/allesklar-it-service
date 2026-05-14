<?php
/**
 * Klient Claude API (Anthropic) — wrapper na cURL.
 *
 * Bez SDK, żeby uniknąć Composera na hostingu PHP. Używamy modelu Claude Haiku
 * (najtańszy, wystarcza do generowania krótkich komentarzy po polsku).
 *
 * System prompt jest dopasowany do roli "doradcy AllesKlarService" — model
 * dostaje twardo policzony wynik i ma TYLKO opisać go po polsku, NIE liczyć
 * od nowa (bo modele myli się w progach prawa socjalnego).
 */

const CLAUDE_MODEL = 'claude-haiku-4-5-20251001';
const CLAUDE_MAX_TOKENS = 1200;

function claude_generate_comment(string $apiKey, array $input, array $result): string
{
    $systemPrompt = "Jesteś doradcą AllesKlarService — firmy pomagającej Polakom mieszkającym w Niemczech "
        . "załatwiać niemieckie świadczenia socjalne (Wohngeld, Kinderzuschlag, Kindergeld, BuT).\n\n"
        . "Twoja rola: dostajesz POLICZONY wynik szansy na Wohngeld i Kinderzuschlag oraz dane "
        . "wejściowe klienta. Twoim zadaniem jest napisać krótki, ciepły komentarz po polsku, "
        . "tłumaczący wynik 'jak Kowalskiemu' — bez urzędniczego żargonu.\n\n"
        . "Zasady:\n"
        . "- NIE zmieniaj liczb. Procenty i kwoty są twardo policzone na podstawie reguł 2026.\n"
        . "- NIE pisz wartości, których nie ma w danych wejściowych ani wyniku.\n"
        . "- Pisz po polsku, naturalnie, z empatią. Maks 4-5 krótkich akapitów.\n"
        . "- Używaj emoji jednolicie: 🟢 dla wysokiej szansy, 🟡 dla średniej, 🔴 dla niskiej.\n"
        . "- Zawsze przypomnij: ostateczną decyzję wydaje urząd.\n"
        . "- Na końcu konkretne CTA: 'Zleć nam wniosek za 50 €' albo konsultacja 25 €.\n"
        . "- NIE używaj fraz typu 'jako asystent AI'. Mów w 1. osobie jak doradca.";

    $mietstufeUzyta = $result['meta']['mietstufe'] ?? '?';
    $regionInfo = $input['miasto_nazwa']
        ? "{$input['miasto_nazwa']} (kategoria: {$input['region_typ']}, Mietstufe {$mietstufeUzyta})"
        : ucfirst($input['region_typ']) . " (Mietstufe {$mietstufeUzyta})";

    $userPrompt = sprintf(
        "Oto dane klienta i wynik analizy. Napisz komentarz po polsku.\n\n"
        . "DANE KLIENTA:\n"
        . "- Sytuacja: %s\n"
        . "- Mieszkanie: %s, czynsz %s €/mies.\n"
        . "- Region: %s\n"
        . "- %d osób w gospodarstwie\n"
        . "- Dzieci: %d (Kindergeld: %s, mieszkają: %s)\n"
        . "- Dochód razem: %s €/mies. (z 1: %s, z 2: %s, inne: %s)\n"
        . "- Bürgergeld: %s\n\n"
        . "WYNIK:\n"
        . "- Wohngeld: %d%% szansy, szacowana kwota %d €/mies.\n"
        . "  Powody: %s\n"
        . "- Kinderzuschlag: %d%% szansy, szacowana kwota %d €/mies.\n"
        . "  Powody: %s\n",
        $input['sytuacja'] ?: '(brak)',
        $input['mieszkanie'] ?: '(brak)',
        $input['czynsz'],
        $regionInfo,
        $input['osoby'],
        $input['liczba_dzieci'],
        $input['kindergeld'] ? 'TAK' : 'NIE',
        $input['dzieci_de'],
        $input['dochod_1'] + $input['dochod_2'] + $input['dochod_inne'],
        $input['dochod_1'],
        $input['dochod_2'],
        $input['dochod_inne'],
        $input['buergergeld'] ? 'TAK' : 'NIE',
        $result['wohngeld']['chance'],
        $result['wohngeld']['amount'],
        implode('; ', $result['wohngeld']['reasons']) ?: 'brak',
        $result['kinderzuschlag']['chance'],
        $result['kinderzuschlag']['amount'],
        implode('; ', $result['kinderzuschlag']['reasons']) ?: 'brak'
    );

    $body = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => CLAUDE_MAX_TOKENS,
        'system' => $systemPrompt,
        'messages' => [
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $errstr = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException("Claude cURL error ({$errno}): {$errstr}");
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException("Claude: nieprawidłowy JSON (HTTP {$httpCode})");
    }
    if ($httpCode >= 400) {
        $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
        throw new RuntimeException("Claude API: {$msg}");
    }

    $text = $data['content'][0]['text'] ?? '';
    if ($text === '') {
        throw new RuntimeException("Claude: pusta odpowiedź");
    }

    return $text;
}
