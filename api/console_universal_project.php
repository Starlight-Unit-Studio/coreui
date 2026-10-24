<?php
declare(strict_types=1);
require_once __DIR__ . '/console_universal_source_protocol.php';

/**
 * Opt-in generic TEXT project tool. All inference still uses the configured
 * local Ollama /api/chat endpoint. This module never calls a model directly.
 * It accepts a serial callback, and only CoreUI constructs the file writer.
 */
function coreui_up_enabled(): bool {
  return defined('STU_EMBER_UNIVERSAL_FILES_ENABLED')
    && constant('STU_EMBER_UNIVERSAL_FILES_ENABLED') === true;
}
function coreui_up_requested(string $request): bool {
  return coreui_console_download_requested($request)
    && preg_match('~\b(?:zip|archiv)\b~iu', $request) === 1;
}
function coreui_up_extensions(): array {
  return ['html','htm','css','js','mjs','cjs','jsx','ts','tsx','json','jsonc',
    'txt','md','mdx','svg','xml','yaml','yml','toml','ini','csv','py','sql',
    'php','sh','rb','go','rs','java','c','cpp','h','hpp','cs'];
}
/** Relative text paths only: no dot segments, traversal, absolute or binary. */
function coreui_up_path_ok(string $path): bool {
  if ($path === '' || strlen($path) > 180 || str_contains($path, '\\')
      || str_contains($path, "\0") || str_starts_with($path, '/')) return false;
  $segments = explode('/', $path);
  if (count($segments) > 4) return false;
  foreach ($segments as $seg) {
    if ($seg === '.' || $seg === '..'
      || preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$~D', $seg) !== 1
      || str_contains($seg, '..')) return false;
  }
  return in_array(strtolower((string)pathinfo($path, PATHINFO_EXTENSION)),
    coreui_up_extensions(), true);
}
function coreui_up_plan_prompt(string $request): string {
  $task = function_exists('mb_substr')
    ? mb_substr($request, 0, 6000, 'UTF-8') : substr($request, 0, 6000);
  return "COREUI PROJEKTPLAN: Keine Dateien schreiben, kein Python, keine Toolmarker.\n"
    . "Benutzerauftrag:\n" . $task . "\n\n"
    . "Lege die notwendige Projektstruktur selbst fest, UNABHAENGIG vom Projekttyp. "
    . "Antworte AUSSCHLIESSLICH mit einem gueltigen JSON-Objekt in dieser Form:\n"
    . '{"archive":"projekt.zip","files":[{"path":"src/main.js","purpose":"Logik",'
    . '"depends_on":["index.html"]},{"path":"index.html",'
    . '"purpose":"Startseite","depends_on":[]}]}' . "\n"
    . "Dies sind BEISPIELNAMEN, keine Dateivorgabe. Maximal 10 echte UTF-8-Textdateien "
    . "mit maximal vier relativen Pfadsegmenten. Erlaubt sind Textformate wie "
    . "HTML, CSS, JavaScript, TypeScript, JSON, Markdown, SVG, XML, Python, YAML, "
    . "PHP und weitere Quelltexte; keine binaeren Bilder oder anderen Binaerdateien. "
    . "Jede Abhaengigkeit muss in files genannt sein, keine Zyklen, keine "
    . "Verweise auf nicht erstellte lokale Dateien. Kein Markdown und keine Einleitung.";
}
/** Strict JSON plan, cross-file dependencies, and deterministic topo order. */
function coreui_up_plan_parse(string $reply, string $request): ?array {
  if (!coreui_up_requested($request) || strlen($reply) > 20000
      || str_contains($reply, "\0")) return null;
  $raw = trim($reply);
  if (preg_match('~^\x60{3,}json\s*\r?\n(.*?)\r?\n\x60{3,}\s*$~isD',
      $raw, $fence)) $raw = $fence[1];
  $data = json_decode($raw, true);
  if (!is_array($data) || array_is_list($data)) return null;
  $list = $data['files'] ?? null;
  if (!is_array($list) || !array_is_list($list)
      || count($list) < 1 || count($list) > 10) return null;
  $files = [];
  foreach ($list as $entry) {
    if (!is_array($entry) || array_is_list($entry)) return null;
    $path = $entry['path'] ?? null;
    $purpose = $entry['purpose'] ?? null;
    $dependencies = $entry['depends_on'] ?? null;
    if (!is_string($path) || !coreui_up_path_ok($path)
        || !is_string($purpose) || trim($purpose) === '' || strlen($purpose) > 200
        || !is_array($dependencies) || !array_is_list($dependencies)
        || count($dependencies) > 10) return null;
    $key = strtolower($path);
    // Only the ZIP is published. Different directories may share basenames.
    if (isset($files[$key])) return null;
    $deps = [];
    foreach ($dependencies as $dependency) {
      if (!is_string($dependency) || !coreui_up_path_ok($dependency)
          || strtolower($dependency) === $key) return null;
      $depkey = strtolower($dependency);
      if (isset($deps[$depkey])) return null;
      $deps[$depkey] = $dependency;
    }
    $files[$key] = ['path'=>$path,'purpose'=>trim($purpose),
      'depends_on'=>array_values($deps)];
  }
  foreach ($files as $entry) {
    foreach ($entry['depends_on'] as $dep) {
      if (!isset($files[strtolower($dep)])) return null;
    }
  }
  $remaining = $files; $ordered = [];
  while ($remaining) {
    $found = false;
    foreach ($remaining as $key => $entry) {
      foreach ($entry['depends_on'] as $dep) {
        if (!isset($ordered[strtolower($dep)])) continue 2;
      }
      $ordered[$key] = $entry; unset($remaining[$key]); $found = true;
    }
    if (!$found) return null; // cyclic or impossible dependencies
  }
  $archive = is_string($data['archive'] ?? null) ? trim($data['archive']) : 'projekt.zip';
  if (preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]{0,90}\.zip$~iD', $archive) !== 1
      || str_contains($archive, '..')) return null;
  if (preg_match('~(?<![A-Za-z0-9._-])([A-Za-z0-9_][A-Za-z0-9_.-]{0,90}\.zip)(?![A-Za-z0-9._-])~i',
      $request, $userZip)) $archive = $userZip[1];
  return ['archive'=>$archive,'files'=>array_values($ordered)];
}
function coreui_up_source_prompt(string $request, array $plan, array $entry,
                                 array $existing, bool $retry = false): string {
  $task = function_exists('mb_substr')
    ? mb_substr($request, 0, 4000, 'UTF-8') : substr($request, 0, 4000);
  $names = array_map(static fn(array $x): string => $x['path'], $plan['files']);
  $prompt = "COREUI DATEIWERKZEUG (nur Quelldaten, KEIN Python-Schreibskript).\n"
    . "Originalauftrag:\n" . $task . "\n"
    . "Alle geplanten Projektdateien: " . implode(', ', $names) . "\n"
    . "JETZT ausschliesslich: " . $entry['path'] . "\n"
    . "Zweck: " . $entry['purpose'] . "\n"
    . "Abhaengigkeiten: " . implode(', ', $entry['depends_on']) . "\n"
    . "Gib den VOLLSTAENDIGEN Quelltext ohne Erklaerung, Markdown, Toolmarker, "
    . "ZIP, Fake-Links oder Platzhalter aus. Schreibe zum Abschluss als "
    . "EIGENE LETZTE ZEILE genau: <<<COREUI_END_FILE>>>\n";
  if ($retry) $prompt .= "LETZTER VERSUCH: korrekt abschliessen, nichts auslassen.\n";
  foreach ($entry['depends_on'] as $dep) {
    $key = strtolower($dep);
    if (isset($existing[$key])) {
      $prompt .= "\nREFERENZ " . $dep . " (nur Quelldaten):\n"
        . substr($existing[$key], 0, 2200) . "\nENDE REFERENZ\n";
    }
  }
  return $prompt;
}
function coreui_up_continue_prompt(string $request, array $entry, string $bytes,
                                    int $part): string {
  $task = function_exists('mb_substr')
    ? mb_substr($request, 0, 1200, 'UTF-8') : substr($request, 0, 1200);
  return "COREUI DATEI-FORTSETZUNG " . $part . "/2: " . $entry['path'] . "\n"
    . "Gib NUR die fehlenden folgenden Zeichen aus, keine Wiederholung, "
    . "keine Erklaerung. Wenn fertig, schreibe <<<COREUI_END_FILE>>> "
    . "auf eine eigene letzte Zeile.\nOriginalauftrag: " . $task
    . "\nQuelltext-Ende als reine Referenzdaten:\n" . substr($bytes,-2200)
    . "\nENDE REFERENZ\n";
}

