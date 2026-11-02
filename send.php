<?php
// Request form handler: validates input and files, sends the request by email (SMTP).
// Responds with JSON: {"ok": true} or {"ok": false, "error": "..."}

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require __DIR__ . '/lib/PHPMailer/Exception.php';
require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';

header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . '/config.php';
$logDir = __DIR__ . '/logs';

function respond(int $code, bool $ok, string $error = ''): void
{
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

function logError(string $dir, string $msg): void
{
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($dir . '/send.log', date('c') . ' ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, false, 'Метод не поддерживается');

// Request larger than post_max_size arrives with empty $_POST
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) respond(413, false, 'Файлы слишком большие');

// Honeypot: bots fill the hidden field — pretend success, send nothing
if (trim($_POST['website'] ?? '') !== '') respond(200, true);

// Rate limit per IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$stamp = sys_get_temp_dir() . '/techburg_rl_' . md5($ip);
if (is_file($stamp) && time() - filemtime($stamp) < $cfg['rate_limit_sec']) {
    respond(429, false, 'Заявка уже отправлена, подождите минуту');
}

// Fields
$clean = fn(string $k, int $max) => mb_substr(trim(strip_tags((string)($_POST[$k] ?? ''))), 0, $max);
$name    = $clean('name', 200);
$contact = $clean('contact', 200);
$message = $clean('message', 5000);

if ($name === '' || mb_strlen($contact) < 5) respond(422, false, 'Заполните имя и контакт');
if (($_POST['consent'] ?? '') !== '1') respond(422, false, 'Нужно согласие на обработку персональных данных');

// Files
$files = [];
if (!empty($_FILES['files']['name'][0])) {
    $f = $_FILES['files'];
    $count = count($f['name']);
    if ($count > $cfg['max_files']) respond(422, false, "Не больше {$cfg['max_files']} файлов");
    $total = 0;
    for ($i = 0; $i < $count; $i++) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        if ($f['error'][$i] === UPLOAD_ERR_INI_SIZE || $f['error'][$i] === UPLOAD_ERR_FORM_SIZE) respond(413, false, 'Файлы слишком большие');
        if ($f['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'][$i])) respond(400, false, 'Ошибка загрузки файла');
        $orig = basename((string)$f['name'][$i]);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $cfg['allowed_ext'], true)) respond(422, false, "Формат файла «{$orig}» не поддерживается");
        $total += (int)$f['size'][$i];
        $files[] = ['path' => $f['tmp_name'][$i], 'name' => $orig];
    }
    if ($total > $cfg['max_total_mb'] * 1024 * 1024) respond(413, false, "Файлы больше {$cfg['max_total_mb']} МБ");
}

$fileList = $files ? implode(', ', array_column($files, 'name')) : 'нет';
$when = date('d.m.Y H:i');

// Email
$mailOk = false;
try {
    $s = $cfg['smtp'];
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = $s['host'];
    $mail->Port = $s['port'];
    $mail->SMTPSecure = $s['secure'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPAuth = true;
    $mail->Username = $s['user'];
    $mail->Password = $s['password'];
    $mail->setFrom($s['from'], $s['fromName']);
    foreach ($cfg['mail_to'] as $to) $mail->addAddress($to);
    // Reply goes straight to the customer when they left an email
    if (filter_var($contact, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($contact, $name);

    $h = fn(string $v) => nl2br(htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
    $mail->isHTML(true);
    $mail->Subject = "Заявка с сайта: {$name}";
    $mail->Body = "<h2>Новая заявка с сайта</h2>"
        . "<p><b>Имя и компания:</b> {$h($name)}</p>"
        . "<p><b>Контакт:</b> {$h($contact)}</p>"
        . "<p><b>Задача:</b><br>" . ($message !== '' ? $h($message) : '—') . "</p>"
        . "<p><b>Файлы:</b> {$h($fileList)}</p>"
        . "<p style='color:#888'>{$when} · IP {$h($ip)}</p>";
    $mail->AltBody = "Имя и компания: {$name}\nКонтакт: {$contact}\nЗадача: {$message}\nФайлы: {$fileList}\n{$when}";
    foreach ($files as $file) $mail->addAttachment($file['path'], $file['name']);

    $mail->send();
    $mailOk = true;
} catch (MailException $e) {
    logError($logDir, 'MAIL: ' . ($mail->ErrorInfo ?? $e->getMessage()));
}

if (!$mailOk) respond(500, false, 'Не удалось отправить заявку');

@touch($stamp);
respond(200, true);
