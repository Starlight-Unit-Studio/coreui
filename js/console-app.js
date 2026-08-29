// Ember CoreUI - console-app.js
// Session-Persistenz via MariaDB (api/console_sessions.php)
// Nachrichten kommen aus stu_chat_messages via SSE und Fetch-Poll.
// Rohe Modellgedanken werden weder angezeigt noch aus der Historie uebernommen.
(function () {
  'use strict';

  // ── Konfiguration ────────────────────────────────────────
  var API_BASE          = new URL('./api', document.baseURI).pathname.replace(/\/$/, '');
  var CHANNEL           = 'console';       // Privater Ember-Kanal
  var EMBER_PREFIX      = '@Ember ';
  var POLL_FAST_MS      = 1500;   // während Ember antwortet
  var POLL_BG_MS        = 3000;   // Hintergrund-Poll
  var MAX_POLL_ATTEMPTS = 700;    // ~17,5 Minuten: lokale Gemma-4-Generierung plus Werkzeugpfad
  // Phase 3a: SSE mit sicherem Denkstatus. Poll bleibt Fallback.
  var USE_STREAM        = (typeof window !== 'undefined' && 'EventSource' in window);
  var STREAM_IDLE_MS    = 780000; // Server sendet sichere Heartbeats; erst nach 13 Minuten ausweichen.
  var BROWSE_IDLE_MS    = 720000; // Mehrstufige lokale Recherche darf bis zu 12 Minuten dauern.
  var uiPreferences     = window.CoreUIPreferences
    ? window.CoreUIPreferences.load()
    : { thinkingExpanded: false, liveFrameMotion: true };

  window.addEventListener('coreui:preferences', function (event) {
    if (event && event.detail) uiPreferences = event.detail;
  });

  var SAFE_THINKING_LINES = {
    'ANFRAGE WIRD ANALYSIERT': true,
    'KONTEXT WIRD ABGEGLICHEN': true,
    'ANTWORT WIRD FORMULIERT': true,
    'AUSGABE WIRD GEPRUEFT': true,
    'WERKZEUGPFAD WIRD AUSGEFUEHRT': true,
    'ANFRAGE ANALYSIERT': true,
    'KONTEXT ABGEGLICHEN': true,
    'AUSGABE GEPRUEFT': true,
    'ANTWORT BEREIT': true
  };
  var SAFE_THINKING_COMPLETE = 'ANFRAGE ANALYSIERT\nKONTEXT ABGEGLICHEN\nAUSGABE GEPRUEFT\nANTWORT BEREIT';

  // Zweite, clientseitige Schranke. Selbst bei einem alten Server oder einer alten
  // Datenbankzeile wird niemals beliebiger thinking_content in den DOM geschrieben.
  function safeThinkingText(value) {
    var raw = String(value || '').trim();
    if (!raw || raw === 'busy') return '';
    var result = [];
    raw.split(/\r?\n/).forEach(function (line) {
      var clean = String(line || '').trim();
      if (SAFE_THINKING_LINES[clean] && result.indexOf(clean) < 0) result.push(clean);
    });
    return result.length ? result.join('\n') : SAFE_THINKING_COMPLETE;
  }

  function appendSafeThinkingStatus(body, value) {
    if (!body) return;
    var safe = safeThinkingText(value);
    if (!safe) return;
    var current = String(body.textContent || '').split(/\r?\n/).filter(Boolean);
    safe.split(/\r?\n/).forEach(function (line) {
      if (current.indexOf(line) < 0) current.push(line);
    });
    body.textContent = current.join('\n');
    body.scrollTop = body.scrollHeight;
  }

  // Letzte Schranke fuer alte DB-Zeilen und rollende Upgrades. Neue Antworten
  // kommen bereits serverseitig bereinigt und werden erst nach Abschluss gesendet.
  function safeReplyText(value) {
    var text = String(value || '').trim();
    if (!text) return '';
    if (/<\/think>/i.test(text)) text = text.replace(/^.*?<\/think>/is, '').trim();
    text = text.replace(/<think>[\s\S]*$/i, '').trim();
    text = text.replace(/\[(?:WEB|BROWSE):[^\]]*\]/gi, '').replace(/\[\/?PY\]/gi, '').trim();

    var head = text.slice(0, 900).replace(/^[*"'„“«\s]+/, '').toLowerCase();
    var startsInternal = /^(?:response draft|selected response|final answer|refined(?: response)?|draft(?:\s*\d+)?|alternative|final decision|checking constraints?|constraint check|analysis|reasoning|plan|tone|self-correction|antwortsentwurf|ausgew(?:ae|ä)hlte antwort|endg(?:ue|ü)ltige antwort|analyse|(?:ue|ü)berlegung|pr(?:ue|ü)fung|selbstkorrektur)\s*:/.test(head);
    var highSignals = [
      /the user (?:is asking|asks|wants)/i,
      /actually,? the prompt (?:says|asks|requires)/i,
      /(?:system|developer) (?:prompt|instruction)/i,
      /let['’]?s (?:try|go with)\s*:/i,
      /(?:i|we) (?:need|should|must) to (?:answer|respond|mention|avoid|follow|ensure)/i,
      /der nutz(?:er|erin) (?:fragt|moechte|möchte|will)/i,
      /ich (?:muss|sollte) (?:antworten|erwaehnen|erwähnen|vermeiden|sicherstellen)/i,
      /(?:system|entwickler)[\s-]*(?:prompt|anweisung)/i
    ].filter(function (rx) { return rx.test(head); }).length;
    if (startsInternal || highSignals >= 1) {
      return 'Diese ältere Ausgabe enthielt einen internen Modellentwurf und wurde ausgeblendet.';
    }
    return text;
  }

  // ── State ────────────────────────────────────────────────
  var state = {
    uid:          null,
    characterId:  null,
    charName:     null,
    userDisplayName: null,
    assistantName: 'Ember',
    userAvatarUrl: null,
    assistantAvatarUrl: null,
    csrfToken:     '',
    sessions:        [],
    activeSessionId: null,
    viewEpoch:       0,
    drawerOpen:      false,
    waitingEmber:    false,
    pollTimers:      {},
    pollAttemptsBySession: {},
    bgTimer:         null,
    typingEl:        null,
    thinkingPanelEl: null,
    sendTimestamp:   0,
    sendTimestampBySession: {},
    countdownEl:     null,
    countdownTimer:  null,
    streamedIds:     {},
    pendingBySession: {},
    messageCacheBySession: {},
    historyHasMoreBySession: {},
    historyOldestBySession: {},
    showArchived: false
  };

  // ── DOM refs ─────────────────────────────────────────────
  var drawerEl      = document.getElementById('drawer');
  var drawerOverlay = document.getElementById('drawerOverlay');
  var drawerClose   = document.getElementById('drawerClose');
  var btnDrawer     = document.getElementById('btnDrawer');
  var sessionListEl = document.getElementById('sessionList');
  var sessionCountEl = document.getElementById('sessionCount');
  var sessionArchiveToggle = document.getElementById('sessionArchiveToggle');
  var messagesInner = document.getElementById('messagesInner');
  var messagesArea  = document.getElementById('messagesArea');
  var inputEl       = document.getElementById('inputMessage');
  var btnSend       = document.getElementById('btnSend');
  // v1.1.1.89 - Anhang
  var fileInput     = document.getElementById('fileInput');
  var btnAttach     = document.getElementById('btnAttach');
  var attachList    = document.getElementById('attachList');
  var attachUploadState = document.getElementById('attachUploadState');
  var attachUploadName = document.getElementById('attachUploadName');
  var attachUploadSub = document.getElementById('attachUploadSub');
  var attachBar     = document.getElementById('attachBar');
  var attachBarFill = document.getElementById('attachBarFill');
  var MAX_MESSAGE_ATTACHMENTS = 10;
  var pendingFiles  = [];
  var uploadQueue   = [];
  var uploadCurrentFile = null;
  var uploadXhr     = null;
  var btnNewSession = document.getElementById('btnNewSession');
  var btnSettings   = document.getElementById('btnSettings');
  var btnProtocols  = document.getElementById('btnProtocols');
  var btnLogout     = document.getElementById('btnLogout');
  var headerSessionName = document.getElementById('headerSessionName');
  var statusDot     = document.getElementById('statusDot');
  var statusLabel   = document.getElementById('statusLabel');

  // ── Status ───────────────────────────────────────────────
  function setStatus(type, label) {
    statusDot.className = 'status-dot' + (type ? ' ' + type : '');
    statusLabel.textContent = label || '';
  }

  // ── Session-Zugriff ──────────────────────────────────────
  function activeSession() {
    var id = String(state.activeSessionId || '');
    return state.sessions.find(function(s) { return String(s.id) === id; }) || null;
  }

  function isActiveSession(sessionId) {
    return String(state.activeSessionId || '') === String(sessionId || '');
  }

  function activeSessionStorageKey() {
    return 'emberCoreUI.activeSession.' + String(state.uid || 'guest');
  }

  function rememberActiveSession(sessionId) {
    try { localStorage.setItem(activeSessionStorageKey(), String(sessionId || '')); } catch (e) {}
  }

  function rememberedActiveSession() {
    try { return localStorage.getItem(activeSessionStorageKey()) || ''; } catch (e) { return ''; }
  }

  function sessionById(sessionId) {
    var id = String(sessionId || '');
    return state.sessions.find(function(s) { return String(s.id) === id; }) || null;
  }

  function updateActivePendingUi() {
    var ses = activeSession();
    state.waitingEmber = !!(ses && state.pendingBySession[ses.id]);
    btnSend.disabled = state.waitingEmber;
  }

  // ── DB: Sessions laden ───────────────────────────────────
  function dbLoadSessions() {
    var url = API_BASE + '/console_sessions.php?action=list'
      + (state.showArchived ? '&include_archived=1' : '');
    return fetch(url, {
      credentials: 'include'
    })
    .then(function(r) {
      return r.json().then(function(d) {
        if (!r.ok || !d || !d.ok || !Array.isArray(d.sessions)) {
          throw new Error((d && d.error) || 'session_list_failed');
        }
        return d;
      });
    })
    .then(function(d) {
      var selected = String(state.activeSessionId || rememberedActiveSession());
      state.sessions = d.sessions;
      if (selected && sessionById(selected)) state.activeSessionId = selected;
    });
  }

  // ── DB: Neue Session anlegen ─────────────────────────────
  function dbCreateSession(title) {
    return fetch(API_BASE + '/console_sessions.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'create', title: title || '' })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.ok) {
        var ses = d.session || d;
        state.sessions.unshift(ses);
        return ses;
      }
      throw new Error(d.error || 'create_failed');
    });
  }

  function dbSessionAction(payload) {
    return fetch(API_BASE + '/console_sessions.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {})
    }).then(function(r) { return r.json(); });
  }

  function dbMarkRead(sessionId, messageId) {
    if (!(messageId > 0)) return Promise.resolve();
    return dbSessionAction({ action: 'mark_read', id: sessionId, message_id: messageId })
      .catch(function() {});
  }

  function dbRenameSession(sessionId, title) {
    return dbSessionAction({ action: 'rename', id: sessionId, title: title });
  }

  function dbArchiveSession(sessionId) {
    return dbSessionAction({ action: 'archive', id: sessionId });
  }

  function dbRestoreSession(sessionId) {
    return dbSessionAction({ action: 'restore', id: sessionId });
  }

  function dbDeleteSession(sessionId) {
    return dbSessionAction({
      action: 'delete_permanently',
      id: sessionId,
      confirmation: 'DELETE:' + String(sessionId || '')
    });
  }

  function clearSessionRuntime(sessionId) {
    sessionId = String(sessionId || '');
    if (state.pollTimers[sessionId]) clearTimeout(state.pollTimers[sessionId]);
    delete state.pollTimers[sessionId];
    delete state.pollAttemptsBySession[sessionId];
    delete state.pendingBySession[sessionId];
    delete state.sendTimestampBySession[sessionId];
    (state.messageCacheBySession[sessionId] || []).forEach(function(message) {
      if (message && Number(message.id) > 0) delete state.streamedIds[Number(message.id)];
    });
    delete state.messageCacheBySession[sessionId];
    delete state.historyHasMoreBySession[sessionId];
    delete state.historyOldestBySession[sessionId];
  }

  // ── Session-Liste rendern ────────────────────────────────
  function renderSessionList() {
    sessionListEl.innerHTML = '';
    var activeCount = state.sessions.filter(function(s) { return !s.archived; }).length;
    if (sessionCountEl) sessionCountEl.textContent = String(activeCount).padStart(2, '0');
    if (sessionArchiveToggle) {
      sessionArchiveToggle.classList.toggle('active', state.showArchived);
      sessionArchiveToggle.textContent = state.showArchived ? 'ARCHIV AUS' : 'ARCHIV';
    }
    state.sessions.forEach(function(s) {
      var li = document.createElement('li');
      li.className = 'session-item' + (isActiveSession(s.id) ? ' active' : '') + (s.archived ? ' archived' : '');

      var titleEl = document.createElement('div');
      titleEl.className = 'session-item-title';
      titleEl.textContent = s.title;

      var metaEl = document.createElement('div');
      metaEl.className = 'session-item-meta';
      var metaBits = [];
      if (s.updated_at) {
        metaBits.push(new Date(s.updated_at).toLocaleDateString('de-DE', {
          day: '2-digit', month: '2-digit', year: '2-digit'
        }));
      }
      metaBits.push(String(Number(s.message_count) || 0) + ' NACHRICHTEN');
      metaEl.textContent = metaBits.join(' // ');

      if ((Number(s.unread_count) || 0) > 0) {
        var unread = document.createElement('span');
        unread.className = 'session-unread';
        unread.textContent = String(s.unread_count);
        unread.title = 'Neue Nachrichten';
        li.appendChild(unread);
      }

      var actions = document.createElement('div');
      actions.className = 'session-item-actions';
      var rename = document.createElement('button');
      rename.type = 'button';
      rename.className = 'session-action';
      rename.textContent = 'UMBENENNEN';
      rename.addEventListener('click', function(ev) {
        ev.stopPropagation();
        var next = window.prompt('Name der Sitzung', s.title || '');
        if (next === null || !next.trim()) return;
        dbRenameSession(s.id, next.trim()).then(function(d) {
          if (!d.ok) throw new Error(d.error || 'rename_failed');
          s.title = d.title;
          if (isActiveSession(s.id)) headerSessionName.textContent = s.title;
          renderSessionList();
        }).catch(function() {
          if (isActiveSession(s.id)) appendMessageEl('system', '\u26a0 Sitzung konnte nicht umbenannt werden.');
        });
      });
      var archive = document.createElement('button');
      archive.type = 'button';
      archive.className = 'session-action' + (s.archived ? '' : ' danger');
      archive.textContent = s.archived ? 'WIEDERHERSTELLEN' : 'ARCHIVIEREN';
      archive.addEventListener('click', function(ev) {
        ev.stopPropagation();
        var restoring = !!s.archived;
        if (!restoring && state.pendingBySession[s.id]) {
          window.alert('Diese Sitzung verarbeitet noch eine Antwort. Bitte warte bis zum Abschluss.');
          return;
        }
        if (!restoring && !window.confirm('Sitzung "' + String(s.title || '') + '" archivieren? Der Verlauf bleibt erhalten.')) return;
        var wasActive = isActiveSession(s.id);
        var operation = restoring ? dbRestoreSession(s.id) : dbArchiveSession(s.id);
        operation.then(function(d) {
          if (!d.ok) throw new Error(d.error || 'archive_failed');
          if (!restoring) delete state.messageCacheBySession[s.id];
          return dbLoadSessions();
        }).then(function() {
          if (wasActive && !restoring) {
            var next = state.sessions.find(function(item) { return !item.archived && String(item.id) !== String(s.id); });
            state.activeSessionId = next ? next.id : null;
            if (next) {
              selectSession(next.id);
              return null;
            }
            return dbCreateSession('SITZUNG 1').then(function(created) { selectSession(created.id); });
          } else {
            renderSessionList();
          }
        }).catch(function() {
          window.alert(restoring
            ? 'Sitzung konnte nicht wiederhergestellt werden.'
            : 'Sitzung konnte nicht archiviert werden.');
        });
      });

      var permanentlyDelete = null;
      if (s.archived) {
        permanentlyDelete = document.createElement('button');
        permanentlyDelete.type = 'button';
        permanentlyDelete.className = 'session-action permanent-danger';
        permanentlyDelete.textContent = 'ENDGÜLTIG LÖSCHEN';
        permanentlyDelete.addEventListener('click', function(ev) {
          ev.stopPropagation();
          var title = String(s.title || 'Sitzung');
          if (!window.confirm(
            'Sitzung "' + title + '" endgültig löschen? Nachrichten, Browser-Recherchen und zugehörige Uploads werden aus CoreUI entfernt.'
          )) return;
          var proof = window.prompt('Zur Bestätigung LÖSCHEN eingeben:');
          proof = String(proof || '').trim().toLocaleUpperCase('de-DE');
          if (proof !== 'LÖSCHEN' && proof !== 'LOESCHEN') {
            if (proof !== '') window.alert('Löschung abgebrochen: Bestätigung stimmt nicht.');
            return;
          }

          permanentlyDelete.disabled = true;
          dbDeleteSession(s.id).then(function(d) {
            if (!d.ok || !d.deleted) throw new Error(d.error || 'delete_failed');
            clearSessionRuntime(s.id);
            return dbLoadSessions().then(function() {
              renderSessionList();
              var counts = d.counts || {};
              var fileWarning = (Number(counts.files_failed) || 0) > 0
                ? ' Hinweis: ' + String(Number(counts.files_failed)) + ' Datei(en) konnten nicht entfernt werden; bitte den Server-Preflight prüfen.'
                : '';
              window.alert(
                'Sitzung endgültig gelöscht. ' + String(Number(counts.messages_deleted) || 0)
                + ' Nachrichten und ' + String(Number(counts.browse_jobs_deleted) || 0)
                + ' Browser-Jobs wurden aus der Datenbank entfernt.' + fileWarning
              );
            });
          }).catch(function(err) {
            var code = String((err && err.message) || '');
            if (code === 'session_busy') {
              window.alert('Die Sitzung hat noch eine laufende Browser-Recherche und kann erst danach gelöscht werden.');
            } else if (code === 'session_must_be_archived') {
              window.alert('Die Sitzung muss vor dem endgültigen Löschen archiviert sein.');
            } else {
              window.alert('Sitzung konnte nicht endgültig gelöscht werden.');
            }
          }).finally(function() {
            permanentlyDelete.disabled = false;
          });
        });
      }
      if (!s.archived) actions.appendChild(rename);
      actions.appendChild(archive);
      if (permanentlyDelete) actions.appendChild(permanentlyDelete);

      li.appendChild(titleEl);
      li.appendChild(metaEl);
      li.appendChild(actions);

      li.addEventListener('click', function() {
        if (s.archived) return;
        selectSession(s.id);
        closeDrawer();
      });
      sessionListEl.appendChild(li);
    });
  }

  function selectSession(sessionId) {
    if (state.bgTimer) clearTimeout(state.bgTimer);
    stopCountdown();
    state.viewEpoch++;
    state.activeSessionId = String(sessionId || '');
    var ses = activeSession();
    if (!ses) return;

    rememberActiveSession(ses.id);
    headerSessionName.textContent = ses.title;
    messagesInner.innerHTML = '';
    state.typingEl = null;
    state.thinkingPanelEl = null;
    updateActivePendingUi();
    renderSessionList();

    // Bereits bestaetigte Daten sofort aus dem sitzungseigenen Cache anzeigen.
    // Ein kurzzeitiger API-Fehler darf einen vorhandenen Verlauf nicht optisch
    // verschwinden lassen.
    if (Array.isArray(state.messageCacheBySession[ses.id])) renderHistory(ses);
    loadChatHistory(ses);
  }

  function mergeSessionMessages(sessionId, rows) {
    var merged = {};
    (state.messageCacheBySession[sessionId] || []).concat(rows || []).forEach(function(m) {
      if (m && Number(m.id) > 0) merged[String(m.id)] = m;
    });
    state.messageCacheBySession[sessionId] = Object.keys(merged).map(function(id) {
      return merged[id];
    }).sort(function(a, b) { return Number(a.id) - Number(b.id); });
  }

  function renderMessageRecord(m) {
    var text = m.message || '';
    if (m.is_ember === true || isEmberMsg(m)) {
      appendMessageEl('ember', text, state.assistantName, m.thinking_content || null, m.attachments || m.attachment || null, m.created_at);
    } else if (String(m.character_name || '').toLowerCase() === 'system' || String(m.character_id || '').toLowerCase() === 'system') {
      appendMessageEl('system', text);
    } else {
      appendMessageEl('user', stripEmberPrefix(text), state.userDisplayName || m.character_name || state.charName, null, m.attachments || m.attachment || null, m.created_at);
    }
  }

  function renderHistory(ses) {
    if (!isActiveSession(ses.id)) return;
    messagesInner.innerHTML = '';
    state.typingEl = null;
    state.thinkingPanelEl = null;
    if (state.historyHasMoreBySession[ses.id]) {
      var older = document.createElement('button');
      older.type = 'button';
      older.className = 'history-load-older';
      older.textContent = 'AELTERE NACHRICHTEN LADEN';
      older.disabled = !!state.pendingBySession[ses.id];
      older.addEventListener('click', function() {
        older.disabled = true;
        loadChatHistory(ses, state.historyOldestBySession[ses.id] || 0, true);
      });
      messagesInner.appendChild(older);
    }
    (state.messageCacheBySession[ses.id] || []).forEach(renderMessageRecord);
    if (!state.messageCacheBySession[ses.id] || !state.messageCacheBySession[ses.id].length) {
      appendMessageEl('system', 'ARCHNET VERBINDUNG HERGESTELLT. EMBER INTERFACE AKTIV.');
    }
    if (state.pendingBySession[ses.id]) {
      showTypingIndicator();
      startCountdown();
      setStatus('waiting', 'EMBER ANTWORTET\u2026');
    }
  }

  // Exakte, serverseitig gefilterte History einer einzigen Sitzung.
  function loadChatHistory(ses, beforeId, prepend) {
    if (!state.characterId || !ses) return Promise.resolve();
    var epoch = state.viewEpoch;
    var url = API_BASE + '/console_messages.php?session_id=' + encodeURIComponent(ses.id)
      + '&character_id=' + encodeURIComponent(state.characterId)
      + '&limit=60';
    if (beforeId > 0) url += '&before_id=' + Number(beforeId);

    if (!prepend) setStatus('', 'LADE\u2026');
    return fetch(url, { credentials: 'include' })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d.ok || String(d.session_id || '') !== String(ses.id) || !Array.isArray(d.messages)) {
        throw new Error(d.error || 'history_failed');
      }
      if (!prepend) state.messageCacheBySession[ses.id] = [];
      mergeSessionMessages(ses.id, d.messages);
      state.historyHasMoreBySession[ses.id] = !!d.has_more_before;
      state.historyOldestBySession[ses.id] = Number(d.oldest_id) || state.historyOldestBySession[ses.id] || 0;
      var newest = Number(d.last_id) || 0;
      if (newest > Number(ses.last_read_message_id || 0)) ses.last_read_message_id = newest;
      if (newest > Number(ses.last_message_id || 0)) ses.last_message_id = newest;
      ses.unread_count = 0;
      if (!isActiveSession(ses.id) || epoch !== state.viewEpoch) return;
      renderHistory(ses);
      if (!state.pendingBySession[ses.id]) setStatus('online', 'VERBUNDEN');
      if (prepend) {
        var first = messagesInner.querySelector('.msg-row');
        if (first) first.scrollIntoView({ block: 'start' });
      } else {
        scrollToBottom();
      }
      renderSessionList();
      scheduleBackgroundPoll();
    })
    .catch(function() {
      if (!isActiveSession(ses.id) || epoch !== state.viewEpoch) return;
      var hasCachedHistory = Array.isArray(state.messageCacheBySession[ses.id])
        && state.messageCacheBySession[ses.id].length > 0;
      if (hasCachedHistory) renderHistory(ses);
      else messagesInner.innerHTML = '';
      setStatus('error', 'LADEFEHLER');
      appendMessageEl('system', hasCachedHistory
        ? '\u26a0 Aktualisierung fehlgeschlagen. Der zuletzt bestaetigte Verlauf bleibt sichtbar.'
        : '\u26a0 Sitzung konnte nicht geladen werden. Bitte Preflight und Migration pruefen.');
      scheduleBackgroundPoll();
    });
  }

  // ── Hilfsfunktionen ──────────────────────────────────────
  function isEmberMsg(m) {
    return (m.character_name && m.character_name.toLowerCase() === 'ember') ||
           m.is_ember === true;
  }

  function stripEmberPrefix(text) {
    // "@Ember " am Anfang entfernen (case-insensitive)
    return text.replace(/^@ember\s*/i, '');
  }

  function profileInitials(value, fallback) {
    var clean = String(value || '').trim();
    if (!clean) return fallback;
    var parts = clean.split(/\s+/).filter(Boolean);
    if (parts.length > 1) return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    return clean.substring(0, 2).toUpperCase();
  }

  function applyMessageAvatar(element, role, name) {
    var isUser = role === 'user';
    var url = isUser ? state.userAvatarUrl : state.assistantAvatarUrl;
    element.textContent = profileInitials(name, isUser ? 'DU' : 'EM');
    element.classList.toggle('has-image', !!url);
    element.style.backgroundImage = url ? ('url("' + String(url).replace(/"/g, '%22') + '")') : '';
  }

  // ── DOM: Nachricht einfügen ──────────────────────────────
  function appendMessageEl(role, text, name, thinkingText, attachment, createdAt) {
    var row = document.createElement('div');
    row.className = 'msg-row ' + role;

    if (role === 'system') {
      var bubble = document.createElement('div');
      bubble.className = 'msg-bubble';
      bubble.textContent = text;
      row.appendChild(bubble);
      messagesInner.appendChild(row);
      return row;
    }

    // Avatar
    var avatar = document.createElement('div');
    avatar.className = 'msg-avatar';
    applyMessageAvatar(avatar, role, role === 'user'
      ? (name || state.userDisplayName || state.charName || 'DU')
      : (state.assistantName || 'Ember'));
    row.appendChild(avatar);

    // Content wrapper
    var content = document.createElement('div');
    content.className = 'msg-content';

    if (role === 'ember') {
      thinkingText = safeThinkingText(thinkingText);
      text = safeReplyText(text);
    }

    // Denkstatus-Panel: falls laufendes Panel vorhanden, Text dort eintragen
    // sonst neue Panel-Row bauen (für History)
    if (role === 'ember' && thinkingText && thinkingText.trim()) {
      if (state.thinkingPanelEl) {
        // Laufendes Panel befuellen und als abgeschlossen markieren (Toggle: DENKSTATUS)
        var existingBody = state.thinkingPanelEl.querySelector('.thinking-body');
        if (existingBody) existingBody.textContent = thinkingText;
        var runToggle = state.thinkingPanelEl.querySelector('.thinking-toggle');
        if (runToggle) {
          runToggle.classList.remove('running');
          if (uiPreferences.thinkingExpanded === true) {
            runToggle.classList.add('open');
            runToggle.setAttribute('aria-expanded', 'true');
            if (existingBody) existingBody.classList.add('open');
          }
          var runIcon = runToggle.querySelector('.thinking-toggle-icon');
          var runLabel = runToggle.querySelector('span:last-child');
          if (runIcon) runIcon.textContent = '▶';
          if (runLabel) runLabel.textContent = 'DENKSTATUS';
        }
        state.thinkingPanelEl = null;
      } else {
        // History: neue Panel-Row vor dieser Bubble einfügen
        var tp = buildThinkingPanel(thinkingText);
        content.appendChild(tp);
      }
    }

    var nameEl = document.createElement('div');
    nameEl.className = 'msg-name';
    nameEl.textContent = role === 'user'
      ? (name || state.userDisplayName || state.charName || 'DU').toUpperCase()
      : (state.assistantName || 'Ember').toUpperCase();
    content.appendChild(nameEl);

    var bubble = document.createElement('div');
    bubble.className = 'msg-bubble';
    bubble.textContent = text;
    var attachments = Array.isArray(attachment) ? attachment : (attachment ? [attachment] : []);
    // Leere Blase unterdruecken, wenn nur Anhaenge gesendet wurden.
    if (text || attachments.length === 0) content.appendChild(bubble);

    // v1.1.1.89: Anhang darstellen. Bilder direkt, alles andere als Karte
    // mit Link auf den authentifizierten Auslieferungs-Endpunkt.
    attachments.slice(0, MAX_MESSAGE_ATTACHMENTS).forEach(function (item) {
      content.appendChild(buildAttachmentEl(item));
    });

    // Zeitstempel
    var timeEl = document.createElement('div');
    timeEl.className = 'msg-time';
    var now = createdAt ? new Date(createdAt) : new Date();
    if (Number.isNaN(now.getTime())) now = new Date();
    timeEl.textContent = now.toLocaleTimeString('de-DE', {hour:'2-digit', minute:'2-digit'})
      + ', ' + now.toLocaleDateString('de-DE', {day:'2-digit', month:'2-digit', year:'2-digit'});
    content.appendChild(timeEl);

    row.appendChild(content);
    messagesInner.appendChild(row);
    return row;
  }

  // Anhang-Element bauen (v1.1.1.89)
  function buildAttachmentEl(att) {
    var kind = att.kind || 'document';
    var url  = att.url || (att.uuid ? (API_BASE + '/console_media.php?uuid=' + encodeURIComponent(att.uuid)) : '');

    if (kind === 'image' && (att.image_url || url)) {
      var img = document.createElement('img');
      img.className = 'msg-image';
      img.src = att.image_url || url;
      img.alt = att.name || 'Bild';
      img.loading = 'lazy';
      return img;
    }

    if (kind === 'video') {
      var vid = document.createElement('video');
      vid.className = 'msg-image';
      vid.src = url;
      vid.controls = true;
      vid.preload = 'metadata';
      return vid;
    }

    if (kind === 'audio') {
      var aud = document.createElement('audio');
      aud.className = 'msg-image';
      aud.src = url;
      aud.controls = true;
      aud.preload = 'metadata';
      return aud;
    }

    var a = document.createElement('a');
    a.className = 'msg-file';
    a.href = url;
    a.target = '_blank';
    a.rel = 'noopener';

    var ic = document.createElement('span');
    ic.className = 'msg-file-icon';
    ic.textContent = KIND_ICON[kind] || '\uD83D\uDCCE';
    a.appendChild(ic);

    var meta = document.createElement('div');
    meta.className = 'msg-file-meta';
    var nm = document.createElement('span');
    nm.className = 'msg-file-name';
    nm.textContent = att.name || 'Datei';
    var sb = document.createElement('span');
    sb.className = 'msg-file-sub';
    sb.textContent = formatBytes(att.size) + (att.mime ? (' \u00b7 ' + att.mime) : '');
    meta.appendChild(nm);
    meta.appendChild(sb);
    a.appendChild(meta);
    return a;
  }

  // Aufklappbares, sicheres Denkstatus-Panel bauen
  function buildThinkingPanel(thinkingText) {
    var panel = document.createElement('div');
    panel.className = 'thinking-panel';

    var toggle = document.createElement('button');
    var startsOpen = uiPreferences.thinkingExpanded === true;
    toggle.className = 'thinking-toggle' + (startsOpen ? ' open' : '');
    toggle.setAttribute('aria-expanded', startsOpen ? 'true' : 'false');

    var icon = document.createElement('span');
    icon.className = 'thinking-toggle-icon';
    icon.textContent = '▶';

    var label = document.createElement('span');
    label.textContent = 'DENKSTATUS';

    toggle.appendChild(icon);
    toggle.appendChild(label);

    var body = document.createElement('div');
    body.className = 'thinking-body' + (startsOpen ? ' open' : '');
    body.textContent = thinkingText;

    toggle.addEventListener('click', function() {
      var isOpen = body.classList.contains('open');
      if (isOpen) {
        body.classList.remove('open');
        toggle.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        icon.textContent = '▶';
      } else {
        body.classList.add('open');
        toggle.classList.add('open');
        toggle.setAttribute('aria-expanded', 'true');
        icon.textContent = '▶';
        scrollToBottom();
      }
    });

    panel.appendChild(toggle);
    panel.appendChild(body);
    return panel;
  }

  // Sicherer Status-Platzhalter waehrend Ember antwortet
  function showThinkingRunning() {
    if (state.typingEl) return;

    // Thinking-Panel als eigene persistente Row (bleibt nach Antwort)
    var thinkRow = document.createElement('div');
    thinkRow.className = 'msg-row ember';
    var thinkAvatar = document.createElement('div');
    thinkAvatar.className = 'msg-avatar';
    applyMessageAvatar(thinkAvatar, 'ember', state.assistantName || 'Ember');
    thinkRow.appendChild(thinkAvatar);
    var thinkContent = document.createElement('div');
    thinkContent.className = 'msg-content';
    var panel = document.createElement('div');
    panel.className = 'thinking-panel';
    var toggle = document.createElement('button');
    toggle.className = 'thinking-toggle running';
    var icon = document.createElement('span');
    icon.className = 'thinking-toggle-icon';
    icon.textContent = '◌';
    var label = document.createElement('span');
    label.textContent = 'DENKSTATUS AKTIV…';
    toggle.appendChild(icon);
    toggle.appendChild(label);
    var body = document.createElement('div');
    body.className = 'thinking-body';
    body.textContent = 'ANFRAGE WIRD ANALYSIERT';
    toggle.addEventListener('click', function() {
      var isOpen = body.classList.contains('open');
      body.classList.toggle('open', !isOpen);
      toggle.classList.toggle('open', !isOpen);
      scrollToBottom();
    });
    panel.appendChild(toggle);
    panel.appendChild(body);
    thinkContent.appendChild(panel);
    thinkRow.appendChild(thinkContent);
    messagesInner.appendChild(thinkRow);
    state.thinkingPanelEl = panel;

    // Separate Typing-Row (wird nach Antwort entfernt)
    var row = document.createElement('div');
    row.className = 'msg-row ember';
    var avatar = document.createElement('div');
    avatar.className = 'msg-avatar';
    applyMessageAvatar(avatar, 'ember', state.assistantName || 'Ember');
    row.appendChild(avatar);
    var content = document.createElement('div');
    content.className = 'msg-content';
    var nameEl = document.createElement('div');
    nameEl.className = 'msg-name';
    nameEl.textContent = (state.assistantName || 'Ember').toUpperCase();
    content.appendChild(nameEl);
    var bubble = document.createElement('div');
    bubble.className = 'msg-bubble';
    bubble.innerHTML = '<div class="typing-dots"><span></span><span></span><span></span></div>';
    content.appendChild(bubble);
    row.appendChild(content);
    messagesInner.appendChild(row);
    state.typingEl = row;
    scrollToBottom();
  }

  function showTypingIndicator() {
    showThinkingRunning();
  }

  function removeTypingIndicator() {
    // Typing-Row komplett entfernen - Thinking-Panel bleibt als eigene Row erhalten
    if (state.typingEl && state.typingEl.parentNode) {
      state.typingEl.parentNode.removeChild(state.typingEl);
    }
    state.typingEl = null;
    // Running-Panel auf abgeschlossen setzen
    if (state.thinkingPanelEl) {
      var toggle = state.thinkingPanelEl.querySelector('.thinking-toggle');
      var body = state.thinkingPanelEl.querySelector('.thinking-body');
      if (toggle) {
        toggle.classList.remove('running');
        if (uiPreferences.thinkingExpanded === true) {
          toggle.classList.add('open');
          toggle.setAttribute('aria-expanded', 'true');
          if (body) body.classList.add('open');
        }
        var icon = toggle.querySelector('.thinking-toggle-icon');
        var label = toggle.querySelector('span:last-child');
        if (icon) icon.textContent = '▶';
        if (label) label.textContent = 'DENKSTATUS';
      }
      state.thinkingPanelEl = null;
    }
  }

  function scrollToBottom() {
    requestAnimationFrame(function() {
      messagesArea.scrollTop = messagesArea.scrollHeight;
    });
  }

  var MAX_WAIT_SEC = 310;

  function startCountdown() {
    stopCountdown();
    var el = document.createElement('div');
    el.className = 'ember-countdown';
    el.textContent = '⏳ ' + MAX_WAIT_SEC + 's';
    messagesInner.appendChild(el);
    state.countdownEl = el;
    scrollToBottom();
    var remaining = MAX_WAIT_SEC;
    state.countdownTimer = setInterval(function() {
      remaining--;
      if (remaining <= 0) {
        // Nicht verschwinden lassen - lange (Web-)Turns laufen noch. stopCountdown räumt am Ende auf.
        if (state.countdownEl) state.countdownEl.textContent = '⏳ noch dabei…';
        clearInterval(state.countdownTimer);
        state.countdownTimer = null;
        return;
      }
      if (state.countdownEl) state.countdownEl.textContent = '⏳ ' + remaining + 's';
    }, 1000);
  }

  function stopCountdown() {
    if (state.countdownTimer) { clearInterval(state.countdownTimer); state.countdownTimer = null; }
    if (state.countdownEl && state.countdownEl.parentNode) {
      state.countdownEl.parentNode.removeChild(state.countdownEl);
    }
    state.countdownEl = null;
  }

  function showResponseTime(row, sessionId) {
    var sentAt = sessionId
      ? Number(state.sendTimestampBySession[String(sessionId)] || 0)
      : Number(state.sendTimestamp || 0);
    if (!sentAt) return;
    var elapsed = Math.round((Date.now() - sentAt) / 1000);
    if (sessionId) delete state.sendTimestampBySession[String(sessionId)];
    state.sendTimestamp = 0;
    var timeEl = row ? row.querySelector('.msg-time') : null;
    if (timeEl) {
      timeEl.textContent = '⏱ ' + elapsed + 's  ·  ' + timeEl.textContent;
    }
  }

  // ── Anhang (v1.1.1.89) ───────────────────────────────────
  var KIND_ICON = { image:'\uD83D\uDDBC', video:'\uD83C\uDFAC', audio:'\uD83C\uDFB5',
                    archive:'\uD83D\uDDDC', document:'\uD83D\uDCC4' };

  function formatBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
    if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
    return (n / 1073741824).toFixed(2) + ' GB';
  }

  function attachmentSlotsUsed() {
    return pendingFiles.length + uploadQueue.length + (uploadCurrentFile ? 1 : 0);
  }

  function renderAttachmentList() {
    if (!attachList) return;
    attachList.innerHTML = '';
    pendingFiles.forEach(function (item, index) {
      var chip = document.createElement('div');
      chip.className = 'composer-attach';

      var icon = document.createElement('span');
      icon.className = 'attach-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = KIND_ICON[item.kind] || '\uD83D\uDCCE';

      var meta = document.createElement('span');
      meta.className = 'attach-meta';
      var name = document.createElement('span');
      name.className = 'attach-name';
      name.textContent = item.name || 'Datei';
      var sub = document.createElement('span');
      sub.className = 'attach-sub';
      sub.textContent = formatBytes(item.size) + ' \u00b7 bereit';
      meta.appendChild(name);
      meta.appendChild(sub);

      var remove = document.createElement('button');
      remove.className = 'attach-remove';
      remove.type = 'button';
      remove.setAttribute('aria-label', (item.name || 'Anhang') + ' entfernen');
      remove.textContent = '\u00d7';
      remove.addEventListener('click', function () {
        var removed = pendingFiles.splice(index, 1)[0] || null;
        renderAttachmentList();
        deleteUnreferencedUpload(removed);
      });

      chip.appendChild(icon);
      chip.appendChild(meta);
      chip.appendChild(remove);
      attachList.appendChild(chip);
    });
    attachList.hidden = pendingFiles.length === 0;
    if (btnAttach) btnAttach.disabled = attachmentSlotsUsed() >= MAX_MESSAGE_ATTACHMENTS;
  }

  function showUploadState(file, sub) {
    if (!attachUploadState) return;
    attachUploadState.hidden = !file;
    if (attachUploadName) attachUploadName.textContent = file ? file.name : '';
    if (attachUploadSub) attachUploadSub.textContent = sub || '';
    if (attachBarFill) attachBarFill.style.width = file ? '0%' : '0%';
  }

  function clearAttachments() {
    if (uploadXhr) { try { uploadXhr.abort(); } catch (e) {} uploadXhr = null; }
    pendingFiles = [];
    uploadQueue = [];
    uploadCurrentFile = null;
    if (fileInput) fileInput.value = '';
    showUploadState(null, '');
    renderAttachmentList();
  }

  function restoreAttachments(items) {
    (items || []).forEach(function (item) {
      if (!item || !item.uuid) return;
      if (pendingFiles.some(function (saved) { return saved.uuid === item.uuid; })) return;
      if (pendingFiles.length < MAX_MESSAGE_ATTACHMENTS) pendingFiles.push(item);
    });
    renderAttachmentList();
  }

  function deleteUnreferencedUpload(item) {
    if (!item || !item.uuid || !state.csrfToken) return;
    fetch(API_BASE + '/console_upload.php', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-CoreUI-CSRF': state.csrfToken
      },
      body: JSON.stringify({ action: 'delete_unreferenced', uuid: item.uuid })
    }).catch(function () {
      // Die sichtbare Auswahl bleibt entfernt. Ein spaeterer Wartungslauf kann
      // einen seltenen verwaisten Upload gefahrlos anhand fehlender Referenzen loeschen.
    });
  }

  // Fehlercodes des Endpunkts in Klartext. Ohne das steht bei einem zu grossen
  // Video nur "Fehler" und man sucht an der falschen Stelle.
  var UPLOAD_ERRORS = {
    file_too_large:            'Datei ist zu gross.',
    file_too_large_ini:        'Datei ueberschreitet das Serverlimit.',
    post_too_large:            'Upload ueberschreitet das Serverlimit (post_max_size).',
    storage_dir_unavailable:   'Ablageordner fehlt (files/console_media).',
    storage_dir_not_writable:  'Ablageordner ist nicht beschreibbar.',
    not_authenticated:         'Nicht angemeldet.',
    empty_file:                'Datei ist leer.',
    move_failed:               'Datei konnte nicht gespeichert werden.',
    db_insert_failed:          'Datenbankfehler beim Speichern.'
  };

  function processUploadQueue() {
    if (uploadXhr || uploadCurrentFile || uploadQueue.length === 0) {
      renderAttachmentList();
      return;
    }

    var file = uploadQueue.shift();
    uploadCurrentFile = file;
    showUploadState(file, 'wird hochgeladen\u2026');
    if (btnAttach) btnAttach.disabled = true;

    var fd = new FormData();
    fd.append('file', file);
    if (state.characterId) fd.append('character_id', state.characterId);

    var xhr = new XMLHttpRequest();
    uploadXhr = xhr;
    xhr.open('POST', API_BASE + '/console_upload.php', true);
    xhr.withCredentials = true;

    // Fortschritt ist bei 256-MB-Uploads keine Kosmetik: ohne Anzeige wirkt
    // die Console minutenlang eingefroren.
    xhr.upload.onprogress = function(e) {
      if (!e.lengthComputable || !attachBarFill) return;
      var pct = Math.round((e.loaded / e.total) * 100);
      attachBarFill.style.width = pct + '%';
      if (attachUploadSub) attachUploadSub.textContent = pct + '% \u00b7 ' + formatBytes(e.loaded) + ' / ' + formatBytes(e.total);
    };

    xhr.onload = function() {
      uploadXhr = null;
      uploadCurrentFile = null;
      showUploadState(null, '');
      var d = null;
      try { d = JSON.parse(xhr.responseText || '{}'); } catch (e) { d = null; }

      if (xhr.status >= 200 && xhr.status < 300 && d && d.ok) {
        if (pendingFiles.length < MAX_MESSAGE_ATTACHMENTS) pendingFiles.push(d);
        renderAttachmentList();
        processUploadQueue();
        return;
      }
      var code = (d && d.error) || ('HTTP_' + xhr.status);
      var msg = UPLOAD_ERRORS[code] || ('Upload fehlgeschlagen (' + code + ')');
      if (code === 'file_too_large' && d && d.max_mb) msg = 'Datei ist zu gross (max. ' + d.max_mb + ' MB).';
      appendMessageEl('system', '\u26a0 ' + msg);
      scrollToBottom();
      renderAttachmentList();
      processUploadQueue();
    };

    xhr.onerror = function() {
      uploadXhr = null;
      uploadCurrentFile = null;
      showUploadState(null, '');
      appendMessageEl('system', '\u26a0 Upload fehlgeschlagen (Verbindung).');
      scrollToBottom();
      renderAttachmentList();
      processUploadQueue();
    };

    xhr.send(fd);
  }

  function queueFiles(fileList) {
    var selected = Array.prototype.slice.call(fileList || []);
    if (!selected.length) return;
    if (state.waitingEmber) {
      appendMessageEl('system', '\u231b Ember antwortet gerade\u2026 Anhaenge bitte danach.');
      scrollToBottom();
      return;
    }
    var free = Math.max(0, MAX_MESSAGE_ATTACHMENTS - attachmentSlotsUsed());
    if (selected.length > free) {
      appendMessageEl('system', '\u26a0 Pro Nachricht sind maximal ' + MAX_MESSAGE_ATTACHMENTS + ' Dateien möglich.');
      scrollToBottom();
    }
    selected.slice(0, free).forEach(function (file) { uploadQueue.push(file); });
    if (fileInput) fileInput.value = '';
    renderAttachmentList();
    processUploadQueue();
  }

  if (btnAttach) btnAttach.addEventListener('click', function() { if (fileInput) fileInput.click(); });
  if (fileInput) fileInput.addEventListener('change', function(e) {
    queueFiles(e.target && e.target.files ? e.target.files : []);
  });

  // ── Nachricht senden ─────────────────────────────────────
  function sendMessage(text) {
    // v1.1.1.89: Ein Anhang ohne Begleittext ist eine gueltige Nachricht.
    if ((!text || !text.trim()) && pendingFiles.length === 0) return;
    if (uploadXhr || uploadCurrentFile || uploadQueue.length > 0) {
      appendMessageEl('system', '\u231b Upload laeuft noch\u2026');
      scrollToBottom();
      return;
    }
    if (!state.characterId) {
      appendMessageEl('system', '\u26a0 Kein Charakter aktiv. Bitte erst im Spiel einloggen.');
      return;
    }
    var ses = activeSession();
    if (!ses) return;
    if (state.pendingBySession[ses.id]) {
      appendMessageEl('system', '\u231b Ember antwortet gerade\u2026');
      scrollToBottom();
      return;
    }
    var sessionId = String(ses.id);

    var userText = (text || '').trim();
    var atts = pendingFiles.slice(0, MAX_MESSAGE_ATTACHMENTS);
    var sentCreatedAt = new Date().toISOString();
    inputEl.value = '';
    btnSend.disabled = true;

    // @Ember Prefix nur intern - für Anzeige ohne Prefix
    var msgToSend = EMBER_PREFIX + userText;
    appendMessageEl('user', userText, state.userDisplayName || state.charName, null, atts, sentCreatedAt);
    clearAttachments();
    scrollToBottom();

    setStatus('waiting', 'EMBER ANTWORTET\u2026');
    state.pendingBySession[sessionId] = true;
    state.waitingEmber = true;
    state.sendTimestamp = Date.now();
    state.sendTimestampBySession[sessionId] = state.sendTimestamp;
    showTypingIndicator();
    startCountdown();

    fetch(API_BASE + '/chat.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action:       'send',
        channel:      CHANNEL,
        session_id:   sessionId,
        character_id: state.characterId,
        message:      msgToSend,
        attachment_uuids: atts.map(function (item) { return item.uuid; }),
        ember_client_async: USE_STREAM   // Streaming: Server speichert nur, Stream-Endpunkt generiert
      })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d.ok || (d.session_id && String(d.session_id) !== sessionId)) {
        var errMap = {
          'character_not_found': '\u26a0 Charakter nicht gefunden.',
          'rate_limited':        '\u26a0 Zu viele Nachrichten.',
          'burst_limit':         '\u26a0 Zu schnell. Kurze Pause.',
          'muted':               '\u26a0 Charakter stummgeschaltet.',
          'sender_muted':        '\u26a0 Charakter stummgeschaltet.',
          'invalid_message':     '\u26a0 Nachricht ungültig.',
          'too_many_attachments':'\u26a0 Maximal zehn Dateien pro Nachricht.',
          'attachment_not_found':'\u26a0 Mindestens ein Anhang ist nicht mehr verfügbar.',
          'attachment_migration_required':'\u26a0 Die Anhang-Migration fehlt auf dem Server.',
          'message_store_failed':'\u26a0 Nachricht und Anhänge konnten nicht gespeichert werden.',
          'too_short':           '\u26a0 Nachricht zu kurz.',
          'banned':              '\u26a0 Konto gesperrt.',
          'not_authenticated':   '\u26a0 Sitzung beendet.'
        };
        delete state.pendingBySession[sessionId];
        restoreAttachments(atts);
        if (isActiveSession(sessionId)) {
          stopCountdown();
          removeTypingIndicator();
          updateActivePendingUi();
          setStatus('online', 'VERBUNDEN');
          appendMessageEl('system', errMap[d.error] || ('\u26a0 ' + (d.error || 'Fehler')));
          scrollToBottom();
        }
        if (d.error === 'banned' || d.error === 'not_authenticated') {
          setTimeout(function () { location.replace('login.html'); }, 900);
        }
        return;
      }

      var sentMsgId = d.id || 0;
      state.pendingBySession[sessionId] = Number(sentMsgId) || true;
      if (sentMsgId > Number(ses.last_message_id || 0)) ses.last_message_id = sentMsgId;
      if (sentMsgId > Number(ses.last_read_message_id || 0)) ses.last_read_message_id = sentMsgId;
      ses.client_cursor = Math.max(Number(ses.client_cursor) || 0, sentMsgId);
      ses.unread_count = 0;
      ses.updated_at = sentCreatedAt;
      state.sessions.sort(function(a, b) {
        return new Date(b.updated_at || 0).getTime() - new Date(a.updated_at || 0).getTime();
      });
      dbMarkRead(sessionId, sentMsgId);
      if (sentMsgId > 0) {
        mergeSessionMessages(sessionId, [{
          id: sentMsgId,
          session_id: sessionId,
          reply_to_id: 0,
          character_id: state.characterId,
          character_name: state.charName,
          message: EMBER_PREFIX + userText,
          is_ember: false,
          thinking_content: null,
          attachments: atts,
          attachment: atts[0] || null,
          created_at: sentCreatedAt
        }]);
      }
      if (d.session_title) {
        ses.title = d.session_title;
        if (isActiveSession(sessionId)) headerSessionName.textContent = ses.title;
      }
      renderSessionList();

      // Phase 3a: Streaming, wenn der Server bestätigt dass Ember antwortet (ember_pending).
      if (USE_STREAM && d.ember_pending) {
        openStreamForReply(ses, sentMsgId);
      } else {
        startFastPoll(ses, sentMsgId);
      }
    })
    .catch(function() {
      delete state.pendingBySession[sessionId];
      restoreAttachments(atts);
      if (isActiveSession(sessionId)) {
        stopCountdown();
        removeTypingIndicator();
        updateActivePendingUi();
        setStatus('error', 'FEHLER');
        appendMessageEl('system', '\u26a0 Verbindungsfehler.');
        scrollToBottom();
      }
    });
  }

  // ── Phase 3a: SSE mit sicherem Denkstatus ─────────────────
  // Oeffnet einen EventSource und fuellt Status + Antwort live. Rohe Thinking-
  // Tokens werden absichtlich verworfen, auch wenn ein alter Server sie sendet.
  // Fällt bei jedem Problem auf den normalen Poll zurück (der Server generiert
  // serverseitig zu Ende und legt die Antwort in der DB ab).
  function openStreamForReply(ses, userMsgId) {
    var sessionId = String(ses.id);
    var url = API_BASE + '/console_stream.php'
      + '?character_id=' + encodeURIComponent(state.characterId)
      + '&session_id=' + encodeURIComponent(sessionId)
      + '&after_id=' + (userMsgId || 0);

    var es;
    try { es = new EventSource(url, { withCredentials: true }); }
    catch (e) { startFastPoll(ses, userMsgId); return; }

    var streamDone = false;

    function panelBody() {
      return isActiveSession(sessionId) && state.thinkingPanelEl
        ? state.thinkingPanelEl.querySelector('.thinking-body')
        : null;
    }
    function fallbackPoll() {
      if (streamDone) return;
      streamDone = true;
      clearTimeout(watchdog);
      try { es.close(); } catch (e) {}
      // Live-Reste so lassen - der Poll-Pfad (v1.1.1.17) finalisiert sauber aus der DB.
      startFastPoll(ses, userMsgId);
    }

    var watchdog;
    function armWatchdog() { clearTimeout(watchdog); watchdog = setTimeout(fallbackPoll, STREAM_IDLE_MS); }
    armWatchdog();

    es.addEventListener('thinking', function() {
      // Rollendes Update mit einem aelteren Endpoint: Payload strikt ignorieren.
      armWatchdog();
    });
    es.addEventListener('progress', function(ev) {
      armWatchdog();
      try {
        if (!isActiveSession(sessionId)) return;
        var d = JSON.parse(ev.data).text || '';
        var b = panelBody();
        if (b) {
          appendSafeThinkingStatus(b, d);
          scrollToBottom();
        }
      } catch (e) {}
    });
    es.addEventListener('heartbeat', function() {
      armWatchdog();
    });
    es.addEventListener('token', function() {
      // Kompatibilitaet mit alten Servern: rohe Fragmente niemals anzeigen.
      armWatchdog();
    });
    es.addEventListener('busy', function() {
      // Doppel-Trigger: es läuft schon eine Generierung -> Poll holt das Ergebnis.
      fallbackPoll();
    });
    es.addEventListener('done', function(ev) {
      if (streamDone) return;
      streamDone = true;
      clearTimeout(watchdog);
      try { es.close(); } catch (e) {}
      var data = {};
      try { data = JSON.parse(ev.data) || {}; } catch (e) {}
      finalizeStream(data, ses, userMsgId);
    });
    es.addEventListener('error', function() {
      // Verbindung/SSE gestört -> Server generiert serverseitig zu Ende, Poll holt es.
      fallbackPoll();
    });
  }

  function finalizeStream(data, ses, userMsgId) {
    var sessionId = String(ses.id);
    if ((data && data.session_id && String(data.session_id) !== sessionId)
        || (data && data.reply_to_id && Number(data.reply_to_id) !== Number(userMsgId))) {
      startFastPoll(ses, userMsgId);
      return;
    }
    var text     = safeReplyText((data && typeof data.text === 'string') ? data.text : '');
    var thinking = safeThinkingText(
      (data && typeof data.thinking === 'string') ? data.thinking : ''
    );
    var emberId  = (data && data.id) ? (data.id | 0) : 0;
    var browseJobId = (data && data.browse_job_id) ? (data.browse_job_id | 0) : 0;
    if (browseJobId <= 0) delete state.pendingBySession[sessionId];
    if (emberId > 0) state.streamedIds[emberId] = true; // Poll darf diese ID nicht doppelt rendern
    if (emberId > 0 && text) {
      mergeSessionMessages(sessionId, [{
        id: emberId,
        session_id: sessionId,
        reply_to_id: Number(userMsgId) || 0,
        character_id: 'ember',
        character_name: 'Ember',
        message: text,
        is_ember: true,
        thinking_content: thinking,
        attachment: null,
        created_at: new Date().toISOString()
      }]);
      ses.client_cursor = Math.max(Number(ses.client_cursor) || 0, emberId);
    }
    if (emberId > Number(ses.last_message_id || 0)) ses.last_message_id = emberId;

    if (!isActiveSession(sessionId)) {
      dbLoadSessions().then(renderSessionList).catch(function() {});
      return;
    }
    stopCountdown();

    // Thinking-Panel finalisieren
    if (state.thinkingPanelEl) {
      if (thinking) {
        var body = state.thinkingPanelEl.querySelector('.thinking-body');
        if (body) body.textContent = thinking;
        var toggle = state.thinkingPanelEl.querySelector('.thinking-toggle');
        if (toggle) {
          toggle.classList.remove('running');
          if (uiPreferences.thinkingExpanded === true) {
            toggle.classList.add('open');
            toggle.setAttribute('aria-expanded', 'true');
            if (body) body.classList.add('open');
          }
          var icon  = toggle.querySelector('.thinking-toggle-icon');
          var label = toggle.querySelector('span:last-child');
          if (icon)  icon.textContent  = '▶';
          if (label) label.textContent = 'DENKSTATUS';
        }
      } else {
        // Kein Thinking -> leere Panel-Row entfernen
        var prow = state.thinkingPanelEl.parentNode ? state.thinkingPanelEl.parentNode.parentNode : null;
        if (prow && prow.parentNode) prow.parentNode.removeChild(prow);
      }
      state.thinkingPanelEl = null;
    }

    // Typing-Row -> finale Ember-Bubble (NICHT entfernen) - außer Browse-Turn (keine Sofort-Antwort).
    if (state.typingEl) {
      if (!text && browseJobId > 0) {
        // Browse-Turn: keine leere Bubble. Live-Fenster übernimmt, Ergebnis kommt per Poll.
        if (state.typingEl.parentNode) state.typingEl.parentNode.removeChild(state.typingEl);
      } else {
        var bubble = state.typingEl.querySelector('.msg-bubble');
        if (bubble) {
          if (text) bubble.textContent = text;
          else if (bubble.querySelector('.typing-dots')) bubble.textContent = '\u2026';
        }
        var content = state.typingEl.querySelector('.msg-content');
        if (content && !content.querySelector('.msg-time')) {
          var timeEl = document.createElement('div');
          timeEl.className = 'msg-time';
          var now = new Date();
          timeEl.textContent = now.toLocaleTimeString('de-DE', {hour:'2-digit', minute:'2-digit'})
            + ', ' + now.toLocaleDateString('de-DE', {day:'2-digit', month:'2-digit', year:'2-digit'});
          content.appendChild(timeEl);
          showResponseTime(state.typingEl, sessionId); // Antwortzeit voranstellen
        }
      }
      state.typingEl = null;
    }

    if (emberId > 0) dbMarkRead(sessionId, emberId);

    updateActivePendingUi();

    // Browse-Turn: Live-Browser-Fenster öffnen (Phase 3b). Das Ergebnis kommt später per Poll.
    if (browseJobId > 0) {
      // Die FINALE Antwort kommt erst NACH dem Surfen (per Hintergrund-Poll). Bis dahin arbeitet
      // Ember weiter -> Status sichtbar "arbeitet" (amber) lassen, NICHT vorschnell auf grün.
      setStatus('waiting', 'EMBER ANTWORTET\u2026');
      openBrowseWindow(browseJobId, sessionId);
    } else {
      setStatus('online', 'VERBUNDEN');
    }

    scrollToBottom();
    dbLoadSessions().then(renderSessionList).catch(function() {});
    scheduleBackgroundPoll();
  }

  // ── Live-Browser-Agentenansicht ───────────────────────────
  // Das Browserbild IST die Rechercheflaeche. Technische Schritte erscheinen nur noch
  // als einzeiliger Status. Der Ghost-Cursor bleibt sichtbar; nur echte Playwright-
  // Klickkoordinaten loesen einen Klickimpuls aus.
  function openBrowseWindow(jobId, sessionId) {
    var panel       = buildBrowsePanel();
    var head        = panel.querySelector('.browse-live-state');
    var urlLabel    = panel.querySelector('.browse-live-url');
    var status      = panel.querySelector('.browse-live-status');
    var shot        = panel.querySelector('.browse-live-shot');
    var placeholder = panel.querySelector('.browse-live-placeholder');
    var cursor      = panel.querySelector('.browse-ghost-cursor');

    var url = API_BASE + '/console_browse_stream.php?job_id=' + (jobId | 0)
      + '&session_id=' + encodeURIComponent(String(sessionId || ''));
    var es;
    try { es = new EventSource(url, { withCredentials: true }); }
    catch (e) {
      if (head) head.textContent = 'OFFLINE';
      if (status) status.textContent = 'Live-Browser konnte nicht verbunden werden.';
      return;
    }

    var doneB = false;
    var gotFrame = false;
    var frameQueue = [];
    var frameBusy = false;

    function clamp(value, min, max) {
      return Math.max(min, Math.min(max, value));
    }

    function pulseCursor() {
      if (!cursor) return;
      cursor.classList.remove('clicking');
      void cursor.offsetWidth;
      cursor.classList.add('clicking');
      setTimeout(function () { cursor.classList.remove('clicking'); }, 650);
    }

    function renderNextFrame() {
      if (frameBusy || !frameQueue.length || !shot) return;
      frameBusy = true;
      var d = frameQueue.shift();
      var hasCursor = typeof d.cx === 'number' && typeof d.cy === 'number'
        && Number(d.vw) > 0 && Number(d.vh) > 0;
      var isClick = d.click === true || Number(d.click) === 1;
      var nextSrc = 'data:image/jpeg;base64,' + String(d.b64 || '');
      var settled = false;

      function frameReady() {
        if (settled) return;
        settled = true;
        gotFrame = true;
        shot.classList.add('ready');
        if (placeholder) placeholder.classList.add('hidden');
        if (urlLabel && d.url) urlLabel.textContent = d.url;
        if (status && d.label) status.textContent = d.label;
        if (hasCursor && cursor) {
          cursor.classList.add('active');
          cursor.style.left = (clamp(Number(d.cx) / Number(d.vw), 0.015, 0.985) * 100) + '%';
          cursor.style.top  = (clamp(Number(d.cy) / Number(d.vh), 0.02, 0.98) * 100) + '%';
          if (isClick) setTimeout(pulseCursor, 310);
        }
        scrollToBottom();
        setTimeout(function () {
          frameBusy = false;
          renderNextFrame();
        }, isClick ? 640 : 260);
      }
      function frameFailed() {
        if (settled) return;
        settled = true;
        frameBusy = false;
        renderNextFrame();
      }

      shot.onload = frameReady;
      shot.onerror = frameFailed;
      if (shot.src === nextSrc && shot.complete && shot.naturalWidth > 0) frameReady();
      else shot.src = nextSrc;
    }

    function queueFrame(d) {
      if (!d || !d.b64) return;
      if (uiPreferences.liveFrameMotion === false) frameQueue = [d];
      else frameQueue.push(d);
      // Bei sehr schnellem Surfen nur einen kleinen, aktuellen Verlauf behalten.
      if (frameQueue.length > 16) frameQueue.splice(1, frameQueue.length - 16);
      renderNextFrame();
    }

    function updateStep(txt) {
      if (!status || !txt) return;
      status.textContent = txt;
      status.title = txt;
    }
    function finishB(label) {
      if (doneB) return;
      doneB = true;
      clearTimeout(bWatch);
      try { es.close(); } catch (e) {}
      if (head) head.textContent = label;
      panel.classList.remove('running');
      panel.classList.add('complete');
      if (!gotFrame && status) status.textContent = 'Recherche beendet, kein Browserbild empfangen.';
    }
    function armBrowseWatch() {
      clearTimeout(bWatch);
      bWatch = setTimeout(function () { finishB('ZEITLIMIT'); }, BROWSE_IDLE_MS);
    }
    var bWatch;
    armBrowseWatch();

    es.addEventListener('step', function (ev) {
      armBrowseWatch();
      try { updateStep(JSON.parse(ev.data).text || ''); } catch (e) {}
    });
    es.addEventListener('frame', function (ev) {
      armBrowseWatch();
      try { queueFrame(JSON.parse(ev.data) || {}); } catch (e) {}
    });
    es.addEventListener('done', function () { finishB('FERTIG ✓'); });
    es.addEventListener('error', function (ev) {
      // Ein benanntes Server-error beendet den Job. Ein reiner Netzwerkfehler
      // laesst das Pending bestehen, weil das Ergebnis weiter persistiert werden kann.
      try {
        var payload = ev && ev.data ? JSON.parse(ev.data) : null;
        if (payload && payload.status === 'error') {
          delete state.pendingBySession[String(sessionId || '')];
          if (isActiveSession(sessionId)) {
            updateActivePendingUi();
            setStatus('error', 'RECHERCHEFEHLER');
          }
        }
      } catch (e) {}
      finishB('ABGESCHLOSSEN');
    });
    es.addEventListener('timeout', function () { finishB('ABGESCHLOSSEN'); });
  }

  function buildBrowsePanel() {
    var row = document.createElement('div');
    row.className = 'msg-row ember browse-row';

    var avatar = document.createElement('div');
    avatar.className = 'msg-avatar';
    applyMessageAvatar(avatar, 'ember', state.assistantName || 'Ember');
    row.appendChild(avatar);

    var content = document.createElement('div');
    content.className = 'msg-content';

    var panel = document.createElement('div');
    panel.className = 'browse-live running';
    panel.innerHTML = ''
      + '<div class="browse-live-chrome">'
      +   '<span class="browse-live-mark">◇</span>'
      +   '<span class="browse-live-title">IM NETZ</span>'
      +   '<span class="browse-live-url">coreui://browser/verbinden</span>'
      +   '<span class="browse-live-state">LIVE</span>'
      + '</div>'
      + '<div class="browse-live-stage">'
      +   '<div class="browse-live-placeholder"><span class="browse-live-orbit"></span>'
      +     '<strong>BROWSERKANAL WIRD SYNCHRONISIERT</strong><small>PLAYWRIGHT // SECURE VIEW</small></div>'
      +   '<img class="browse-live-shot" alt="Aktuelle Browseransicht von Embers Recherche" draggable="false">'
      +   '<span class="browse-scanline" aria-hidden="true"></span>'
      +   '<span class="browse-ghost-cursor active" aria-hidden="true"></span>'
      + '</div>'
      + '<div class="browse-live-footer"><span class="browse-live-pulse"></span>'
      +   '<span class="browse-live-status">Recherche wird vorbereitet...</span>'
      +   '<span class="browse-live-code">N-73 // GHOST</span></div>';

    content.appendChild(panel);
    row.appendChild(content);

    if (messagesInner) messagesInner.appendChild(row);
    scrollToBottom();
    return panel;
  }

  function consoleMessagesUrl(ses, afterId) {
    return API_BASE + '/console_messages.php?session_id=' + encodeURIComponent(ses.id)
      + '&character_id=' + encodeURIComponent(state.characterId)
      + '&after_id=' + Math.max(0, Number(afterId) || 0)
      + '&limit=100';
  }

  function sessionClientCursor(ses) {
    var rows = state.messageCacheBySession[ses.id] || [];
    var cached = rows.length ? Number(rows[rows.length - 1].id) || 0 : 0;
    return Math.max(cached, Number(ses.client_cursor) || 0);
  }

  function ingestNewMessages(ses, rows) {
    var known = {};
    (state.messageCacheBySession[ses.id] || []).forEach(function(m) { known[String(m.id)] = true; });
    mergeSessionMessages(ses.id, rows);
    var newest = sessionClientCursor(ses);
    (rows || []).forEach(function(m) {
      var id = Number(m.id) || 0;
      if (id > newest) newest = id;
      if (!known[String(id)] && isActiveSession(ses.id) && !state.streamedIds[id]) {
        renderMessageRecord(m);
      }
    });
    ses.client_cursor = newest;
    ses.last_read_message_id = Math.max(Number(ses.last_read_message_id) || 0, newest);
    ses.last_message_id = Math.max(Number(ses.last_message_id) || 0, newest);
    ses.unread_count = 0;
    return newest;
  }

  // Fast Poll wartet auf die Antwort zu genau einem User-Turn.
  function startFastPoll(ses, userMsgId) {
    if (!ses) return;
    var sessionId = String(ses.id);
    state.pollAttemptsBySession[sessionId] = 0;
    if (state.pollTimers[sessionId]) clearTimeout(state.pollTimers[sessionId]);
    doPoll(ses, userMsgId);
  }

  function doPoll(ses, userMsgId) {
    if (!ses || !state.characterId) return;
    var sessionId = String(ses.id);
    state.pollAttemptsBySession[sessionId] = Number(state.pollAttemptsBySession[sessionId] || 0) + 1;
    if (state.pollAttemptsBySession[sessionId] > MAX_POLL_ATTEMPTS) {
      delete state.pendingBySession[sessionId];
      delete state.pollTimers[sessionId];
      if (isActiveSession(sessionId)) {
        stopCountdown();
        removeTypingIndicator();
        updateActivePendingUi();
        setStatus('online', 'VERBUNDEN');
        appendMessageEl('system', '\u231b Ember hat nicht geantwortet.');
        scrollToBottom();
      }
      scheduleBackgroundPoll();
      return;
    }

    fetch(consoleMessagesUrl(ses, userMsgId), { credentials: 'include' })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d.ok || String(d.session_id || '') !== sessionId || !Array.isArray(d.messages)) {
        throw new Error(d.error || 'poll_failed');
      }
      var found = null;
      d.messages.forEach(function(m) {
        if (isEmberMsg(m) && Number(m.reply_to_id) === Number(userMsgId)) found = m;
      });
      ingestNewMessages(ses, d.messages);

      if (found) {
        delete state.pendingBySession[sessionId];
        delete state.pollTimers[sessionId];
        if (isActiveSession(sessionId)) {
          stopCountdown();
          removeTypingIndicator();
          updateActivePendingUi();
          setStatus('online', 'VERBUNDEN');
          scrollToBottom();
        }
        dbLoadSessions().then(renderSessionList).catch(function() {});
        scheduleBackgroundPoll();
      } else {
        state.pollTimers[sessionId] = setTimeout(function() { doPoll(ses, userMsgId); }, POLL_FAST_MS);
      }
    })
    .catch(function() {
      state.pollTimers[sessionId] = setTimeout(function() { doPoll(ses, userMsgId); }, POLL_FAST_MS);
    });
  }

  // Background Poll liest ausschliesslich neue Nachrichten der aktiven Sitzung.
  function scheduleBackgroundPoll() {
    if (state.bgTimer) clearTimeout(state.bgTimer);
    state.bgTimer = setTimeout(doBackgroundPoll, POLL_BG_MS);
  }

  function doBackgroundPoll() {
    var ses = activeSession();
    if (!ses || !state.characterId) {
      scheduleBackgroundPoll();
      return;
    }
    var sessionId = String(ses.id);
    var cursor = sessionClientCursor(ses);
    fetch(consoleMessagesUrl(ses, cursor), { credentials: 'include' })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d.ok || String(d.session_id || '') !== sessionId || !Array.isArray(d.messages)) {
        scheduleBackgroundPoll();
        return;
      }
      var pendingTurnId = Number(state.pendingBySession[sessionId]) || 0;
      var receivedReply = false;
      d.messages.forEach(function(m) {
        if (pendingTurnId > 0 && isEmberMsg(m)
            && Number(m.reply_to_id) === pendingTurnId) receivedReply = true;
      });
      ingestNewMessages(ses, d.messages);
      if (receivedReply && state.pendingBySession[sessionId]) {
        delete state.pendingBySession[sessionId];
        if (isActiveSession(sessionId)) {
          stopCountdown();
          removeTypingIndicator();
          updateActivePendingUi();
          setStatus('online', 'VERBUNDEN');
        }
      }
      if (d.messages.length && isActiveSession(sessionId)) scrollToBottom();
      scheduleBackgroundPoll();
    })
    .catch(function() { scheduleBackgroundPoll(); });
  }

  // ── Auth & Charakter ─────────────────────────────────────
  function checkAuth() {
    setStatus('', 'VERBINDE\u2026');
    return fetch(API_BASE + '/auth.php?action=me', { credentials: 'include' })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d.ok) { location.replace('login.html'); return Promise.reject('not_authenticated'); }
        state.uid = d.user_id;
        state.csrfToken = d.csrf_token || '';
        state.userDisplayName = d.display_name || null;
        state.assistantName = d.assistant_name || 'Ember';
        state.userAvatarUrl = d.avatars && d.avatars.user ? d.avatars.user : null;
        state.assistantAvatarUrl = d.avatars && d.avatars.assistant ? d.avatars.assistant : null;
        if (inputEl) inputEl.placeholder = 'Nachricht an ' + state.assistantName + '…';
      });
  }

  function refreshRuntimeProfile() {
    if (!state.uid) return Promise.resolve();
    return fetch(API_BASE + '/profile.php', { credentials: 'include', cache: 'no-store' })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d.ok || !d.profile) return;
        state.userDisplayName = d.profile.display_name || state.userDisplayName;
        state.assistantName = d.profile.assistant_name || state.assistantName || 'Ember';
        state.userAvatarUrl = d.profile.avatars && d.profile.avatars.user ? d.profile.avatars.user : null;
        state.assistantAvatarUrl = d.profile.avatars && d.profile.avatars.assistant ? d.profile.avatars.assistant : null;
        if (inputEl) inputEl.placeholder = 'Nachricht an ' + state.assistantName + '…';
        var ses = activeSession();
        if (ses && isActiveSession(ses.id)) renderHistory(ses);
      })
      .catch(function() {});
  }

  function loadCharacter() {
    return fetch(API_BASE + '/storage.php?key=stu_active_character_id', { credentials: 'include' })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d && d.value) state.characterId = d.value;
        return fetch(API_BASE + '/storage.php?key=stu_characters', { credentials: 'include' });
      })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d && d.value) {
          var chars = [];
          try { chars = JSON.parse(d.value); } catch(e) {}
          if (Array.isArray(chars) && chars.length > 0) {
            if (!state.characterId) state.characterId = chars[0].id;
            var ac = chars.find(function(c) { return c.id === state.characterId; });
            state.charName = ac ? ac.name : chars[0].name;
          }
        }
        // Kein Charakter gefunden - wird in init() behandelt, kein Fehler werfen
      })
      .catch(function(err) {
        // storage.php Fehler - im Chat anzeigen für Diagnose
        appendMessageEl('system', '⚠ Charakter-Load fehlgeschlagen: ' + (err && err.message ? err.message : 'storage.php Fehler'));
      });
  }

  // ── Drawer ───────────────────────────────────────────────
  function openDrawer()  {
    state.drawerOpen = true;
    drawerEl.classList.add('open');
    drawerOverlay.classList.add('visible');
    renderSessionList();
  }
  function closeDrawer() {
    state.drawerOpen = false;
    drawerEl.classList.remove('open');
    drawerOverlay.classList.remove('visible');
  }

  // ── Logout ───────────────────────────────────────────────
  function doLogout() {
    fetch(API_BASE + '/auth.php?action=logout', { method: 'POST', credentials: 'include' })
      .finally(function() { location.replace('login.html'); });
  }

  // ── Events ───────────────────────────────────────────────
  btnDrawer.addEventListener('click', openDrawer);
  drawerOverlay.addEventListener('click', closeDrawer);
  drawerClose.addEventListener('click', closeDrawer);

  if (sessionArchiveToggle) {
    sessionArchiveToggle.addEventListener('click', function() {
      state.showArchived = !state.showArchived;
      sessionArchiveToggle.disabled = true;
      dbLoadSessions()
        .then(renderSessionList)
        .catch(function() {
          state.showArchived = !state.showArchived;
          renderSessionList();
        })
        .finally(function() { sessionArchiveToggle.disabled = false; });
    });
  }

  btnNewSession.addEventListener('click', function() {
    closeDrawer();
    var num = state.sessions.length + 1;
    dbCreateSession('SITZUNG ' + num)
      .then(function(ses) {
        selectSession(ses.id);
      })
      .catch(function() {
        appendMessageEl('system', '\u26a0 Sitzung konnte nicht angelegt werden.');
      });
  });

  btnProtocols.addEventListener('click', function() {
    closeDrawer();
    location.href = 'protocols.html';
  });

  if (btnSettings) {
    btnSettings.addEventListener('click', function() {
      closeDrawer();
      location.href = 'settings.html';
    });
  }

  btnLogout.addEventListener('click', doLogout);

  window.addEventListener('pageshow', function () { refreshRuntimeProfile(); });

  btnSend.addEventListener('click', function() { sendMessage(inputEl.value); });
  inputEl.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(inputEl.value); }
  });

  // ── Init ─────────────────────────────────────────────────
  function init() {
    // Button sofort freischalten - nie durch einen Fehler blockiert lassen
    btnSend.disabled = false;

    checkAuth()
      .then(loadCharacter)
      .then(function() {
        if (!state.characterId) {
          setStatus('error', 'KEIN CHARAKTER');
          appendMessageEl('system', '\u26a0 Kein Charakter gefunden. Bitte erst im Spiel einen Charakter anlegen.');
          scrollToBottom();
          return;
        }
        // Sessions laden - Fehler hier killen nicht die App
        return dbLoadSessions()
          .then(function() {
            if (state.sessions.length === 0) {
              return dbCreateSession('SITZUNG 1');
            }
          })
          .then(function() {
            var preferred = rememberedActiveSession();
            var ses = sessionById(preferred) || state.sessions[0] || null;
            if (!ses) {
              appendMessageEl('system', 'ARCHNET VERBINDUNG HERGESTELLT. EMBER INTERFACE AKTIV.');
              scrollToBottom();
              setStatus('online', 'VERBUNDEN');
              return;
            }
            selectSession(ses.id);
          })
          .catch(function() {
            appendMessageEl('system', '\u26a0 Sitzungsdienst nicht verfuegbar. Bitte Migration 003 und Preflight pruefen.');
            scrollToBottom();
            setStatus('error', 'SITZUNGSFEHLER');
          });
      })
      .catch(function(err) {
        if (err !== 'not_authenticated') {
          setStatus('error', 'FEHLER');
          appendMessageEl('system', '\u26a0 Fehler: ' + (err && err.message ? err.message : String(err)));
          scrollToBottom();
        }
      });
  }

  init();

})();
