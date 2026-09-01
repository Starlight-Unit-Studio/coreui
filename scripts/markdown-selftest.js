'use strict';

var fs = require('fs');
var path = require('path');
var markdown = require('../js/coreui-markdown.js');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

var unsafe = markdown.parse('Hallo <img src=x onerror=alert(1)> und [klick](javascript:alert(1))');
var unsafeJson = JSON.stringify(unsafe);
expect(unsafeJson.indexOf('onerror=alert(1)') >= 0, 'Rohes HTML wurde nicht als Text erhalten.');
expect(unsafeJson.indexOf('"href":"javascript:') < 0, 'Unsicheres Link-Protokoll wurde zugelassen.');
expect(markdown.safeLinkUrl('javascript:alert(1)') === '', 'javascript:-URL wurde zugelassen.');
expect(markdown.safeLinkUrl('data:text/html,test') === '', 'data:-URL wurde zugelassen.');
expect(markdown.safeLinkUrl('https://example.com/a?b=1') === 'https://example.com/a?b=1', 'HTTPS-Link wurde abgelehnt.');
expect(markdown.safeLinkUrl('mailto:test@example.com') === 'mailto:test@example.com', 'Mail-Link wurde abgelehnt.');

var blocks = markdown.parse([
  '# Titel',
  '',
  '| A | B |',
  '| --- | ---: |',
  '| eins | zwei |',
  '',
  '```javascript',
  'const answer = 42;',
  '```'
].join('\n'));
expect(blocks[0] && blocks[0].type === 'heading', 'Ueberschrift wurde nicht erkannt.');
expect(blocks.some(function (block) { return block.type === 'table'; }), 'Tabelle wurde nicht erkannt.');
expect(blocks.some(function (block) {
  return block.type === 'codeblock' && block.language === 'javascript' && block.value.indexOf('answer = 42') >= 0;
}), 'Codeblock oder Sprache wurde nicht erkannt.');

var source = fs.readFileSync(path.join(__dirname, '..', 'js', 'coreui-markdown.js'), 'utf8');
expect(source.indexOf('.innerHTML') < 0, 'Markdown-Renderer verwendet innerHTML.');
expect(source.indexOf('eval(') < 0, 'Markdown-Renderer verwendet eval.');

process.stdout.write('Markdown-Selftest OK: sichere Links, HTML-Text, Tabellen und Codebloecke geprueft.\n');
