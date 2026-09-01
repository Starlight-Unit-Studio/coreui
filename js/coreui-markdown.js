/* Ember CoreUI 0.5.0-alpha: sichere, lokal ausgelieferte Markdown-Darstellung. */
(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.CoreUIMarkdown = api;
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  var LANGUAGE_ALIASES = {
    js: 'javascript', jsx: 'javascript', mjs: 'javascript', cjs: 'javascript',
    ts: 'typescript', tsx: 'typescript',
    py: 'python', shell: 'bash', sh: 'bash', zsh: 'bash',
    yml: 'yaml', htm: 'html', xml: 'html',
    md: 'markdown', plaintext: 'text', txt: 'text'
  };

  var KEYWORDS = {
    javascript: 'async await break case catch class const continue debugger default delete do else export extends finally for from function get if import in instanceof let new of return set static super switch this throw try typeof var void while with yield',
    typescript: 'abstract any as asserts async await boolean break case catch class const constructor continue declare default delete do else enum export extends finally for from function get if implements import in infer instanceof interface keyof let namespace never new null number object of private protected public readonly require return set static string super switch symbol this throw try type typeof undefined unknown var void while with yield',
    php: 'abstract and array as break callable case catch class clone const continue declare default do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile eval exit extends final finally fn for foreach function global goto if implements include include_once instanceof insteadof interface isset list match namespace new or print private protected public readonly require require_once return static switch throw trait try unset use var while xor yield',
    python: 'and as assert async await break class continue def del elif else except False finally for from global if import in is lambda None nonlocal not or pass raise return True try while with yield',
    bash: 'case do done elif else esac export fi for function if in local readonly return select then time until while',
    sql: 'add all alter and as asc begin between by case check column commit constraint create database default delete desc distinct drop else end exists foreign from full grant group having in index inner insert into is join key left like limit not null on or order outer primary references right rollback select set table then union unique update values view when where with',
    css: 'and important media not only or supports',
    html: 'a article aside body button code div footer form h1 h2 h3 h4 h5 h6 head header html img input label li link main meta nav ol p pre script section span strong style table tbody td th thead title tr ul',
    json: 'false null true',
    yaml: 'false null true yes no on off'
  };

  function normalizeText(value) {
    return String(value == null ? '' : value).replace(/\r\n?/g, '\n').replace(/\u0000/g, '');
  }

  function canonicalLanguage(value) {
    var lang = String(value || '').trim().toLowerCase().replace(/[^a-z0-9_+#.-]/g, '').slice(0, 32);
    return LANGUAGE_ALIASES[lang] || lang || 'text';
  }

  function safeLinkUrl(value) {
    var raw = String(value || '').trim();
    if (!raw || /[\u0000-\u001f\u007f]/.test(raw)) return '';
    if (/^https?:\/\//i.test(raw) || /^mailto:/i.test(raw)) return raw;
    return '';
  }

  function textNode(value) {
    return { type: 'text', value: String(value || '') };
  }

  function parseInline(value, depth) {
    var text = String(value || '');
    var level = Number(depth) || 0;
    if (!text || level > 8) return [textNode(text)];
    var out = [];
    var buffer = '';

    function flush() {
      if (buffer) out.push(textNode(buffer));
      buffer = '';
    }

    function pushWrapped(type, inner) {
      flush();
      out.push({ type: type, children: parseInline(inner, level + 1) });
    }

    for (var i = 0; i < text.length;) {
      if (text.charAt(i) === '\\' && i + 1 < text.length) {
        buffer += text.charAt(i + 1);
        i += 2;
        continue;
      }

      if (text.charAt(i) === '`') {
        var codeEnd = text.indexOf('`', i + 1);
        if (codeEnd > i + 1) {
          flush();
          out.push({ type: 'code', value: text.slice(i + 1, codeEnd) });
          i = codeEnd + 1;
          continue;
        }
      }

      if (text.slice(i, i + 2) === '**' || text.slice(i, i + 2) === '__') {
        var strongMark = text.slice(i, i + 2);
        var strongEnd = text.indexOf(strongMark, i + 2);
        if (strongEnd > i + 2) {
          pushWrapped('strong', text.slice(i + 2, strongEnd));
          i = strongEnd + 2;
          continue;
        }
      }

      if (text.slice(i, i + 2) === '~~') {
        var strikeEnd = text.indexOf('~~', i + 2);
        if (strikeEnd > i + 2) {
          pushWrapped('del', text.slice(i + 2, strikeEnd));
          i = strikeEnd + 2;
          continue;
        }
      }

      if (text.charAt(i) === '[') {
        var labelEnd = text.indexOf('](', i + 1);
        if (labelEnd > i + 1) {
          var linkEnd = text.indexOf(')', labelEnd + 2);
          if (linkEnd > labelEnd + 2) {
            var target = safeLinkUrl(text.slice(labelEnd + 2, linkEnd).split(/\s+["']/)[0]);
            if (target) {
              flush();
              out.push({
                type: 'link',
                href: target,
                children: parseInline(text.slice(i + 1, labelEnd), level + 1)
              });
              i = linkEnd + 1;
              continue;
            }
          }
        }
      }

      if (text.charAt(i) === '<') {
        var autoEnd = text.indexOf('>', i + 1);
        if (autoEnd > i + 1) {
          var autoTarget = safeLinkUrl(text.slice(i + 1, autoEnd));
          if (autoTarget) {
            flush();
            out.push({ type: 'link', href: autoTarget, children: [textNode(autoTarget)] });
            i = autoEnd + 1;
            continue;
          }
        }
      }

      var rawUrl = text.slice(i).match(/^https?:\/\/[^\s<>]+/i);
      if (rawUrl) {
        var urlValue = rawUrl[0];
        var trailing = '';
        while (/[),.!?;:]$/.test(urlValue)) {
          trailing = urlValue.slice(-1) + trailing;
          urlValue = urlValue.slice(0, -1);
        }
        var safeRawUrl = safeLinkUrl(urlValue);
        if (safeRawUrl) {
          flush();
          out.push({ type: 'link', href: safeRawUrl, children: [textNode(urlValue)] });
          if (trailing) out.push(textNode(trailing));
          i += rawUrl[0].length;
          continue;
        }
      }

      if (text.charAt(i) === '*' || text.charAt(i) === '_') {
        var emMark = text.charAt(i);
        var emEnd = text.indexOf(emMark, i + 1);
        if (emEnd > i + 1) {
          pushWrapped('em', text.slice(i + 1, emEnd));
          i = emEnd + 1;
          continue;
        }
      }

      buffer += text.charAt(i);
      i++;
    }
    flush();
    return out;
  }

  function splitTableLine(line) {
    var text = String(line || '').trim();
    if (text.charAt(0) === '|') text = text.slice(1);
    if (text.charAt(text.length - 1) === '|') text = text.slice(0, -1);
    var cells = [];
    var buffer = '';
    var escaped = false;
    var inCode = false;
    for (var i = 0; i < text.length; i++) {
      var ch = text.charAt(i);
      if (escaped) {
        buffer += ch;
        escaped = false;
      } else if (ch === '\\') {
        escaped = true;
      } else if (ch === '`') {
        inCode = !inCode;
        buffer += ch;
      } else if (ch === '|' && !inCode) {
        cells.push(buffer.trim());
        buffer = '';
      } else {
        buffer += ch;
      }
    }
    cells.push(buffer.trim());
    return cells;
  }

  function isTableDivider(line) {
    var cells = splitTableLine(line);
    return cells.length > 0 && cells.every(function (cell) {
      return /^:?-{3,}:?$/.test(cell);
    });
  }

  function lineStartsBlock(lines, index) {
    var line = lines[index] || '';
    if (!line.trim()) return true;
    if (/^\s{0,3}(?:`{3,}|~{3,})/.test(line)) return true;
    if (/^\s{0,3}#{1,6}\s+/.test(line)) return true;
    if (/^\s{0,3}>\s?/.test(line)) return true;
    if (/^\s{0,3}(?:[-+*]|\d+[.)])\s+/.test(line)) return true;
    if (/^\s{0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/.test(line)) return true;
    return index + 1 < lines.length && line.indexOf('|') >= 0 && isTableDivider(lines[index + 1]);
  }

  function parseBlocks(value) {
    var lines = normalizeText(value).split('\n');
    var blocks = [];
    for (var i = 0; i < lines.length;) {
      var line = lines[i];
      if (!line.trim()) { i++; continue; }

      var fence = line.match(/^\s{0,3}(`{3,}|~{3,})\s*([A-Za-z0-9_+#.-]{0,32})\s*$/);
      if (fence) {
        var marker = fence[1];
        var code = [];
        i++;
        while (i < lines.length && !new RegExp('^\\s{0,3}' + marker.charAt(0) + '{' + marker.length + ',}\\s*$').test(lines[i])) {
          code.push(lines[i]);
          i++;
        }
        if (i < lines.length) i++;
        blocks.push({ type: 'codeblock', language: canonicalLanguage(fence[2]), value: code.join('\n') });
        continue;
      }

      var heading = line.match(/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/);
      if (heading) {
        blocks.push({ type: 'heading', level: heading[1].length, children: parseInline(heading[2]) });
        i++;
        continue;
      }

      if (/^\s{0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
        blocks.push({ type: 'hr' });
        i++;
        continue;
      }

      if (/^\s{0,3}>\s?/.test(line)) {
        var quoteLines = [];
        while (i < lines.length && /^\s{0,3}>\s?/.test(lines[i])) {
          quoteLines.push(lines[i].replace(/^\s{0,3}>\s?/, ''));
          i++;
        }
        blocks.push({ type: 'blockquote', blocks: parseBlocks(quoteLines.join('\n')) });
        continue;
      }

      var listStart = line.match(/^\s{0,3}([-+*]|\d+[.)])\s+(.+)$/);
      if (listStart) {
        var ordered = /^\d/.test(listStart[1]);
        var listItems = [];
        var start = ordered ? Math.max(1, parseInt(listStart[1], 10) || 1) : 1;
        while (i < lines.length) {
          var itemMatch = lines[i].match(/^\s{0,3}([-+*]|\d+[.)])\s+(.+)$/);
          if (!itemMatch || /^\d/.test(itemMatch[1]) !== ordered) break;
          var itemText = itemMatch[2];
          i++;
          while (i < lines.length && /^\s{2,}\S/.test(lines[i]) && !lineStartsBlock(lines, i)) {
            itemText += '\n' + lines[i].trim();
            i++;
          }
          listItems.push(parseInline(itemText));
        }
        blocks.push({ type: 'list', ordered: ordered, start: start, items: listItems });
        continue;
      }

      if (i + 1 < lines.length && line.indexOf('|') >= 0 && isTableDivider(lines[i + 1])) {
        var header = splitTableLine(line).map(parseInline);
        var alignment = splitTableLine(lines[i + 1]).map(function (cell) {
          return cell.charAt(0) === ':' && cell.charAt(cell.length - 1) === ':' ? 'center'
            : (cell.charAt(cell.length - 1) === ':' ? 'right' : (cell.charAt(0) === ':' ? 'left' : ''));
        });
        var rows = [];
        i += 2;
        while (i < lines.length && lines[i].trim() && lines[i].indexOf('|') >= 0) {
          rows.push(splitTableLine(lines[i]).map(parseInline));
          i++;
        }
        blocks.push({ type: 'table', header: header, alignment: alignment, rows: rows });
        continue;
      }

      var paragraph = [line.trim()];
      i++;
      while (i < lines.length && !lineStartsBlock(lines, i)) {
        paragraph.push(lines[i].trim());
        i++;
      }
      blocks.push({ type: 'paragraph', lines: paragraph.map(parseInline) });
    }
    return blocks;
  }

  function appendInline(parent, nodes, doc) {
    (nodes || []).forEach(function (node) {
      if (node.type === 'text') {
        parent.appendChild(doc.createTextNode(node.value));
        return;
      }
      if (node.type === 'code') {
        var code = doc.createElement('code');
        code.className = 'md-inline-code';
        code.textContent = node.value;
        parent.appendChild(code);
        return;
      }
      var tag = node.type === 'strong' ? 'strong' : (node.type === 'em' ? 'em' : (node.type === 'del' ? 'del' : 'a'));
      var el = doc.createElement(tag);
      if (node.type === 'link') {
        el.href = node.href;
        el.target = '_blank';
        el.rel = 'noopener noreferrer nofollow';
      }
      appendInline(el, node.children || [], doc);
      parent.appendChild(el);
    });
  }

  function copyText(value, doc) {
    var text = String(value || '');
    if (typeof navigator !== 'undefined' && navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      try {
        var area = doc.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        doc.body.appendChild(area);
        area.select();
        var ok = doc.execCommand('copy');
        doc.body.removeChild(area);
        if (!ok) throw new Error('copy_failed');
        resolve();
      } catch (error) {
        reject(error);
      }
    });
  }

  function appendHighlightedCode(codeEl, value, language, doc) {
    var lang = canonicalLanguage(language);
    var keywordList = KEYWORDS[lang] || '';
    var keywordMap = {};
    keywordList.split(/\s+/).filter(Boolean).forEach(function (word) { keywordMap[word] = true; });
    var commentParts = ['\\/\\*[\\s\\S]*?\\*\\/', '\\/\\/[^\\n]*'];
    if (lang === 'python' || lang === 'bash' || lang === 'yaml') commentParts.push('#[^\\n]*');
    if (lang === 'sql') commentParts.push('--[^\\n]*');
    var source = commentParts.concat([
      "'(?:\\\\.|[^'\\\\])*'",
      '"(?:\\\\.|[^"\\\\])*"',
      '`(?:\\\\.|[^`\\\\])*`',
      '\\b\\d+(?:\\.\\d+)?\\b',
      '\\b[A-Za-z_$][\\w$]*\\b'
    ]).join('|');
    var rx = new RegExp(source, 'g');
    var text = String(value || '');
    var last = 0;
    var match;
    while ((match = rx.exec(text)) !== null) {
      if (match.index > last) codeEl.appendChild(doc.createTextNode(text.slice(last, match.index)));
      var token = match[0];
      var cls = '';
      if (/^(?:\/\*|\/\/|#|--)/.test(token)) cls = 'md-token-comment';
      else if (/^["'`]/.test(token)) cls = 'md-token-string';
      else if (/^\d/.test(token)) cls = 'md-token-number';
      else if (keywordMap[token] || keywordMap[token.toLowerCase()]) cls = 'md-token-keyword';
      else if (/^(?:true|false|null|none|undefined)$/i.test(token)) cls = 'md-token-literal';
      if (cls) {
        var span = doc.createElement('span');
        span.className = cls;
        span.textContent = token;
        codeEl.appendChild(span);
      } else {
        codeEl.appendChild(doc.createTextNode(token));
      }
      last = rx.lastIndex;
    }
    if (last < text.length) codeEl.appendChild(doc.createTextNode(text.slice(last)));
  }

  function appendBlock(parent, block, doc) {
    if (block.type === 'paragraph') {
      var p = doc.createElement('p');
      block.lines.forEach(function (line, index) {
        if (index > 0) p.appendChild(doc.createElement('br'));
        appendInline(p, line, doc);
      });
      parent.appendChild(p);
      return;
    }
    if (block.type === 'heading') {
      var heading = doc.createElement('h' + block.level);
      appendInline(heading, block.children, doc);
      parent.appendChild(heading);
      return;
    }
    if (block.type === 'hr') {
      parent.appendChild(doc.createElement('hr'));
      return;
    }
    if (block.type === 'blockquote') {
      var quote = doc.createElement('blockquote');
      block.blocks.forEach(function (child) { appendBlock(quote, child, doc); });
      parent.appendChild(quote);
      return;
    }
    if (block.type === 'list') {
      var list = doc.createElement(block.ordered ? 'ol' : 'ul');
      if (block.ordered && block.start !== 1) list.start = block.start;
      block.items.forEach(function (item) {
        var li = doc.createElement('li');
        appendInline(li, item, doc);
        list.appendChild(li);
      });
      parent.appendChild(list);
      return;
    }
    if (block.type === 'table') {
      var wrap = doc.createElement('div');
      wrap.className = 'md-table-wrap';
      var table = doc.createElement('table');
      var thead = doc.createElement('thead');
      var headRow = doc.createElement('tr');
      block.header.forEach(function (cell, index) {
        var th = doc.createElement('th');
        if (block.alignment[index]) th.style.textAlign = block.alignment[index];
        appendInline(th, cell, doc);
        headRow.appendChild(th);
      });
      thead.appendChild(headRow);
      table.appendChild(thead);
      var tbody = doc.createElement('tbody');
      block.rows.forEach(function (row) {
        var tr = doc.createElement('tr');
        for (var index = 0; index < block.header.length; index++) {
          var td = doc.createElement('td');
          if (block.alignment[index]) td.style.textAlign = block.alignment[index];
          appendInline(td, row[index] || [], doc);
          tr.appendChild(td);
        }
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      wrap.appendChild(table);
      parent.appendChild(wrap);
      return;
    }
    if (block.type === 'codeblock') {
      var codeWrap = doc.createElement('div');
      codeWrap.className = 'md-codeblock';
      var toolbar = doc.createElement('div');
      toolbar.className = 'md-code-toolbar';
      var language = doc.createElement('span');
      language.className = 'md-code-language';
      language.textContent = canonicalLanguage(block.language).toUpperCase();
      var copy = doc.createElement('button');
      copy.type = 'button';
      copy.className = 'md-code-copy';
      copy.textContent = 'KOPIEREN';
      copy.setAttribute('aria-label', 'Code kopieren');
      copy.addEventListener('click', function () {
        copy.disabled = true;
        copyText(block.value, doc).then(function () {
          copy.textContent = 'KOPIERT';
        }).catch(function () {
          copy.textContent = 'FEHLER';
        }).finally(function () {
          setTimeout(function () { copy.disabled = false; copy.textContent = 'KOPIEREN'; }, 1400);
        });
      });
      toolbar.appendChild(language);
      toolbar.appendChild(copy);
      var pre = doc.createElement('pre');
      var code = doc.createElement('code');
      code.className = 'language-' + canonicalLanguage(block.language);
      appendHighlightedCode(code, block.value, block.language, doc);
      pre.appendChild(code);
      codeWrap.appendChild(toolbar);
      codeWrap.appendChild(pre);
      parent.appendChild(codeWrap);
    }
  }

  function render(container, value) {
    if (!container || !container.ownerDocument) return;
    var doc = container.ownerDocument;
    while (container.firstChild) container.removeChild(container.firstChild);
    var fragment = doc.createDocumentFragment();
    parseBlocks(value).forEach(function (block) { appendBlock(fragment, block, doc); });
    container.appendChild(fragment);
  }

  return {
    render: render,
    parse: parseBlocks,
    parseInline: parseInline,
    safeLinkUrl: safeLinkUrl,
    canonicalLanguage: canonicalLanguage,
    copyText: copyText
  };
});