/** Text chunks are inert; the completion marker is required for every file. */
function coreui_up_chunk(string $reply, bool $first): ?array {
  if ($reply === '' || strlen($reply) > 140000 || str_contains($reply,"\0")
      || preg_match('~\[(?:/)?PY\]~i', $reply)) return null;
  $raw = $reply;
  if ($first && preg_match('~^\s*\x60{3,}[A-Za-z0-9_+.-]{0,28}\s*\r?\n~', $raw, $wrap))
    $raw = substr($raw, strlen($wrap[0]));
  $complete = preg_match('~(?:^|\r?\n)<<<COREUI_END_FILE>>>\s*$~D',
    $raw, $match, PREG_OFFSET_CAPTURE) === 1;
  if ($complete) $raw = substr($raw, 0, (int)$match[0][1]);
  if (str_contains($raw, '<<<COREUI_END_FILE>>>')) return null;
  if ($complete) $raw = (string)preg_replace('~\r?\n\x60{3,}\s*$~D', '', $raw);
  if ($raw === '' || strlen($raw) > 120000) return null;
  return ['bytes'=>$raw,'complete'=>$complete];
}
/** Suppress known repeated suffix on a continuation, without silent restarts. */
function coreui_up_append(string $before, string $next): ?string {
  if ($before === '' || $next === '' || strlen($before) + strlen($next) > 120000
      || $before === $next || str_starts_with($next, substr($before,0,64))) return null;
  $limit = min(2200, strlen($before), strlen($next)); $overlap = 0;
  for ($n=$limit; $n>=12; $n--) {
    if (substr($before,-$n) === substr($next,0,$n)) { $overlap=$n; break; }
  }
  $joined = $before . substr($next,$overlap);
  return strlen($joined) > strlen($before) ? $joined : null;
}
function coreui_up_source_valid(string $path, string $source): bool {
  if (!coreui_up_path_ok($path) || $source === '' || strlen($source) > 120000
      || str_contains($source,"\0") || preg_match('//u',$source) !== 1) return false;
  $ext = strtolower((string)pathinfo($path,PATHINFO_EXTENSION));
  if (in_array($ext,['html','htm'],true))
    return preg_match('~^\s*(?:<!doctype\s+html[^>]*>\s*)?<html\b~i',$source) === 1
      && preg_match('~</html>\s*$~i',$source) === 1;
  if ($ext === 'svg')
    return preg_match('~^\s*<svg\b~i',$source) === 1
      && preg_match('~</svg>\s*$~i',$source) === 1;
  if ($ext === 'json') {
    json_decode($source,true);
    return json_last_error() === JSON_ERROR_NONE;
  }
  if ($ext === 'css') {
    // A zero-brace conversational answer is not a stylesheet. The
    // validation is per language, never per homepage or project layout.
    $open=substr_count($source,'{');
    $closed=substr_count($source,'}');
    if ($open !== $closed) return false;
    $withoutComments=(string)preg_replace('~/\*.*?\*/~s','',$source);
    if (trim($withoutComments)==='') return false;
    if ($open===0) {
      return preg_match('~^\s*@(?:charset|import|namespace|layer)\b[^{}]*;\s*$~is',
        $withoutComments) === 1;
    }
    return preg_match('~\{[^{}]*\}~s',$withoutComments) === 1;
  }
  // Other languages must be checked with their own non-executing linters.
  return true;
}
/** Relative web resource resolution; null is returned for out-of-tree paths. */
function coreui_up_relative(string $origin, string $link): ?string {
  if ($link === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/|#)~i',$link)) return null;
  $base = dirname($origin);
  $segments = $base === '.' ? [] : explode('/',$base);
  $reference = explode('?',explode('#',$link,2)[0],2)[0];
  foreach (explode('/',$reference) as $segment) {
    if ($segment === '' || $segment === '.') continue;
    if ($segment === '..') {
      if ($segments === []) return null;
      array_pop($segments); continue;
    }
    $segments[]=$segment;
  }
  return implode('/',$segments);
}
function coreui_up_project_valid(array $plan, array $files): bool {
  $wanted = [];
  foreach ($plan['files'] as $entry) $wanted[strtolower($entry['path'])]=$entry['path'];
  if (count($wanted) !== count($files)) return false;
  $total=0;
  foreach ($wanted as $key=>$path) {
    $source=$files[$key] ?? null;
    if (!is_string($source) || !coreui_up_source_valid($path,$source)) return false;
    $total += strlen($source);
    if ($total > 400000) return false;
    if (!in_array(strtolower((string)pathinfo($path,PATHINFO_EXTENSION)),['html','htm'],true))
      continue;
    if (preg_match_all('~<(?:script|link)\b[^>]*\b(?:src|href)\s*=\s*["\x27]([^"\x27]+)["\x27]~i',
      $source,$refs)) {
      foreach ($refs[1] as $link) {
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/|#)~i',$link)) continue;
        $target=coreui_up_relative($path,$link);
        if ($target===null || !isset($wanted[strtolower($target)])) return false;
      }
    }
  }
  return true;
}
/** Only the trusted backend script executes; model output is encoded as DATA. */
function coreui_up_writer(array $plan, array $files, int $maxCodeChars): ?array {
  if ($maxCodeChars < 500 || !coreui_up_project_valid($plan,$files)) return null;
  $packed=[]; $total=0;
  foreach ($plan['files'] as $entry) {
    $path=$entry['path']; $source=$files[strtolower($path)];
    $total += strlen($source);
    $compressed=gzcompress($source,6);
    $useZip=is_string($compressed) && strlen($compressed)<strlen($source);
    $packed[$path]=[$useZip?'z':'b',base64_encode($useZip?$compressed:$source)];
  }
  $json=json_encode($packed,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  $archive=json_encode($plan['archive'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  $script="from pathlib import Path\nimport base64, zlib, zipfile, tempfile\n"
    ."output = Path('/work/output')\noutput.mkdir(parents=True, exist_ok=True)\n"
    ."sources = ".$json."\narchive = output / ".$archive."\n"
    ."with tempfile.TemporaryDirectory(prefix='coreui_project_') as temp:\n"
    ."    root = Path(temp)\n"
    ."    for name, pair in sources.items():\n"
    ."        mode, data = pair\n"
    ."        raw = base64.b64decode(data, validate=True)\n"
    ."        if mode == 'z': raw = zlib.decompress(raw)\n"
    ."        dest = root.joinpath(*name.split('/'))\n"
    ."        dest.parent.mkdir(parents=True, exist_ok=True)\n"
    ."        dest.write_bytes(raw)\n"
    ."        assert dest.is_file() and dest.stat().st_size == len(raw)\n"
    ."    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED) as z:\n"
    ."        for name in sorted(sources):\n"
    ."            z.write(root.joinpath(*name.split('/')), arcname=name)\n"
    ."    with zipfile.ZipFile(archive) as z:\n"
    ."        assert z.namelist() == sorted(sources)\n"
    ."        assert z.testzip() is None\n"
    ."        for name in sources:\n"
    ."            assert z.read(name) == root.joinpath(*name.split('/')).read_bytes()\n"
    ."assert archive.is_file() and archive.stat().st_size > 0\n"
    ."print('ZIP verified:', archive.name, len(sources))\n";
  if (strlen($script)>$maxCodeChars) return null;
  return ['code'=>$script,'name'=>$plan['archive'],'file_count'=>1,
    'source_count'=>count($files),'size_bytes'=>$total];
}
/**
 * Serial model adapter contract:
 * fn(prompt, phase, path, attempt) => ['text'=>string,'truncated'=>bool,'done_reason'=>string].
 * No persistence or executable model code; the caller verifies worker outputs.
 */
function coreui_up_build(string $request, callable $modelCall, int $maxCodeChars=100000,
                         ?callable $progress=null): array {
  if (!coreui_up_requested($request)) return ['ok'=>false,'reason'=>'not_project_request'];
  $emit=static function(string $stage,array $meta=[]) use($progress): void {
    if ($progress!==null) $progress($stage,$meta);
  };
  $plan=null;
  for ($attempt=1;$attempt<=2;$attempt++) {
    $emit('plan_requested',['attempt'=>$attempt]);
    $answer=$modelCall(coreui_up_plan_prompt($request),'plan','',$attempt);
    $text=is_array($answer)?($answer['text']??null):null;
    $truncated=is_array($answer)&&coreui_usp_reply_truncated($answer);
    $plan=is_string($text)&&!$truncated?coreui_up_plan_parse($text,$request):null;
    if ($plan!==null) break;
  }
  if (!is_array($plan)) return ['ok'=>false,'reason'=>'project_plan_invalid'];
  $emit('plan_ready',['file_count'=>count($plan['files'])]);
  $files=[];
  foreach ($plan['files'] as $entry) {
    $key=strtolower($entry['path']); $accepted=null;
    for ($attempt=1;$attempt<=2;$attempt++) {
      $emit('source_requested',['file_name'=>basename($entry['path']),'attempt'=>$attempt]);
      $answer=$modelCall(coreui_up_source_prompt($request,$plan,$entry,$files,$attempt>1),
        'source',$entry['path'],$attempt);
      $chunk=is_array($answer)&&is_string($answer['text']??null)
        ?coreui_usp_parse($answer['text'],$entry['path'],true):null;
      if (!is_array($chunk)) continue;
      $bytes=$chunk['bytes'];
      $openFenceTicks=(int)($chunk['fence_ticks'] ?? 0);
      // Aider-style raw/whole and fenced responses are accepted only with
      // a safe Ollama stop or an explicit closed response. File validation
      // still decides whether the bytes are an eligible source.
      $done=coreui_usp_reply_complete($answer,$chunk);
      $needsMore=coreui_usp_reply_truncated($answer);
      for ($segment=1;!$done&&$needsMore&&$segment<=2;$segment++) {
        $more=$modelCall(coreui_up_continue_prompt($request,$entry,$bytes,$segment),
          'continue',$entry['path'],$segment);
        $part=is_array($more)&&is_string($more['text']??null)
          ?coreui_usp_parse($more['text'],$entry['path'],false,$openFenceTicks):null;
        if (!is_array($part)) break;
        $joined=coreui_up_append($bytes,$part['bytes']);
        if ($joined===null) break;
        $bytes=$joined;
        if (($part['fence_closed'] ?? false) === true) $openFenceTicks=0;
        $done=coreui_usp_reply_complete($more,$part);
        $needsMore=coreui_usp_reply_truncated($more);
      }
      if ($done&&coreui_up_source_valid($entry['path'],$bytes)) {
        $accepted=$bytes; break;
      }
    }
    if ($accepted===null) return ['ok'=>false,'reason'=>'source_incomplete',
      'file_name'=>basename($entry['path'])];
    $files[$key]=$accepted;
    $emit('source_ready',['file_name'=>basename($entry['path'])]);
  }
  $writer=coreui_up_writer($plan,$files,$maxCodeChars);
  if (!is_array($writer)) return ['ok'=>false,'reason'=>'project_validation_failed'];
  return ['ok'=>true,'plan'=>$plan,'writer'=>$writer,
    'source_count'=>count($files)];
}

/**
 * An Aider-style exact edit primitive for later file repairs.
 * No fuzzy replacements: ambiguous matches MUST fail closed.
 */
function coreui_up_edit_once(string $previous, string $search, string $replace): ?string {
  if ($search === '' || strlen($previous) > 120000 || strlen($search) > 24000
      || strlen($replace) > 60000 || str_contains($previous, "\0")
      || substr_count($previous, $search) !== 1) return null;
  $updated = str_replace($search, $replace, $previous);
  return $updated !== '' && strlen($updated) <= 120000 ? $updated : null;
}
