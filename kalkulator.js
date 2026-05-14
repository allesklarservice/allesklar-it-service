/**
 * Kalkulator AI — logika frontowa.
 *
 * Zbiera dane z 4 ekranów formularza, wywołuje api/calculate.php,
 * wstawia wyniki do ekranu wyniku (krok 6).
 *
 * Iteracja 1: bez Stripe i bez Claude AI (placeholdery dla AI komentarza).
 * Stripe + Claude dojdą w kolejnych iteracjach.
 */

const state = {
    sytuacja: null,
    mieszkanie: null,
    czynsz: 0,
    mietstufe: 3,
    osoby: 1,
    liczba_dzieci: 0,
    kindergeld: false,
    dzieci_de: 'de',
    dochod_1: 0,
    dochod_2: 0,
    dochod_inne: 0,
    buergergeld: false,
    email: '',
};

// ============ NAWIGACJA EKRANÓW ============
function goToScreen(n) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    const target = document.getElementById('screen-' + n);
    if (target) {
        target.classList.add('active');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

// ============ WYBÓR KAFELKA ============
function selectTile(el, value) {
    const grid = el.closest('.tile-grid');
    if (!grid) return;
    // zaznacz tylko jeden w obrębie tego grida
    grid.querySelectorAll('.tile').forEach(s => s.classList.remove('selected'));
    el.classList.add('selected');

    const field = grid.dataset.field;
    if (!field) return;

    // konwersja prostych mapowań true/false dla pól boolean
    if (field === 'kindergeld') {
        state.kindergeld = (value === 'kg-tak');
    } else if (field === 'buergergeld') {
        state.buergergeld = (value === 'bg-tak');
    } else if (field === 'dzieci_de') {
        state.dzieci_de = value.replace('mieszka-', ''); // mieszka-de → de
    } else if (field === 'mieszkanie') {
        // wynajem | wlasne | subnajem — pasują
        state.mieszkanie = value;
    } else if (field === 'sytuacja') {
        state.sytuacja = value;
    } else {
        state[field] = value;
    }
}

// ============ ZBIERANIE INPUTÓW ============
function captureInputs() {
    const czynsz = parseFloat(document.getElementById('czynsz')?.value) || 0;
    const mietstufe = parseInt(document.getElementById('miasto')?.value, 10) || 3;
    const osoby = parseInt(document.getElementById('osoby')?.value, 10) || 1;
    const liczbaDzieci = parseInt(document.getElementById('liczba-dzieci')?.value, 10) || 0;
    const dochod1 = parseFloat(document.getElementById('dochod-1')?.value) || 0;
    const dochod2 = parseFloat(document.getElementById('dochod-2')?.value) || 0;
    const dochodInne = parseFloat(document.getElementById('dochod-inne')?.value) || 0;
    const email = document.getElementById('email')?.value || '';

    state.czynsz = czynsz;
    state.mietstufe = mietstufe;
    state.osoby = osoby;
    state.liczba_dzieci = liczbaDzieci;
    state.dochod_1 = dochod1;
    state.dochod_2 = dochod2;
    state.dochod_inne = dochodInne;
    state.email = email;
}

// ============ PRZEJŚCIE Z KAŻDEGO KROKU ============
function nextStep(targetScreen) {
    captureInputs();
    goToScreen(targetScreen);
}

// ============ WYSYŁKA DO API + POKAZANIE WYNIKU ============
// W iteracji 1 — bezpośrednio po kliknięciu "Zapłać" (bez Stripe).
// W iteracji 2 — najpierw Stripe Checkout, po powrocie wywołanie API.
async function submitCalculation() {
    captureInputs();

    const btn = document.getElementById('payBtn');
    if (btn) {
        btn.disabled = true;
        btn.textContent = '⏳ Liczę...';
    }

    try {
        const response = await fetch('/api/calculate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(state)
        });

        if (!response.ok) {
            throw new Error('Błąd serwera: ' + response.status);
        }

        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Błąd obliczeń');
        }

        renderResults(data);
        goToScreen(6);
    } catch (err) {
        console.error(err);
        alert('Coś poszło nie tak: ' + err.message + '\n\nSpróbuj odświeżyć stronę.');
        if (btn) {
            btn.disabled = false;
            btn.textContent = '🔒 Zapłać 4,99 € przez Stripe →';
        }
    }
}

