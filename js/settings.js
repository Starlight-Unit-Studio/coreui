/* Project STΛRLIɢHT: Ember CoreUI settings controller. */
(function () {
  'use strict';

  var preferences = window.CoreUIPreferences;
  var fields = {
    reduceMotion: document.getElementById('prefReduceMotion'),
    compactFeed: document.getElementById('prefCompactFeed'),
    thinkingExpanded: document.getElementById('prefThinkingExpanded'),
    liveFrameMotion: document.getElementById('prefLiveFrameMotion'),
    showTelemetry: document.getElementById('prefShowTelemetry')
  };
  var saveState = document.getElementById('settingsSaveState');
  var accountMail = document.getElementById('accountMail');
  var accountLevel = document.getElementById('accountLevel');
  var adminEntry = document.getElementById('adminEntry');
  var healthSummary = document.getElementById('healthSummary');
  var healthVersion = document.getElementById('healthVersion');
  var healthModel = document.getElementById('healthModel');
  var healthChecks = document.getElementById('healthChecks');
  var refreshButton = document.getElementById('refreshHealth');
  var resetButton = document.getElementById('resetPreferences');
  var logoutButton = document.getElementById('settingsLogout');
  var backButton = document.getElementById('settingsBack');
  var aiSaveButton = document.getElementById('saveAiSettings');
  var aiSystemPrompt = document.getElementById('aiSystemPrompt');
  var aiPromptCounter = document.getElementById('aiPromptCounter');
  var aiMemoryEnabled = document.getElementById('aiMemoryEnabled');
  var aiMemoryLimit = document.getElementById('aiMemoryLimit');
  var aiNumPredict = document.getElementById('aiNumPredict');
  var aiTemperature = document.getElementById('aiTemperature');
  var aiProvider = document.getElementById('aiProvider');
  var externalNote = document.getElementById('externalProviderNote');
  var memoryFact = document.getElementById('memoryFact');
  var memoryRelevance = document.getElementById('memoryRelevance');
  var memoryList = document.getElementById('memoryList');
  var addMemoryButton = document.getElementById('addMemory');
  var reloadMemoriesButton = document.getElementById('reloadMemories');
  var saveTimer = 0;
  var csrfToken = '';
  var promptMax = 6000;

  function api(url, options) {
    options = options || {};
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
          throw err;
        }
        return data;
      });
    });
  }

  function syncFields(value) {
    Object.keys(fields).forEach(function (key) {
      if (fields[key]) fields[key].checked = value[key] === true;
    });
  }

  function collect() {
    var value = {};
    Object.keys(fields).forEach(function (key) {
      value[key] = fields[key] ? fields[key].checked : false;
    });
    return value;
  }

  function announceSaved(message, isError) {
    if (!saveState) return;
    saveState.textContent = message || 'GESPEICHERT';
    saveState.classList.toggle('error', isError === true);
    saveState.classList.add('visible');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () { saveState.classList.remove('visible'); }, 2600);
  }

  function saveLocal() {
    if (!preferences) return;
    var stored = preferences.save(collect());
    syncFields(stored);
    announceSaved('LOKAL GESPEICHERT');
  }

  function statusLabel(ok) {
    return ok ? 'BEREIT' : 'FEHLER';
  }

  function renderChecks(checks) {
    if (!healthChecks) return;
    healthChecks.innerHTML = '';
    var names = {
      database: 'COREUI-DATENBANK',
      schema: 'DATENSCHEMA',
      ollama: 'OLLAMA-KERN',
      storage: 'RUNTIME-SPEICHER'
    };
    Object.keys(names).forEach(function (key) {
      var ok = checks && checks[key] === true;
      var item = document.createElement('div');
      item.className = 'health-node ' + (ok ? 'ok' : 'error');
      var light = document.createElement('span');
      light.className = 'health-node-light';
      light.setAttribute('aria-hidden', 'true');
      var name = document.createElement('span');
      name.className = 'health-node-name';
      name.textContent = names[key];
      var result = document.createElement('strong');
      result.textContent = statusLabel(ok);
      item.appendChild(light);
      item.appendChild(name);
      item.appendChild(result);
      healthChecks.appendChild(item);
    });
  }

  function loadHealth() {
    if (refreshButton) refreshButton.disabled = true;
    if (healthSummary) {
      healthSummary.className = 'health-summary checking';
      healthSummary.textContent = 'KERNSTATUS WIRD GEPRÜFT...';
    }
    return fetch('./api/health.php', { credentials: 'include', cache: 'no-store' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (healthSummary) {
          healthSummary.className = 'health-summary ' + (data.ok ? 'ok' : 'error');
          healthSummary.textContent = data.ok ? 'ALLE KERNMODULE BEREIT' : 'KERNPRÜFUNG MIT ABWEICHUNGEN';
        }
        if (healthVersion) healthVersion.textContent = data.version || 'UNBEKANNT';
        if (healthModel) healthModel.textContent = data.model || 'NICHT GEMELDET';
        renderChecks(data.checks || {});
      })
      .catch(function () {
        if (healthSummary) {
          healthSummary.className = 'health-summary error';
          healthSummary.textContent = 'HEALTH-ENDPUNKT NICHT ERREICHBAR';
        }
        renderChecks({});
      })
      .finally(function () { if (refreshButton) refreshButton.disabled = false; });
  }

  function loadAccount() {
    return fetch('./api/auth.php?action=me', { credentials: 'include', cache: 'no-store' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data.ok) {
          location.replace('login.html');
          return;
        }
        csrfToken = data.csrf_token || csrfToken;
        if (accountMail) accountMail.textContent = data.username || 'NICHT GEMELDET';
        if (accountLevel) accountLevel.textContent = 'LV.' + String(data.permission_level == null ? 4 : data.permission_level);
        if (adminEntry && Number(data.permission_level) <= 1) adminEntry.hidden = false;
      })
      .catch(function () {
        if (accountMail) accountMail.textContent = 'STATUS NICHT ERREICHBAR';
      });
  }

  function updatePromptCounter() {
    if (!aiPromptCounter || !aiSystemPrompt) return;
    aiPromptCounter.textContent = String(aiSystemPrompt.value.length) + ' / ' + String(promptMax);
  }

  function loadAiSettings() {
    return api('./api/user_settings.php').then(function (data) {
      csrfToken = data.csrf_token || csrfToken;
      var s = data.settings || {};
      promptMax = Number(data.limits && data.limits.system_prompt_chars) || 6000;
      if (aiSystemPrompt) {
        aiSystemPrompt.maxLength = promptMax;
        aiSystemPrompt.value = s.system_prompt || '';
      }
      if (aiMemoryEnabled) aiMemoryEnabled.checked = s.memory_enabled === true;
      if (aiMemoryLimit) aiMemoryLimit.value = String(s.memory_limit == null ? 16 : s.memory_limit);
      if (aiNumPredict) aiNumPredict.value = String(s.num_predict == null ? 6500 : s.num_predict);
      if (aiTemperature) aiTemperature.value = String(s.temperature == null ? 1 : s.temperature);
      var externalConfigured = data.external && data.external.configured === true;
      if (aiProvider) {
        var externalOption = aiProvider.querySelector('option[value="external"]');
        if (externalOption) externalOption.disabled = !externalConfigured;
        aiProvider.value = externalConfigured && s.provider === 'external' ? 'external' : 'local';
      }
      if (externalNote) {
        var p = externalNote.querySelector('p');
        if (p && data.external && data.external.enabled && !externalConfigured) {
          p.textContent = 'Der externe Adapter ist im Adminbereich aktiviert, aber noch nicht vollständig mit HTTPS-Endpunkt, Modell und serverseitigem Schlüssel konfiguriert. CoreUI bleibt deshalb sicher auf lokalem Ollama.';
        }
      }
      updatePromptCounter();
    }).catch(function (error) {
      announceSaved(error.message === 'missing_schema_002' ? 'MIGRATION 002 FEHLT' : 'KI-EINSTELLUNGEN NICHT ERREICHBAR', true);
    });
  }

  function saveAiSettings() {
    if (!aiSaveButton) return;
    aiSaveButton.disabled = true;
    var body = {
      system_prompt: aiSystemPrompt ? aiSystemPrompt.value : '',
      memory_enabled: aiMemoryEnabled ? aiMemoryEnabled.checked : true,
      memory_limit: aiMemoryLimit ? Number(aiMemoryLimit.value) : 16,
      num_predict: aiNumPredict ? Number(aiNumPredict.value) : 6500,
      temperature: aiTemperature ? Number(aiTemperature.value) : 1,
      provider: aiProvider ? aiProvider.value : 'local'
    };
    api('./api/user_settings.php', { method: 'POST', body: JSON.stringify(body) })
      .then(function () {
        announceSaved('KI-PROFIL SERVERSEITIG GESPEICHERT');
        return loadAiSettings();
      })
      .catch(function (error) { announceSaved('SPEICHERN FEHLGESCHLAGEN: ' + error.message, true); })
      .finally(function () { aiSaveButton.disabled = false; });
  }

  function memoryScopeLabel(item) {
    if (item.scope === 'global') return 'GLOBAL';
    if (item.scope === 'character') return 'CHARAKTER';
    return 'PERSÖNLICH';
  }

  function renderMemories(items) {
    if (!memoryList) return;
    memoryList.innerHTML = '';
    if (!items || !items.length) {
      var empty = document.createElement('div');
      empty.className = 'memory-empty';
      empty.textContent = 'NOCH KEINE SICHTBAREN MEMORY-FAKTEN';
      memoryList.appendChild(empty);
      return;
    }
    items.forEach(function (item) {
      var row = document.createElement('article');
      row.className = 'memory-item';
      var body = document.createElement('div');
      var fact = document.createElement('p');
      fact.textContent = item.fact || '';
      var meta = document.createElement('small');
      meta.textContent = memoryScopeLabel(item) + ' // REL.' + String(item.relevance || 1);
      body.appendChild(fact);
      body.appendChild(meta);
      row.appendChild(body);
      if (item.scope !== 'global') {
        var del = document.createElement('button');
        del.className = 'memory-delete';
        del.type = 'button';
        del.textContent = '×';
        del.setAttribute('aria-label', 'Memory-Fakt löschen');
        del.addEventListener('click', function () { deleteMemory(Number(item.id)); });
        row.appendChild(del);
      }
      memoryList.appendChild(row);
    });
  }

  function loadMemories() {
    if (reloadMemoriesButton) reloadMemoriesButton.disabled = true;
    return api('./api/memories.php?limit=100')
      .then(function (data) { renderMemories(data.items || []); })
      .catch(function (error) {
        renderMemories([]);
        announceSaved('MEMORY NICHT ERREICHBAR: ' + error.message, true);
      })
      .finally(function () { if (reloadMemoriesButton) reloadMemoriesButton.disabled = false; });
  }

  function addMemory() {
    var fact = memoryFact ? memoryFact.value.trim() : '';
    if (fact.length < 3) {
      announceSaved('MEMORY-FAKT IST ZU KURZ', true);
      return;
    }
    if (addMemoryButton) addMemoryButton.disabled = true;
    api('./api/memories.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'upsert', fact: fact, relevance: Number(memoryRelevance.value) || 5, scope: 'user' })
    }).then(function () {
      if (memoryFact) memoryFact.value = '';
      announceSaved('MEMORY-FAKT GESPEICHERT');
      return loadMemories();
    }).catch(function (error) {
      announceSaved('MEMORY-FEHLER: ' + error.message, true);
    }).finally(function () { if (addMemoryButton) addMemoryButton.disabled = false; });
  }

  function deleteMemory(id) {
    if (!id || !window.confirm('Diesen persönlichen Memory-Fakt wirklich löschen?')) return;
    api('./api/memories.php', { method: 'POST', body: JSON.stringify({ action: 'delete', id: id }) })
      .then(function () {
        announceSaved('MEMORY-FAKT GELÖSCHT');
        return loadMemories();
      })
      .catch(function (error) { announceSaved('LÖSCHEN FEHLGESCHLAGEN: ' + error.message, true); });
  }

  Object.keys(fields).forEach(function (key) {
    if (fields[key]) fields[key].addEventListener('change', saveLocal);
  });
  if (aiSystemPrompt) aiSystemPrompt.addEventListener('input', updatePromptCounter);
  if (aiSaveButton) aiSaveButton.addEventListener('click', saveAiSettings);
  if (addMemoryButton) addMemoryButton.addEventListener('click', addMemory);
  if (reloadMemoriesButton) reloadMemoriesButton.addEventListener('click', loadMemories);
  if (refreshButton) refreshButton.addEventListener('click', loadHealth);
  if (resetButton) {
    resetButton.addEventListener('click', function () {
      if (!preferences) return;
      syncFields(preferences.reset());
      announceSaved('ANZEIGE-STANDARD WIEDERHERGESTELLT');
    });
  }
  if (logoutButton) {
    logoutButton.addEventListener('click', function () {
      fetch('./api/auth.php?action=logout', { method: 'POST', credentials: 'include' })
        .finally(function () { location.replace('login.html'); });
    });
  }
  if (backButton) {
    backButton.addEventListener('click', function () {
      history.length > 1 ? history.back() : location.href = 'app.html';
    });
  }

  syncFields(preferences ? preferences.load() : {});
  Promise.resolve(loadAccount()).then(function () {
    return Promise.all([loadAiSettings(), loadMemories(), loadHealth()]);
  });
})();
