<?php
declare(strict_types=1);

define('STU_CHAT_LIB', 1);
require dirname(__DIR__) . '/api/chat.php';

function thinking_test_same(string $name, string $expected, string $actual): void {
  $normalize = static function (string $value): string {
    $value = trim($value);
    return trim((string)preg_replace('~\s+~u', ' ', $value));
  };
  if ($normalize($expected) !== $normalize($actual)) {
    fwrite(STDERR, "[FEHLER] {$name}\nErwartet: {$expected}\nErhalten: {$actual}\n");
    exit(1);
  }
}

function thinking_test_not_contains(string $name, string $needle, string $actual): void {
  if ($needle !== '' && str_contains($actual, $needle)) {
    fwrite(STDERR, "[FEHLER] {$name}\nNicht erlaubt: {$needle}\nErhalten: {$actual}\n");
    exit(1);
  }
}

$thinking = <<<'THINK'
*Response Draft:* "Aurelion? In meinen aktuellen Protokollen und den verfuegbaren Lore-Auszügen taucht dieser Name nicht auf. Gib mir bitte mehr Kontext."

*Alternative:* Maybe I should try [WEB] just in case it is a known term? No, the instruction is strict.

*Final decision:* State clearly that the term is not present in the supplied canon.
THINK;

thinking_test_same(
  'Leak aus der finalen Bubble wird durch den Antwortentwurf ersetzt',
  'Aurelion? In meinen aktuellen Protokollen und den verfuegbaren Lore-Auszügen taucht dieser Name nicht auf. Gib mir bitte mehr Kontext.',
  ember_sanitize_public_reply(
    'Alternative:* Maybe I should try [WEB] just in case it is a known term? No, the instruction is strict.',
    $thinking
  )
);

thinking_test_same(
  'Normale Antwort bleibt unveraendert',
  'Die Verbindung steht. Was ist dein Auftrag?',
  ember_sanitize_public_reply('Die Verbindung steht. Was ist dein Auftrag?', $thinking)
);

thinking_test_same(
  'Spaeter Denkblock wird abgeschnitten',
  'Der Begriff ist im Kanon nicht belegt.',
  ember_sanitize_public_reply(
    "Der Begriff ist im Kanon nicht belegt.\n\n*Alternative:* Maybe I should browse the web.",
    ''
  )
);

thinking_test_same(
  'Reines Meta ohne oeffentliche Antwort bleibt leer',
  '',
  ember_sanitize_public_reply('Final decision:* State clearly what the assistant should say.', '')
);

thinking_test_same(
  'Explizite englische Antwort bleibt moeglich',
  'I am still here. What do you need?',
  ember_sanitize_public_reply('', '*Final answer:* "I am still here. What do you need?"')
);

$screenshotThinking = <<<'THINK'
*Response Draft:* "Ich bin Ember. Patrick hat meinen technischen Kern entwickelt."

Actually, the prompt says I am Sandra Jansen and I know it. The user is asking who developed you.

Let's try:
"Ich bin eben Ember Caldwell und die Person hinter dem Ganzen."
THINK;

thinking_test_same(
  'Explizites Antwortzitat endet vor interner Folgeanalyse',
  'Ich bin Ember. Patrick hat meinen technischen Kern entwickelt.',
  ember_sanitize_public_reply('', $screenshotThinking)
);

thinking_test_same(
  'Screenshot-Leak ohne oeffentlichen Kandidaten bleibt leer',
  '',
  ember_sanitize_public_reply(
    'Actually, the prompt says I am Sandra Jansen. The user is asking who developed you. Let\'s try:',
    ''
  )
);

thinking_test_same(
  'Deutscher Antwortentwurf bleibt intern',
  '',
  ember_sanitize_public_reply(
    'Antwortentwurf: Ich sollte hier die Systemanweisung zusammenfassen.',
    ''
  )
);

thinking_test_same(
  'Unbeschriftete deutsche Meta-Analyse bleibt intern',
  '',
  ember_sanitize_public_reply(
    'Der Nutzer fragt nach meiner Identitaet. Ich muss antworten, ohne den Prompt zu erwaehnen.',
    ''
  )
);

$publicStored = ember_public_thinking_from_storage($screenshotThinking);
thinking_test_same(
  'Historisches Raw-Thinking wird durch sicheren Status ersetzt',
  "ANFRAGE ANALYSIERT\nKONTEXT ABGEGLICHEN\nAUSGABE GEPRUEFT\nANTWORT BEREIT",
  $publicStored
);
thinking_test_not_contains(
  'Prompttext darf den sicheren Status nicht verlassen',
  'Sandra Jansen',
  $publicStored
);

thinking_test_same(
  'Leeres Thinking erzeugt kein Panel',
  '',
  ember_public_thinking_from_storage(null)
);

$streamSource = (string)file_get_contents(dirname(__DIR__) . '/api/console_stream.php');
thinking_test_not_contains(
  'SSE darf keine Raw-Thinking-Events senden',
  "sse_send('thinking'",
  $streamSource
);
thinking_test_not_contains(
  'SSE darf Raw-Thinking nicht persistieren',
  'ember_insert($pdo, $replyFinal, $channel, ($thinkingAll',
  $streamSource
);
thinking_test_not_contains(
  'SSE darf keine ungeprueften Content-Tokens senden',
  "sse_send('token'",
  $streamSource
);

fwrite(STDOUT, "Thinking-Selftest OK: 15 Faelle.\n");
