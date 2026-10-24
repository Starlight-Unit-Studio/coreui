<?php
declare(strict_types=1);

/**
 * Inert source replies for the opt-in universal project writer.
 * Aider-style whole-file output may be plain or in a single code fence.
 * An optional COREUI marker is accepted, but is never the only way to
 * establish that an Ollama reply finished. No user source is executed here.
 */
function coreui_usp_parse(string $reply, string $filename, bool $first = true,
                         int $continuationFenceTicks = 0): ?array {
  if ($reply === '' || strlen($reply) > 140000 || str_contains($reply, "\0")
      || preg_match('~\[(?:/)?(?:PY|WEB|BROWSE)(?::[^\]]*)?\]~i', $reply)) return null;
  $raw = $reply;
  if ($first) {
    $heading = '~^\s*#{1,6}[ \t]+' . preg_quote(basename($filename), '~') . '[ \t]*\r?\n~i';
    $raw = (string)preg_replace($heading, '', $raw, 1);
  }
  $marked = preg_match('~(?:^|\r?\n)<<<COREUI_END_FILE>>>[ \t]*\r?\n?\s*$~D',
    $raw, $matches, PREG_OFFSET_CAPTURE) === 1;
  if ($marked) $raw = substr($raw, 0, (int)$matches[0][1]);
  if (str_contains($raw, '<<<COREUI_END_FILE>>>')) return null;
  $fenced = false;
  $fenceClosed = false;
  $fenceTicks = 0;
  if ($first && preg_match('~^\s*(\x60{3,})(?:[A-Za-z0-9_.+#-]{0,48})[ \t]*\r?\n~',
      $raw, $start) === 1) {
    $fenced = true;
    $ticks = $start[1];
    $fenceTicks = strlen($ticks);
    $raw = substr($raw, strlen($start[0]));
    $close = '~(?:^|\r?\n)' . preg_quote($ticks, '~') . '[ \t]*\r?\n?\s*$~D';
    if (preg_match($close, $raw, $end, PREG_OFFSET_CAPTURE) === 1) {
      $fenceClosed = true;
      $fenceTicks = 0;
      $raw = substr($raw, 0, (int)$end[0][1]);
    }
  }
  // The closing fence may arrive in a later Ollama chunk.
  if (!$first && $continuationFenceTicks >= 3 && $continuationFenceTicks <= 12) {
    $ticks = str_repeat(chr(96), $continuationFenceTicks);
    $close = '~(?:^|\r?\n)' . preg_quote($ticks, '~') . '[ \t]*\r?\n?\s*$~D';
    if (preg_match($close, $raw, $end, PREG_OFFSET_CAPTURE) === 1) {
      $fenceClosed = true;
      $raw = substr($raw, 0, (int)$end[0][1]);
    }
  }
  // Do not guess where a second fenced block starts or ends.
  if (preg_match('~(?:^|\r?\n)\s*\x60{3,}~', $raw)) return null;
  if (trim($raw) === '' || strlen($raw) > 120000) return null;
  return ['bytes'=>$raw, 'marked'=>$marked, 'fenced'=>$fenced,
    'fence_closed'=>$fenceClosed, 'fence_ticks'=>$fenceTicks];
}

/** Preserve complete normal Ollama replies even if the model omits markers. */
function coreui_usp_reply_complete(array $reply, array $part): bool {
  if (coreui_usp_reply_truncated($reply)) return false;
  $reason = strtolower(trim((string)($reply['done_reason'] ?? '')));
  if ($reason !== '' && $reason !== 'stop') return false;
  // An explicit tool/file terminator is enough if the provider lacks a reason.
  return $reason === 'stop' || ($reply['complete'] ?? false) === true
    || $part['marked'] === true || $part['fence_closed'] === true;
}

/** Never treat an Ollama length-stop as a complete file. */
function coreui_usp_reply_truncated(array $reply): bool {
  $reason = strtolower(trim((string)($reply['done_reason'] ?? '')));
  return ($reply['truncated'] ?? false) === true
    || in_array($reason, ['length', 'max_tokens', 'token_limit'], true)
    || ($reply['done'] ?? true) === false;
}
