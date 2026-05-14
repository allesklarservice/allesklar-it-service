<?php
/**
 * Silnik kalkulatora Wohngeld + Kinderzuschlag 2026.
 *
 * Przyjmuje dane formularza jako POST JSON, zwraca JSON z procentową szansą
 * i szacowaną kwotą świadczenia. Reguły 2026 wyodrębnione w jeden blok
 * RULES_2026 — łatwo aktualizować rok do roku, gdy zmienia się prawo.
 *
 * WAŻNE: To są SZACUNKI. Ostateczną decyzję wydaje Wohngeldstelle /
 * Familienkasse po sprawdzeniu kompletu dokumentów. Klient widzi ten
 * disclaimer wyraźnie na ekranie wyniku w kalkulator.html.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metoda niedozwolona']);
    exit;
}

// ============================================================================
// REGUŁY 2026 — wartości pochodzą z waloryzacji Wohngeld 2026 i progów SGB II.
// AKTUALIZUJ TĘ SEKCJĘ rok do roku, gdy zmieniają się przepisy.
// ============================================================================
const RULES_2026 = [
    // Maksymalny czynsz uznawany przez urząd dla 1 osoby, wg Mietstufe (I-VII)
    // Wartości orientacyjne po waloryzacji 2026 (Höchstbeträge §12 WoGG).
    'mietstufen_max_rent_1osoba' => [
        1 => 384,
        2 => 432,
        3 => 491,
        4 => 558,
        5 => 628,
        6 => 716,
        7 => 818,
    ],
    // Dodatek na każdą kolejną osobę w gospodarstwie domowym (przybliżony procent
    // wzrostu uznanego czynszu).
    'osoba_dodatkowa_mnoznik' => 1.20,

    // Próg dochodu MINIMALNY dla Kinderzuschlag (musisz tyle zarabiać żeby
    // w ogóle wniosek miał sens — § 6a BKGG).
    'kiz_min_income_single' => 900,
    'kiz_min_income_para'   => 1300,
    // Maksymalna kwota Kinderzuschlag na 1 dziecko / miesiąc (2026).
    'kiz_max_per_child'     => 292,

    // Procent dochodu który urząd uznaje za "wolne" dla mieszkania.
    // (bardzo uproszczona heurystyka — realnie WoGG ma skomplikowane
    // odliczenia Freibeträge, ale dla MVP wystarczy).
    'wohngeld_income_burden_pct' => 0.30,

    // Wohngeld pokrywa max ~50% różnicy między uznanym czynszem
    // a "swoim wkładem" liczonym z dochodu.
    'wohngeld_coverage_pct' => 0.50,
];

// --- Walidacja wejścia ----------------------------------------------------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nieprawidłowe dane wejściowe (brak JSON).']);
    exit;
}

$sytuacja        = trim((string)($input['sytuacja'] ?? ''));        // single, para, rodzina, samotny, senior, student
$mieszkanie      = trim((string)($input['mieszkanie'] ?? ''));      // wynajem, wlasne, subnajem
$czynsz          = max(0, (float)($input['czynsz'] ?? 0));
$mietstufe       = max(1, min(7, (int)($input['mietstufe'] ?? 3)));
$osoby           = max(1, min(10, (int)($input['osoby'] ?? 1)));
$liczbaDzieci    = max(0, min(10, (int)($input['liczba_dzieci'] ?? 0)));
$dostajeKindergeld = !empty($input['kindergeld']);                  // true/false
$dzieciDe        = trim((string)($input['dzieci_de'] ?? 'de'));     // de, pl, mix
$dochod1         = max(0, (float)($input['dochod_1'] ?? 0));
$dochod2         = max(0, (float)($input['dochod_2'] ?? 0));
$dochodInne      = max(0, (float)($input['dochod_inne'] ?? 0));
$buergergeld     = !empty($input['buergergeld']);                   // true = wykluczenie

$dochodRazem = $dochod1 + $dochod2 + $dochodInne;
$czyPara = in_array($sytuacja, ['para', 'rodzina'], true);

// --- Wohngeld -------------------------------------------------------------
$wohngeld = [
    'chance'  => 0,
    'amount'  => 0,
    'reasons' => [],
];

if ($buergergeld) {
    $wohngeld['chance'] = 0;
    $wohngeld['reasons'][] = 'Bürgergeld wyklucza Wohngeld — nie można pobierać obu jednocześnie.';
} elseif ($mieszkanie === '' || $czynsz <= 0) {
    $wohngeld['chance'] = 0;
    $wohngeld['reasons'][] = 'Brak danych o mieszkaniu — bez czynszu nie da się oszacować.';
} elseif ($dochodRazem <= 0 && $sytuacja !== 'senior') {
    $wohngeld['chance'] = 10;
    $wohngeld['reasons'][] = 'Bez deklarowanego dochodu trudno oszacować — sprawdź czy nie kwalifikujesz się raczej do Bürgergeld.';
} else {
    // Maksymalny uznany czynsz dla danej Mietstufe i liczby osób
    $maxRent1 = RULES_2026['mietstufen_max_rent_1osoba'][$mietstufe] ?? 491;
    $maxRentTotal = $maxRent1 * pow(RULES_2026['osoba_dodatkowa_mnoznik'], max(0, $osoby - 1));
    $uznanyCzynsz = min($czynsz, $maxRentTotal);

    // Wkład własny z dochodu
    $wklad = $dochodRazem * RULES_2026['wohngeld_income_burden_pct'];

    if ($uznanyCzynsz <= $wklad) {
        $wohngeld['chance'] = 15;
        $wohngeld['amount'] = 0;
        $wohngeld['reasons'][] = 'Twój dochód jest na tyle wysoki, że według wstępnej formuły kwota Wohngeld wyszłaby zerowa lub bliska zera.';
    } else {
        $kwota = ($uznanyCzynsz - $wklad) * RULES_2026['wohngeld_coverage_pct'];
        $kwota = max(0, round($kwota, 0));
        $wohngeld['amount'] = (int)$kwota;

        // Heurystyka szansy — im wyższa policzona kwota, tym wyższa pewność
        if ($kwota >= 200) {
            $wohngeld['chance'] = 85;
        } elseif ($kwota >= 100) {
            $wohngeld['chance'] = 70;
        } elseif ($kwota >= 50) {
            $wohngeld['chance'] = 50;
        } else {
            $wohngeld['chance'] = 30;
        }
        $wohngeld['reasons'][] = "Uznany czynsz: " . round($uznanyCzynsz) . " € (z " . round($czynsz) . " €).";
        $wohngeld['reasons'][] = "Wkład z dochodu: ~" . round($wklad) . " € / mies.";
        if ($czynsz > $maxRentTotal) {
            $wohngeld['reasons'][] = "Uwaga: realny czynsz przekracza maksimum uznawane w Twojej Mietstufe — nadwyżki nie pokryje Wohngeld.";
        }
    }
}

// --- Kinderzuschlag -------------------------------------------------------
$kinderzuschlag = [
    'chance'  => 0,
    'amount'  => 0,
    'reasons' => [],
];

if ($liczbaDzieci === 0) {
    $kinderzuschlag['chance'] = 0;
    $kinderzuschlag['reasons'][] = 'Kinderzuschlag jest tylko dla rodzin z dziećmi.';
} elseif ($buergergeld) {
    $kinderzuschlag['chance'] = 0;
    $kinderzuschlag['reasons'][] = 'Bürgergeld wyklucza Kinderzuschlag — nie można pobierać razem.';
} elseif (!$dostajeKindergeld) {
    $kinderzuschlag['chance'] = 10;
    $kinderzuschlag['reasons'][] = 'Bez pobierania Kindergeld nie ma podstawy do Kinderzuschlag — najpierw złóż wniosek o Kindergeld.';
} elseif ($dzieciDe === 'pl') {
    $kinderzuschlag['chance'] = 5;
    $kinderzuschlag['reasons'][] = 'Dla dzieci mieszkających poza Niemcami Kinderzuschlag zwykle nie przysługuje (inaczej niż Kindergeld).';
} else {
    $minIncome = $czyPara ? RULES_2026['kiz_min_income_para'] : RULES_2026['kiz_min_income_single'];

    if ($dochodRazem < $minIncome) {
        $kinderzuschlag['chance'] = 20;
        $kinderzuschlag['reasons'][] = "Twój dochód ({$dochodRazem} €) jest poniżej minimum {$minIncome} € — Kinderzuschlag raczej odpadnie. Spójrz na Bürgergeld jako alternatywę.";
    } else {
        // Górny próg "wyłączający" — bardzo orientacyjnie:
        // minimum + maxKiz × dzieci + 60% dochodu nad minimum „znika" świadczenie
        $kwotaMax = RULES_2026['kiz_max_per_child'] * $liczbaDzieci;
        $hornaGrancia = $minIncome + $kwotaMax + ($dochodRazem - $minIncome) * 0.4;

        if ($dochodRazem > $hornaGrancia + 800) {
            $kinderzuschlag['chance'] = 25;
            $kinderzuschlag['amount'] = 0;
            $kinderzuschlag['reasons'][] = 'Twój dochód prawdopodobnie przekracza próg dla Kinderzuschlag.';
        } else {
            // Im bliżej minimum, tym wyższa szansa i wyższa kwota
            $zaleznosc = max(0, 1 - ($dochodRazem - $minIncome) / max(1, $hornaGrancia - $minIncome));
            $kwota = round($kwotaMax * (0.4 + 0.6 * $zaleznosc), 0);
            $kinderzuschlag['amount'] = (int)$kwota;

            if ($kwota >= ($kwotaMax * 0.7)) {
                $kinderzuschlag['chance'] = 80;
            } elseif ($kwota >= ($kwotaMax * 0.4)) {
                $kinderzuschlag['chance'] = 60;
            } else {
                $kinderzuschlag['chance'] = 40;
            }
            $kinderzuschlag['reasons'][] = "Próg minimalny ({$minIncome} €) spełniony.";
            $kinderzuschlag['reasons'][] = "Maksymalny Kinderzuschlag dla {$liczbaDzieci} dz. = " . (int)$kwotaMax . " €/mies.";
            $kinderzuschlag['reasons'][] = "Plus prawo do BuT (obiady szkolne, wyprawka, dojazdy).";
        }
    }
}

// --- Odpowiedź ------------------------------------------------------------
echo json_encode([
    'success' => true,
    'wohngeld' => $wohngeld,
    'kinderzuschlag' => $kinderzuschlag,
    'meta' => [
        'rok' => 2026,
        'disclaimer' => 'Wyniki są szacunkowe. Ostateczną decyzję wydaje urząd (Wohngeldstelle / Familienkasse) po sprawdzeniu kompletu dokumentów.',
        'sytuacja' => $sytuacja,
        'dochod_razem' => $dochodRazem,
        'mietstufe' => $mietstufe,
        'osoby' => $osoby,
        'dzieci' => $liczbaDzieci,
    ],
], JSON_UNESCAPED_UNICODE);
