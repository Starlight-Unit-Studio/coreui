<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/console_data_link_bridge.php';
require_once dirname(__DIR__) . '/api/console_universal_project.php';

function writer_assert(bool $ok, string $msg): void {
  if (!$ok) { fwrite(STDERR, "universal ZIP writer FAIL: ".$msg."\n"); exit(1); }
}
$request = 'Erstelle ein Browser-Projekt als ZIP zum Download';
$manifest = ['archive'=>'demo.zip','files'=>[
  ['path'=>'src/app.js','purpose'=>'Script','depends_on'=>[]],
  ['path'=>'assets/theme.css','purpose'=>'Style','depends_on'=>[]],
  ['path'=>'index.html','purpose'=>'Page','depends_on'=>['src/app.js','assets/theme.css']],
]];
$spec = coreui_up_plan_parse(json_encode($manifest),$request);
writer_assert(is_array($spec),'manifest invalid');
$sources = [
  'src/app.js'=>"const value = 3;\nconsole.log(value);\n",
  'assets/theme.css'=>"body { background: #113355; }\n",
  'index.html'=>'<!DOCTYPE html><html><head><link rel="stylesheet" href="assets/theme.css"></head>'
    . '<body><script src="src/app.js"></script></body></html>',
];
$writer = coreui_up_writer($spec,array_change_key_case($sources,CASE_LOWER),100000);
writer_assert(is_array($writer),'trusted writer invalid');
if (($argv[1] ?? '') === '--emit-payload') {
  echo json_encode(['code'=>$writer['code'],'expected'=>$sources,
    'archive'=>$writer['name']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  exit(0);
}
if (!function_exists('proc_open')) {
  fwrite(STDERR,"universal ZIP writer FAIL: proc_open unavailable\n"); exit(1);
}
$temp = sys_get_temp_dir() . '/coreui_pilot_' . bin2hex(random_bytes(10));
writer_assert(mkdir($temp,0700),'cannot make temporary directory');
try {
  $dir = $temp . '/output';
  writer_assert(mkdir($dir,0700),'cannot make output directory');
  // Isolated verification only: redirect the fixed sandbox output path
  // to a disposable directory, never the productive artifact storage.
  $code = str_replace("Path('/work/output')", 'Path(' . json_encode($dir,JSON_UNESCAPED_SLASHES) . ')',
    $writer['code']);
  writer_assert($code !== $writer['code'] && !str_contains($code,"Path('/work/output')"),
    'writer output root cannot be safely redirected');
  $script = $temp . '/writer.py';
  writer_assert(file_put_contents($script,$code)!==false,'cannot prepare test script');
  $cmd=['python3','-I',$script];
  $pipes=[];
  $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$temp);
  writer_assert(is_resource($proc),'python3 unavailable');
  fclose($pipes[0]);
  $stdout=stream_get_contents($pipes[1]); fclose($pipes[1]);
  $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]);
  $status=proc_close($proc);
  writer_assert($status===0 && is_file($dir.'/demo.zip'),
    'Python writer failed: '.substr((string)$stderr,0,600));
  // Verify nested paths, exact source content and CRC with Python's stdlib.
  $expected=json_encode($sources,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  $verify = "import json, sys, zipfile\n"
    . "expected=json.loads(sys.argv[2])\n"
    . "with zipfile.ZipFile(sys.argv[1]) as z:\n"
    . "    assert z.testzip() is None\n"
    . "    assert set(z.namelist()) == set(expected)\n"
    . "    for name, text in expected.items():\n"
    . "        assert z.read(name) == text.encode('utf-8')\n";
  $process=proc_open(['python3','-I','-c',$verify,$dir.'/demo.zip',$expected],
    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$temp);
  writer_assert(is_resource($process),'zip verification process unavailable');
  fclose($pipes[0]);$o=stream_get_contents($pipes[1]);fclose($pipes[1]);
  $e=stream_get_contents($pipes[2]);fclose($pipes[2]);
  writer_assert(proc_close($process)===0,'ZIP byte comparison failed: '.substr((string)$e,0,600));
  writer_assert(str_contains((string)$stdout,'ZIP verified:'),'trusted confirmation absent');
} finally {
  foreach ([$temp.'/output/demo.zip',$temp.'/writer.py'] as $path) if (is_file($path)) unlink($path);
  if (is_dir($temp.'/output')) rmdir($temp.'/output');
  if (is_dir($temp)) rmdir($temp);
}
echo "CoreUI universal project ZIP writer selftest: OK\n";
