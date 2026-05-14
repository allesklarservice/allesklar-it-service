<?php
// WZÓR konfiguracji SMTP. Skopiuj jako 'smtp_config.php' i uzupełnij własnymi danymi.
// PRAWDZIWY plik smtp_config.php jest w .gitignore i NIGDY nie wpada do repo.

return [
    'host'     => 'mail.example.com',
    'port'     => 465,
    'secure'   => 'ssl',                       // 'ssl' (port 465) lub 'tls' (port 587 STARTTLS)
    'username' => 'twoje-konto@example.com',
    'password' => 'TUTAJ_TWOJE_HASLO',
    'from'     => 'twoje-konto@example.com',
    'fromName' => 'Twoja Firma',
    'to'       => 'odbiorca@example.com',
    'timeout'  => 20,
];
