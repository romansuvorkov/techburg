<?php
// Settings for send.php. Fill in on the hosting; never publish real passwords in the repository.
return [
    // Where requests are delivered (several addresses allowed)
    'mail_to' => ['tehburg@mail.ru'],

    // SMTP of a mailbox on your domain (REG.RU hosting panel → Почта).
    // Check the exact server name and port in the REG.RU panel / help for your plan.
    'smtp' => [
        'host'     => 'mail.hosting.reg.ru',
        'port'     => 465,            // 465 = SSL, 587 = STARTTLS
        'secure'   => 'ssl',          // 'ssl' or 'tls'
        'user'     => 'zayavki@techburg.ru',
        'password' => 'CHANGE_ME',
        'from'     => 'zayavki@techburg.ru',
        'fromName' => 'Сайт Техбург',
    ],

    // Limits; keep in sync with the form and .user.ini
    'max_files'      => 5,
    'max_total_mb'   => 25,
    'allowed_ext'    => ['pdf', 'dwg', 'dxf', 'step', 'stp', 'iges', 'igs', 'cdw', 'm3d', 'frw', 'a3d', 'sldprt', 'sldasm', 'jpg', 'jpeg', 'png', 'zip', 'rar', '7z'],
    'rate_limit_sec' => 60,   // minimum interval between requests from one IP
];
