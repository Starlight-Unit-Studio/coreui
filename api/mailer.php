<?php
// STΛRLIGHT UNIT – Minimal SMTP mailer (broad host compatibility)
//
// Why not `mail()`?
// - Some hosts throttle or misconfigure it.
// - SMTP credentials are more predictable.
//
// Configure via api/config.local.php:
//   STU_SMTP_HOST, STU_SMTP_PORT, STU_SMTP_USER, STU_SMTP_PASS
//   STU_MAIL_FROM, STU_MAIL_FROM_NAME

require_once __DIR__ . '/config.php';

function stu_mail_enabled(): bool {
  return !!(STU_SMTP_HOST && STU_SMTP_USER && STU_SMTP_PASS && STU_MAIL_FROM);
}

function stu_send_mail(string $to, string $subject, string $textBody): void {
  if (!stu_mail_enabled()) {
    throw new Exception('mail_not_configured');
  }

  $host = STU_SMTP_HOST;
  $port = (int)STU_SMTP_PORT;
  $user = STU_SMTP_USER;
  $pass = STU_SMTP_PASS;

  $from = STU_MAIL_FROM;
  $fromName = STU_MAIL_FROM_NAME ?: 'Starlight Unit Support';

  $fromHeader = '"' . addcslashes($fromName, "\"\\") . '" <' . $from . '>';

  $headers = [];
  $headers[] = 'From: ' . $fromHeader;
  $headers[] = 'To: <' . $to . '>';
  $headers[] = 'Subject: ' . stu_encode_header($subject);
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: text/plain; charset=utf-8';
  $headers[] = 'Content-Transfer-Encoding: 8bit';

  $data = implode("\r\n", $headers) . "\r\n\r\n" . $textBody . "\r\n";

  $ctx = stream_context_create([
    'ssl' => [
      'verify_peer' => true,
      'verify_peer_name' => true,
      'allow_self_signed' => false,
    ]
  ]);

  // STARTTLS is the sane default on port 587.
  $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) throw new Exception('smtp_connect_failed');
  stream_set_timeout($fp, 12);

  stu_smtp_expect($fp, 220);
  stu_smtp_cmd($fp, 'EHLO stu');
  stu_smtp_expect($fp, 250);

  stu_smtp_cmd($fp, 'STARTTLS');
  stu_smtp_expect($fp, 220);

  if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
    throw new Exception('smtp_tls_failed');
  }

  stu_smtp_cmd($fp, 'EHLO stu');
  stu_smtp_expect($fp, 250);

  // AUTH LOGIN
  stu_smtp_cmd($fp, 'AUTH LOGIN');
  stu_smtp_expect($fp, 334);
  stu_smtp_cmd($fp, base64_encode($user));
  stu_smtp_expect($fp, 334);
  stu_smtp_cmd($fp, base64_encode($pass));
  stu_smtp_expect($fp, 235);

  stu_smtp_cmd($fp, 'MAIL FROM:<' . $from . '>');
  stu_smtp_expect($fp, 250);

  stu_smtp_cmd($fp, 'RCPT TO:<' . $to . '>');
  stu_smtp_expect($fp, [250, 251]);

  stu_smtp_cmd($fp, 'DATA');
  stu_smtp_expect($fp, 354);

  // Dot-stuffing
  $safe = preg_replace('/\r?\n\./', "\n..", str_replace("\n", "\r\n", $data));
  fwrite($fp, $safe . "\r\n.\r\n");
  stu_smtp_expect($fp, 250);

  stu_smtp_cmd($fp, 'QUIT');
  fclose($fp);
}

function stu_encode_header(string $s): string {
  // RFC 2047 (simple, UTF-8 base64)
  if (preg_match('/^[\x20-\x7E]+$/', $s)) return $s;
  return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function stu_smtp_cmd($fp, string $cmd): void {
  fwrite($fp, $cmd . "\r\n");
}

function stu_smtp_expect($fp, $codes): void {
  $codes = (array)$codes;
  $line = '';
  while (!feof($fp)) {
    $line = fgets($fp, 8192);
    if ($line === false) break;
    // Multi-line responses have '-' after the code.
    if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
      $code = (int)$m[1];
      $more = ($m[2] === '-');
      if (!$more) {
        if (!in_array($code, $codes, true)) {
          throw new Exception('smtp_unexpected_' . $code);
        }
        return;
      }
    }
  }
  throw new Exception('smtp_no_response');
}
