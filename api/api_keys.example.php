<?php
// WZÓR konfiguracji API. Skopiuj jako 'api_keys.php' i uzupełnij własnymi danymi.
// PRAWDZIWY plik api_keys.php jest w .gitignore i NIGDY nie wpada do repo.
// Na hostingu plik jest generowany automatycznie przez GitHub Actions workflow
// z wartości GitHub Secrets — tuż przed wgraniem na FTP.

return [
    // Stripe — sk_live_... lub sk_test_... (z dashboard.stripe.com → Developers → API keys)
    'stripe_secret_key'       => 'sk_test_TUTAJ_TWOJ_KLUCZ',
    'stripe_publishable_key'  => 'pk_test_TUTAJ_TWOJ_KLUCZ',
    'stripe_webhook_secret'   => '', // opcjonalnie, na razie nie używamy

    'stripe_price_amount'     => 499,   // cena w centach (499 = 4,99 €)
    'stripe_currency'         => 'eur',

    // Anthropic Claude API (z console.anthropic.com → API Keys)
    'anthropic_api_key'       => 'sk-ant-api03-TUTAJ_TWOJ_KLUCZ',

    // Adres strony — wykorzystywany do success_url / cancel_url Stripe.
    // BEZ ukośnika na końcu.
    'site_url'                => 'https://allesklar-it-service.de',
];
