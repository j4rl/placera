<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function plc_mail_encode_header(string $value): string
{
    $clean = trim(str_replace(["\r", "\n"], '', $value));
    return '=?UTF-8?B?' . base64_encode($clean) . '?=';
}

function plc_send_mail(string $to, string $subject, string $body): bool
{
    $recipient = trim($to);
    $from = trim((string)PLC_MAIL_FROM);
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('Placera mail: ogiltig mottagare eller avsändare.');
        return false;
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . plc_mail_encode_header((string)PLC_MAIL_FROM_NAME) . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: Placera/PHP',
    ];

    $sent = mail(
        $recipient,
        plc_mail_encode_header($subject),
        str_replace(["\r\n", "\r"], "\n", $body),
        implode("\r\n", $headers)
    );
    if (!$sent) {
        error_log('Placera mail: serverns sendmail-transport avvisade meddelandet.');
    }
    return $sent;
}
