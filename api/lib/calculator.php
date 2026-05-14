<?php
/**
 * Silnik kalkulatora Wohngeld + Kinderzuschlag 2026 — biblioteka.
 *
 * Wyniesione z api/calculate.php żeby create-checkout.php i get-result.php
 * mogły używać tej samej funkcji. Reguły 2026 wyodrębnione w jednej stałej —
 * łatwo aktualizować rok do roku.
 *
 * WAŻNE: To są SZACUNKI. Ostateczną decyzję wydaje Wohngeldstelle /
 * Familienkasse po sprawdzeniu kompletu dokumentów.
 */

const RULES_2026 = [
    'mietstufen_max_rent_1osoba' => [
        1 => 384, 2 => 432, 3 => 491, 4 => 558, 5 => 628, 6 => 716, 7 => 818,
    ],
    'osoba_dodatkowa_mnoznik'    => 1.20,
    'kiz_min_income_single'      => 900,
    'kiz_min_income_para'        => 1300,
    'kiz_max_per_child'          => 292,
    'wohngeld_income_burden_pct' => 0.30,
    'wohngeld_coverage_pct'      => 0.50,
];

function calculate_eligibility(array $input): array
{
    $sytuacja          = trim((string)($input['sytuacja']   ?? ''));
    $mieszkanie        = trim((string)($input['mieszkanie'] ?? ''));
    $czynsz            = max(0, (float)($input['czynsz']    ?? 0));
    $mietstufe         = max(1, min(7, (int)($input['mietstufe'] ?? 3)));
    $osoby             = max(1, min(10, (int)($input['osoby'] ?? 1)));
    $liczbaDzieci      = max(0, min(10, (int)($input['liczba_dzieci'] ?? 0)));
    $dostajeKindergeld = !empty($input['kindergeld']);
    $dzieciDe          = trim((string)($input['dzieci_de']  ?? 'de'));
    $dochod1           = max(0, (float)($input['dochod_1']  ?? 0));
    $dochod2           = max(0, (float)($input['dochod_2']  ?? 0));
    $dochodInne        = max(0, (float)($input['dochod_inne'] ?? 0));
    $buergergeld       = !empty($input['buergergeld']);

    $dochodRazem = $dochod1 + $dochod2 + $dochodInne;
    $czyPara = in_array($sytuacja, ['para', 'rodzina'], true);

    // === Wohngeld ===
    $wohngeld = ['chance' => 0, 'amount' => 0, 'reasons' => []];

    if ($buergergeld) {
        $wohngeld['reasons'][] = 'Bürgergeld wyklucza Wohngeld — nie można pobierać obu jednocześnie.';
    } elseif ($mieszkanie === '' || $czynsz <= 0) {
        $wohngeld['reasons'][] = 'Brak danych o mieszkaniu — bez czynszu nie da się oszacować.';
    } elseif ($dochodRazem <= 0 && $sytuacja !== 'senior') {
        $wohngeld['chance'] = 10;
        $wohngeld['reasons'][] = 'Bez deklarowanego dochodu trudno oszacować — sprawdź czy nie kwalifikujesz się raczej do Bürgergeld.';
    } else {
        $maxRent1 = RULES_2026['mietstufen_max_rent_1osoba'][$mietstufe] ?? 491;
        $maxRentTotal = $maxRent1 * pow(RULES_2026['osoba_dodatkowa_mnoznik'], max(0, $osoby - 1));
        $uznanyCzynsz = min($czynsz, $maxRentTotal);
        $wklad = $dochodRazem * RULES_2026['wohngeld_income_burden_pct'];

        if ($uznanyCzynsz <= $wklad) {
            $wohngeld['chance'] = 15;
            $wohngeld['reasons'][] = 'Twój dochód jest na tyle wysoki, że według wstępnej formuły kwota Wohngeld wyszłaby zerowa lub bliska zera.';
        } else {
            $kwota = max(0, round(($uznanyCzynsz - $wklad) * RULES_2026['wohngeld_coverage_pct']));
            $wohngeld['amount'] = (int)$kwota;
            if ($kwota >= 200)      $wohngeld['chance'] = 85;
            elseif ($kwota >= 100)  $wohngeld['chance'] = 70;
            elseif ($kwota >= 50)   $wohngeld['chance'] = 50;
            else                    $wohngeld['chance'] = 30;

            $wohngeld['reasons'][] = "Uznany czynsz: " . round($uznanyCzynsz) . " € (z " . round($czynsz) . " €).";
            $wohngeld['reasons'][] = "Wkład z dochodu: ~" . round($wklad) . " € / mies.";
            if ($czynsz > $maxRentTotal) {
                $wohngeld['reasons'][] = "Uwaga: realny czynsz przekracza maksimum uznawane w Twojej Mietstufe — nadwyżki nie pokryje Wohngeld.";
            }
        }
    }

    // === Kinderzuschlag ===
    $kinderzuschlag = ['chance' => 0, 'amount' => 0, 'reasons' => []];

    if ($liczbaDzieci === 0) {
        $kinderzuschlag['reasons'][] = 'Kinderzuschlag jest tylko dla rodzin z dziećmi.';
    } elseif ($buergergeld) {
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
            $kwotaMax = RULES_2026['kiz_max_per_child'] * $liczbaDzieci;
            $hornaGrancia = $minIncome + $kwotaMax + ($dochodRazem - $minIncome) * 0.4;
            if ($dochodRazem > $hornaGrancia + 800) {
                $kinderzuschlag['chance'] = 25;
                $kinderzuschlag['reasons'][] = 'Twój dochód prawdopodobnie przekracza próg dla Kinderzuschlag.';
            } else {
                $zaleznosc = max(0, 1 - ($dochodRazem - $minIncome) / max(1, $hornaGrancia - $minIncome));
                $kwota = round($kwotaMax * (0.4 + 0.6 * $zaleznosc));
                $kinderzuschlag['amount'] = (int)$kwota;
                if ($kwota >= ($kwotaMax * 0.7))       $kinderzuschlag['chance'] = 80;
                elseif ($kwota >= ($kwotaMax * 0.4))   $kinderzuschlag['chance'] = 60;
                else                                    $kinderzuschlag['chance'] = 40;

                $kinderzuschlag['reasons'][] = "Próg minimalny ({$minIncome} €) spełniony.";
                $kinderzuschlag['reasons'][] = "Maksymalny Kinderzuschlag dla {$liczbaDzieci} dz. = " . (int)$kwotaMax . " €/mies.";
                $kinderzuschlag['reasons'][] = "Plus prawo do BuT (obiady szkolne, wyprawka, dojazdy).";
            }
        }
    }

    return [
        'wohngeld' => $wohngeld,
        'kinderzuschlag' => $kinderzuschlag,
        'meta' => [
            'rok' => 2026,
            'disclaimer' => 'Wyniki są szacunkowe. Ostateczną decyzję wydaje urząd (Wohngeldstelle / Familienkasse).',
            'sytuacja' => $sytuacja,
            'dochod_razem' => $dochodRazem,
            'mietstufe' => $mietstufe,
            'osoby' => $osoby,
            'dzieci' => $liczbaDzieci,
        ],
    ];
}