// ============ RENDEROWANIE WYNIKÓW ============
function renderResults(data) {
    const w = data.wohngeld;
    const k = data.kinderzuschlag;

    // Wohngeld
    setText('result-wohngeld-chance', w.chance + '%');
    setText('result-wohngeld-label', chanceLabel(w.chance));
    setText('result-wohngeld-amount', w.amount > 0 ? '~ ' + formatEur(w.amount) + ' / mies.' : 'Niewielka kwota lub 0');
    setChanceCircleColor('result-wohngeld-chance', w.chance);
    setText('result-wohngeld-yearly', w.amount > 0
        ? `Roczna oszczędność: ~${formatEur(w.amount * 12)}`
        : 'Sprawdź z doradcą — pojedyncze przypadki bywają nieoczywiste.');

    const wReasonsHtml = (w.reasons || []).map(r => `<li>${escapeHtml(r)}</li>`).join('');
    const wReasonsEl = document.getElementById('result-wohngeld-reasons');
    if (wReasonsEl) wReasonsEl.innerHTML = wReasonsHtml;

    // Kinderzuschlag
    setText('result-kiz-chance', k.chance + '%');
    setText('result-kiz-label', chanceLabel(k.chance));
    setText('result-kiz-amount', k.amount > 0 ? '~ ' + formatEur(k.amount) + ' / mies.' : 'Niewielka kwota lub 0');
    setChanceCircleColor('result-kiz-chance', k.chance);
    setText('result-kiz-yearly', k.amount > 0
        ? `Plus BuT (obiady, wyprawka): dodatkowo do ~175 €/mies. na dziecko.`
        : 'Sprawdź alternatywę: Bürgergeld lub konsultacja z doradcą.');

    const kReasonsHtml = (k.reasons || []).map(r => `<li>${escapeHtml(r)}</li>`).join('');
    const kReasonsEl = document.getElementById('result-kiz-reasons');
    if (kReasonsEl) kReasonsEl.innerHTML = kReasonsHtml;

    // AI komentarz — placeholder (iteracja 3 podłączy Claude API)
    const aiEl = document.getElementById('ai-comment-body');
    if (aiEl) {
        aiEl.innerHTML = buildPlaceholderComment(data);
    }
}

// ============ POMOCNICZE ============
function setText(id, txt) {
    const el = document.getElementById(id);
    if (el) el.textContent = txt;
}

function setChanceCircleColor(id, chance) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('chance-high', 'chance-mid', 'chance-low');
    if (chance >= 65) el.classList.add('chance-high');
    else if (chance >= 35) el.classList.add('chance-mid');
    else el.classList.add('chance-low');
}

function chanceLabel(chance) {
    if (chance >= 75) return 'Bardzo wysoka szansa';
    if (chance >= 55) return 'Wysoka szansa';
    if (chance >= 35) return 'Średnia szansa';
    if (chance >= 15) return 'Niska szansa';
    return 'Bardzo niska szansa';
}

function formatEur(n) {
    return new Intl.NumberFormat('pl-PL', { maximumFractionDigits: 0 }).format(n) + ' €';
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);
}

function buildPlaceholderComment(data) {
    const w = data.wohngeld, k = data.kinderzuschlag;
    let parts = [];
    parts.push(`<p>Cześć! Przeanalizowałem Twoją sytuację. Tutaj najważniejsze wnioski po polsku.</p>`);

    if (w.chance >= 60) {
        parts.push(`<p><strong>🟢 Wohngeld – wygląda dobrze.</strong> Według naszego silnika szansa wynosi ${w.chance}% przy szacowanej kwocie ${formatEur(w.amount)}/mies. Złóż wniosek w Wohngeldstelle w urzędzie miasta. Decyzja zwykle przychodzi w 4-8 tygodni.</p>`);
    } else if (w.chance >= 30) {
        parts.push(`<p><strong>🟡 Wohngeld – pod znakiem zapytania.</strong> Wstępny wynik to ${w.chance}% szansy. Spróbuj — w najgorszym razie odmowa, ale wiele zależy od dokumentów.</p>`);
    } else {
        parts.push(`<p><strong>🔴 Wohngeld – małe szanse.</strong> ${w.chance}% — według wstępnej kalkulacji nie kwalifikujesz się lub jesteś bardzo blisko progu.</p>`);
    }

    if (data.meta.dzieci > 0) {
        if (k.chance >= 60) {
            parts.push(`<p><strong>🟢 Kinderzuschlag – wygląda dobrze.</strong> Szansa ${k.chance}%, do ${formatEur(k.amount)}/mies. plus automatycznie BuT (obiady szkolne, wyprawka, dojazdy).</p>`);
        } else if (k.chance >= 30) {
            parts.push(`<p><strong>🟡 Kinderzuschlag – warto spróbować.</strong> Szansa ${k.chance}%, ale wszystko zależy od dokładnych dochodów i kosztów mieszkania.</p>`);
        } else {
            parts.push(`<p><strong>🔴 Kinderzuschlag – raczej nie.</strong> ${k.chance}% — Twój dochód jest poza progiem lub brakuje warunku Kindergeld.</p>`);
        }
    }

    parts.push(`<p><strong>⚠️ Pamiętaj:</strong> Wohngeld i Kinderzuschlag <strong>można łączyć</strong>, ale jednoczesny Bürgergeld wyklucza oba. Wniosek ważny jest od miesiąca złożenia — nie zwlekaj.</p>`);
    parts.push(`<p style="color:#94A3B8;font-size:13px;font-style:italic">[iteracja 1: komentarz wygenerowany lokalnie. Iteracja 3 zastąpi to prawdziwą odpowiedzią Claude AI po polsku, spersonalizowaną pod Twoją sytuację.]</p>`);

    return parts.join('\n');
}

// ============ INICJALIZACJA ============
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('hamburger')?.addEventListener('click', () => {
        document.getElementById('navMenu')?.classList.toggle('open');
    });

    window.addEventListener('scroll', () => {
        const nav = document.getElementById('navbar');
        if (!nav) return;
        if (window.scrollY > 20) nav.classList.add('scrolled');
        else nav.classList.remove('scrolled');
    });
});
