/* Ember CoreUI isolated administration controller. */
(function () {
  'use strict';

  var csrfToken = '';
  var actorLevel = 4;
  var flashTimer = 0;
  var runtimeState = {};
  var flash = document.getElementById('adminFlash');
  var identity = document.getElementById('adminIdentity');

  function show(message, error) {
    if (!flash) return;
    flash.textContent = message;
    flash.classList.toggle('error', error === true);
    flash.classList.add('visible');
    clearTimeout(flashTimer);
    flashTimer = setTimeout(function () { flash.classList.remove('visible'); }, 3200);
  }

  function api(action, options, query) {
    options = options || {};
    var url = '../api/admin.php?action=' + encodeURIComponent(action);
    if (query) url += '&' + query;
    options.credentials = 'include';
    options.cache = 'no-store';
    options.headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
    if (options.method === 'POST') {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CoreUI-CSRF'] = csrfToken;
    }
    return fetch(url, options).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok || !data.ok) {
          var err = new Error(data.error || ('HTTP_' + response.status));
          err.data = data;
          if (response.status === 401 || response.status === 403 && data.error === 'insufficient_permissions') {
            location.replace('../settings.html');
          }
          throw err;
        }
        return data;
      });
    });
  }

  function post(action, body) {
    return api(action, { method: 'POST', body: JSON.stringify(body || {}) });
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = String(text);
    return node;
  }

  function switchPanel(name, skipLoad) {
    document.querySelectorAll('[data-panel]').forEach(function (button) {
      button.classList.toggle('active', button.getAttribute('data-panel') === name);
    });
    document.querySelectorAll('[data-panel-view]').forEach(function (panel) {
      var active = panel.getAttribute('data-panel-view') === name;
      panel.hidden = !active;
      panel.classList.toggle('active', active);
    });
    history.replaceState(null, '', '#' + name);
    if (!skipLoad) {
      if (name === 'overview') loadOverview();
      if (name === 'users') loadUsers();
      if (name === 'memories') loadMemories();
      if (name === 'lore') loadLore(false);
      if (name === 'logs') loadLogs();
      if (name === 'audit') loadAudit();
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function loadOverview() {
    return api('overview').then(function (data) {
      csrfToken = data.csrf_token || csrfToken;
      runtimeState = data.settings || {};
      Object.keys(data.stats || {}).forEach(function (key) {
        var target = document.querySelector('[data-stat="' + key + '"]');
        if (target) target.textContent = String(data.stats[key]);
      });
      fillRuntime(runtimeState);
    }).catch(function (error) { show('Übersicht: ' + error.message, true); });
  }

  function localDateTime(value) {
    if (!value) return '';
    var normalized = String(value).replace(' ', 'T');
    return normalized.slice(0, 16);
  }

  function loadUsers() {
    var q = document.getElementById('userSearch').value.trim();
    return api('users', null, 'limit=150&q=' + encodeURIComponent(q)).then(function (data) {
      var tbody = document.getElementById('userRows');
      tbody.innerHTML = '';
      (data.items || []).forEach(function (user) {
        var row = document.createElement('tr');
        row.appendChild(el('td', '', user.id));
        var account = el('td');
        account.appendChild(el('strong', '', user.display_name || user.username || 'ohne E-Mail'));
        account.appendChild(el('small', '', (user.username || '') + (user.character_name ? (' // ' + user.character_name) : '')));
        row.appendChild(account);

        var levelCell = el('td');
        var level = document.createElement('select');
        [0, 1, 2, 3, 4].forEach(function (value) {
          var option = el('option', '', value + ' // ' + ['SYSTEM', 'ADMIN', 'MOD', 'CHAT-MOD', 'USER'][value]);
          option.value = String(value);
          if (Number(user.permission_level) === value) option.selected = true;
          level.appendChild(option);
        });
        levelCell.appendChild(level);
        row.appendChild(levelCell);

        var banCell = el('td');
        var until = document.createElement('input');
        until.type = 'datetime-local';
        until.value = localDateTime(user.banned_until);
        until.title = user.banned_reason || '';
        banCell.appendChild(until);
        row.appendChild(banCell);
        row.appendChild(el('td', '', user.session_count || 0));
        var actionCell = el('td');
        var save = el('button', '', 'SPEICHERN');
        save.type = 'button';
        save.addEventListener('click', function () {
          var reason = '';
          if (until.value) reason = window.prompt('Interner Sperrgrund:', user.banned_reason || '') || '';
          post('user_update', {
            user_id: Number(user.id),
            permission_level: Number(level.value),
            banned_until: until.value,
            banned_reason: reason
          }).then(function () {
            show('Benutzer ' + user.id + ' gespeichert.');
            loadUsers();
          }).catch(function (error) { show('Benutzer: ' + error.message, true); });
        });
        actionCell.appendChild(save);
        row.appendChild(actionCell);
        tbody.appendChild(row);
      });
    }).catch(function (error) { show('Benutzer: ' + error.message, true); });
  }

  function createUser(event) {
    event.preventDefault();
    var button = document.querySelector('#adminUserForm button[type="submit"]');
    if (button) button.disabled = true;
    post('user_create', {
      display_name: document.getElementById('newUserDisplayName').value,
      username: document.getElementById('newUserEmail').value,
      password: document.getElementById('newUserPassword').value,
      permission_level: Number(document.getElementById('newUserLevel').value) || 4
    }).then(function (data) {
      document.getElementById('adminUserForm').reset();
      show('Benutzer #' + data.user.id + ' wurde mit eigenem Ember CoreUI-Profil angelegt.');
      return Promise.all([loadUsers(), loadOverview()]);
    }).catch(function (error) {
      var labels = {
        username_taken: 'Diese Login-E-Mail ist bereits vergeben.',
        invalid_password: 'Das Startpasswort muss mindestens 12 Zeichen lang sein.',
        protected_account: 'Diese Rolle darf dein Administrationskonto nicht vergeben.'
      };
      show(labels[error.message] || ('Benutzeranlage: ' + error.message), true);
    }).finally(function () { if (button) button.disabled = false; });
  }

  function resetMemoryForm() {
    document.getElementById('adminMemoryId').value = '0';
    document.getElementById('adminMemoryFact').value = '';
    document.getElementById('adminMemoryScope').value = 'global';
    document.getElementById('adminMemoryRelevance').value = '5';
    document.getElementById('adminMemoryUser').value = '0';
    document.getElementById('adminMemoryCharacter').value = '';
  }

  function editMemory(item) {
    document.getElementById('adminMemoryId').value = String(item.id || 0);
    document.getElementById('adminMemoryFact').value = item.fact || '';
    document.getElementById('adminMemoryScope').value = item.scope || 'global';
    document.getElementById('adminMemoryRelevance').value = String(item.relevance || 5);
    document.getElementById('adminMemoryUser').value = String(item.user_id || 0);
    document.getElementById('adminMemoryCharacter').value = item.character_id || '';
    document.getElementById('adminMemoryFact').focus();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function loadMemories() {
    var q = document.getElementById('memorySearch').value.trim();
    var scope = document.getElementById('memoryScopeFilter').value;
    return api('memories', null, 'limit=250&q=' + encodeURIComponent(q) + '&scope=' + encodeURIComponent(scope)).then(function (data) {
      var list = document.getElementById('adminMemoryRows');
      list.innerHTML = '';
      (data.items || []).forEach(function (item) {
        var row = el('article', 'memory-admin-item');
        var body = el('div');
        body.appendChild(el('p', '', item.fact || ''));
        body.appendChild(el('small', '', '#' + item.id + ' // ' + String(item.scope || '').toUpperCase() + ' // REL.' + item.relevance + (item.username ? (' // ' + item.username) : '')));
        var actions = el('div', 'item-actions');
        var edit = el('button', '', 'EDIT');
        edit.addEventListener('click', function () { editMemory(item); });
        var remove = el('button', 'danger', 'LÖSCHEN');
        remove.addEventListener('click', function () {
          if (!window.confirm('Memory #' + item.id + ' wirklich löschen?')) return;
          post('memory_delete', { id: Number(item.id) }).then(function () {
            show('Memory gelöscht.');
            loadMemories();
          }).catch(function (error) { show('Memory: ' + error.message, true); });
        });
        actions.appendChild(edit);
        actions.appendChild(remove);
        row.appendChild(body);
        row.appendChild(actions);
        list.appendChild(row);
      });
      if (!(data.items || []).length) list.appendChild(el('div', 'admin-callout', 'KEINE MEMORY-EINTRÄGE GEFUNDEN'));
    }).catch(function (error) { show('Memory: ' + error.message, true); });
  }

  function saveMemory(event) {
    event.preventDefault();
    var body = {
      id: Number(document.getElementById('adminMemoryId').value) || 0,
      fact: document.getElementById('adminMemoryFact').value,
      scope: document.getElementById('adminMemoryScope').value,
      relevance: Number(document.getElementById('adminMemoryRelevance').value) || 5,
      user_id: Number(document.getElementById('adminMemoryUser').value) || 0,
      character_id: document.getElementById('adminMemoryCharacter').value.trim()
    };
    post('memory_upsert', body).then(function () {
      show('Memory gespeichert.');
      resetMemoryForm();
      loadMemories();
    }).catch(function (error) { show('Memory: ' + error.message, true); });
  }

  function renderLore(data) {
    var sourceBox = document.getElementById('loreSources');
    var select = document.getElementById('loreSourceFilter');
    sourceBox.innerHTML = '';
    var previous = select.value;
    select.innerHTML = '<option value="">ALLE QUELLEN</option>';
    (data.sources || []).forEach(function (source) {
      sourceBox.appendChild(el('span', 'lore-source', source.source + ' // ' + source.chunks + ' CHUNKS'));
      var option = el('option', '', source.source);
      option.value = source.source;
      select.appendChild(option);
    });
    select.value = previous;
    var results = document.getElementById('loreResults');
    results.innerHTML = '';
    (data.items || []).forEach(function (item) {
      var row = el('article', 'lore-item');
      var body = el('div');
      body.appendChild(el('p', '', item.chunk_text || ''));
      body.appendChild(el('small', '', item.source + ' // CHUNK ' + item.chunk_no + (item.title ? (' // ' + item.title) : '')));
      row.appendChild(body);
      results.appendChild(row);
    });
  }

  function loadLore(search) {
    var q = search ? document.getElementById('loreSearch').value.trim() : '';
    var source = search ? document.getElementById('loreSourceFilter').value : '';
    return api('lore', null, 'q=' + encodeURIComponent(q) + '&source=' + encodeURIComponent(source))
      .then(renderLore)
      .catch(function (error) { show('Lore: ' + error.message, true); });
  }

  function boolSetting(value) {
    return String(value) === '1';
  }

  function fillRuntime(settings) {
    if (!settings) return;
    document.getElementById('registrationEnabled').checked = boolSetting(settings.registration_enabled);
    document.getElementById('maintenanceEnabled').checked = boolSetting(settings.maintenance_enabled);
    document.getElementById('memoryDefaultEnabled').checked = boolSetting(settings.memory_default_enabled);
    document.getElementById('promptLimit').value = settings.user_system_prompt_max_chars || '6000';
    document.getElementById('maintenanceMessage').value = settings.maintenance_message || '';
    document.getElementById('toolWebEnabled').checked = boolSetting(settings.tool_web_enabled);
    document.getElementById('toolBrowseEnabled').checked = boolSetting(settings.tool_browse_enabled);
    document.getElementById('toolPythonEnabled').checked = boolSetting(settings.tool_python_enabled);
    document.getElementById('externalEnabled').checked = boolSetting(settings.external_api_enabled);
    document.getElementById('externalLabel').value = settings.external_api_label || 'OpenAI-kompatibel';
    document.getElementById('externalBaseUrl').value = settings.external_api_base_url || '';
    document.getElementById('externalModel').value = settings.external_api_model || '';
  }

  function saveRuntime() {
    var settings = {
      registration_enabled: document.getElementById('registrationEnabled').checked ? '1' : '0',
      maintenance_enabled: document.getElementById('maintenanceEnabled').checked ? '1' : '0',
      memory_default_enabled: document.getElementById('memoryDefaultEnabled').checked ? '1' : '0',
      user_system_prompt_max_chars: document.getElementById('promptLimit').value,
      maintenance_message: document.getElementById('maintenanceMessage').value,
      tool_web_enabled: document.getElementById('toolWebEnabled').checked ? '1' : '0',
      tool_browse_enabled: document.getElementById('toolBrowseEnabled').checked ? '1' : '0',
      tool_python_enabled: document.getElementById('toolPythonEnabled').checked ? '1' : '0',
      external_api_enabled: document.getElementById('externalEnabled').checked ? '1' : '0',
      external_api_label: document.getElementById('externalLabel').value,
      external_api_base_url: document.getElementById('externalBaseUrl').value,
      external_api_model: document.getElementById('externalModel').value
    };
    post('settings_save', { settings: settings }).then(function (data) {
      runtimeState = data.settings || settings;
      fillRuntime(runtimeState);
      show('Runtime-Einstellungen gespeichert.');
    }).catch(function (error) { show('Runtime: ' + error.message, true); });
  }

  function loadLogs() {
    return api('logs', null, 'limit=1000').then(function (data) {
      var out = document.getElementById('logOutput');
      out.textContent = (data.lines || []).join('\n') || 'KEINE PROTOKOLLZEILEN';
      out.scrollTop = out.scrollHeight;
    }).catch(function (error) { show('Protokoll: ' + error.message, true); });
  }

  function clearLogs() {
    if (!window.confirm('Das Ember CoreUI-Fehlerprotokoll wirklich leeren?')) return;
    post('logs_clear', {}).then(function (data) {
      show('Protokoll geleert: ' + data.removed + ' sichtbare Zeilen.');
      loadLogs();
    }).catch(function (error) { show('Protokoll: ' + error.message, true); });
  }

  function loadAudit() {
    return api('audit', null, 'limit=200').then(function (data) {
      var list = document.getElementById('auditRows');
      list.innerHTML = '';
      (data.items || []).forEach(function (item) {
        var row = el('article', 'audit-item');
        var body = el('div');
        body.appendChild(el('p', '', item.action_name + (item.target_type ? (' // ' + item.target_type + ':' + item.target_id) : '')));
        body.appendChild(el('small', '', (item.actor || ('UID ' + item.actor_user_id)) + ' // ' + item.created_at));
        row.appendChild(body);
        list.appendChild(row);
      });
    }).catch(function (error) { show('Audit: ' + error.message, true); });
  }

  function bind() {
    document.querySelectorAll('[data-panel]').forEach(function (button) {
      button.addEventListener('click', function () { switchPanel(button.getAttribute('data-panel')); });
    });
    document.getElementById('refreshOverview').addEventListener('click', loadOverview);
    document.getElementById('reloadUsers').addEventListener('click', loadUsers);
    document.getElementById('searchUsers').addEventListener('click', loadUsers);
    document.getElementById('adminUserForm').addEventListener('submit', createUser);
    document.getElementById('reloadAdminMemories').addEventListener('click', loadMemories);
    document.getElementById('searchAdminMemories').addEventListener('click', loadMemories);
    document.getElementById('adminMemoryForm').addEventListener('submit', saveMemory);
    document.getElementById('resetAdminMemory').addEventListener('click', resetMemoryForm);
    document.getElementById('reloadLore').addEventListener('click', function () { loadLore(false); });
    document.getElementById('searchLore').addEventListener('click', function () { loadLore(true); });
    document.getElementById('saveRuntime').addEventListener('click', saveRuntime);
    document.getElementById('reloadLogs').addEventListener('click', loadLogs);
    document.getElementById('clearLogs').addEventListener('click', clearLogs);
    document.getElementById('reloadAudit').addEventListener('click', loadAudit);
  }

  fetch('../api/auth.php?action=me', { credentials: 'include', cache: 'no-store' })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (!data.ok || Number(data.permission_level) > 1) {
        location.replace('../settings.html');
        return Promise.reject(new Error('forbidden'));
      }
      csrfToken = data.csrf_token || '';
      actorLevel = Number(data.permission_level);
      var adminOption = document.querySelector('#newUserLevel option[value="1"]');
      if (adminOption && actorLevel > 0) adminOption.disabled = true;
      if (identity) identity.textContent = (data.username || 'ADMIN') + ' // LV.' + data.permission_level;
      bind();
      var initial = location.hash.replace(/^#/, '') || 'overview';
      if (!document.querySelector('[data-panel="' + initial + '"]')) initial = 'overview';
      return loadOverview().then(function () {
        switchPanel(initial, initial === 'overview');
      });
    })
    .catch(function (error) {
      if (error.message !== 'forbidden') show('Admin-Initialisierung: ' + error.message, true);
    });
})();