/**
 * Sanityzuje surowe dane wejściowe użytkownika — żeby zapisać do Stripe
 * metadata limit 500 znaków na klucz, łącznie 25kB.
 */
function sanitize_input(array $input): array
{
    return [
        'sytuacja'      => trim((string)($input['sytuacja']      ?? '')),
        'mieszkanie'    => trim((string)($input['mieszkanie']    ?? '')),
        'czynsz'        => max(0, (float)($input['czynsz']       ?? 0)),
        'mietstufe'     => max(1, min(7, (int)($input['mietstufe'] ?? 3))),
        'osoby'         => max(1, min(10, (int)($input['osoby']  ?? 1))),
        'liczba_dzieci' => max(0, min(10, (int)($input['liczba_dzieci'] ?? 0))),
        'kindergeld'    => !empty($input['kindergeld']),
        'dzieci_de'     => trim((string)($input['dzieci_de']     ?? 'de')),
        'dochod_1'      => max(0, (float)($input['dochod_1']     ?? 0)),
        'dochod_2'      => max(0, (float)($input['dochod_2']     ?? 0)),
        'dochod_inne'   => max(0, (float)($input['dochod_inne']  ?? 0)),
        'buergergeld'   => !empty($input['buergergeld']),
        'email'         => substr(trim((string)($input['email']  ?? '')), 0, 100),
    ];
}
