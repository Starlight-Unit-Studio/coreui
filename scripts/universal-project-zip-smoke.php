<?php
declare(strict_types=1);
/** Offline trusted-writer integration smoke: real PHP planner -> real Python ZIP. */
require_once dirname(__DIR__) . '/api/console_data_link_bridge.php';
require_once dirname(__DIR__) . '/api/console_universal_project.php';

function up_smoke_assert(bool $condition, string $why): void {
  if (!$condition) throw new RuntimeException('universal project ZIP smoke: ' . $why);
}
function up_smoke_run(array $argv): void {
  $spec = [['pipe','r'],['pipe','w'],['pipe','w']];
  $proc = proc_open($argv, $spec, $pipes);
  if (!is_resource($proc)) throw new RuntimeException('python unavailable');
  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]); fclose($pipes[2]);
  $exitCode=proc_close($proc);
  up_smoke_assert($exitCode===0,
    implode(' ', $argv) . ' failed: ' . substr((string)$stderr,0,2000) . ' ' . substr((string)$stdout,0,1000));
}

$request='Erstelle ein Webprojekt mit HTML, CSS und JavaScript als ZIP zum Download.';
$manifest=['archive'=>'portable-test.zip','files'=>[
  ['path'=>'index.html','purpose'=>'Entry','depends_on'=>['assets/site.css','src/main.js']],
  ['path'=>'src/main.js','purpose'=>'Logic','depends_on'=>[]],
  ['path'=>'assets/site.css','purpose'=>'Styles','depends_on'=>[]],
]];
$sources=[
  'index.html'=>'<!DOCTYPE html><html><head><link rel="stylesheet" href="assets/site.css"></head><body><script src="src/main.js"></script></body></html>',
  'src/main.js'=>'const greeting = "Hello"; console.log(greeting);',
  'assets/site.css'=>'body { color: #eee; background: #203040; }',
];
$calls=[];
$fakeModel=static function(string $prompt,string $phase,string $path,int $attempt)
  use (&$calls,$manifest,$sources): array {
    $calls[]=[$phase,$path,$attempt];
    return ['text'=>$phase==='plan'
      ? json_encode($manifest, JSON_THROW_ON_ERROR)
      : $sources[$path]."\n<<<COREUI_END_FILE>>>",'truncated'=>false];
};
$build=coreui_up_build($request,$fakeModel,100000);
up_smoke_assert(($build['ok']??false)===true,'manifest/build rejected');
up_smoke_assert($calls===[['plan','',1],['source','src/main.js',1],
    ['source','assets/site.css',1],['source','index.html',1]],
  'expected serial dependency order');
$writer=$build['writer'];
up_smoke_assert(($writer['file_count']??0)===1,'should emit exactly one ZIP');

$tmp=sys_get_temp_dir() . '/coreui-up-' . bin2hex(random_bytes(8));
up_smoke_assert(mkdir($tmp,0700)===true,'temporary directory unavailable');
try {
  $out=$tmp . '/output';
  $needle="Path('/work/output')";
  up_smoke_assert(substr_count($writer['code'],$needle)===1,'sandbox output anchor changed');
  $code=str_replace($needle,'Path('.json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).')',$writer['code']);
  $py=$tmp . '/writer.py';
  up_smoke_assert(file_put_contents($py,$code)>0,'writer fixture not saved');
  up_smoke_run(['python3','-I',$py]);
  $archive=$out . '/portable-test.zip';
  up_smoke_assert(is_file($archive) && filesize($archive)>0,'real ZIP missing');
  $expected=$tmp . '/expected.json';
  file_put_contents($expected,json_encode($sources,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
  $verify=$tmp . '/verify.py';
  file_put_contents($verify,implode("\n",[
    'import json, sys, zipfile',
    'expected = json.load(open(sys.argv[2], encoding="utf-8"))',
    'with zipfile.ZipFile(sys.argv[1]) as archive:',
    '    assert sorted(archive.namelist()) == sorted(expected)',
    '    assert archive.testzip() is None',
    '    for filename, content in expected.items():',
    '        assert archive.read(filename) == content.encode("utf-8")',
    'print("Archive bytes, paths and CRC OK")','',
  ]));
  up_smoke_run(['python3','-I',$verify,$archive,$expected]);
} finally {
  $all=new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($all as $item) {
    if ($item->isDir()) rmdir($item->getPathname());
    else unlink($item->getPathname());
  }
  rmdir($tmp);
}
echo "CoreUI universal project ZIP writer smoke: OK\n";
