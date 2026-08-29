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
  var accountDisplayName = document.getElementById('accountDisplayName');
  var accountAvatar = document.getElementById('accountAvatar');
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
  var aiThinkingEnabled = document.getElementById('aiThinkingEnabled');
  var aiMemoryLimit = document.getElementById('aiMemoryLimit');
  var aiNumPredict = document.getElementById('aiNumPredict');
  var aiTemperature = document.getElementById('aiTemperature');
  var aiProvider = document.getElementById('aiProvider');
  var aiModelOverride = document.getElementById('aiModelOverride');
  var refreshModelsButton = document.getElementById('refreshModels');
  var externalNote = document.getElementById('externalProviderNote');
  var memoryFact = document.getElementById('memoryFact');
  var memoryRelevance = document.getElementById('memoryRelevance');
  var memoryList = document.getElementById('memoryList');
  var addMemoryButton = document.getElementById('addMemory');
  var reloadMemoriesButton = document.getElementById('reloadMemories');
  var saveProfileButton = document.getElementById('saveProfile');
  var profileDisplayName = document.getElementById('profileDisplayName');
  var assistantDisplayName = document.getElementById('assistantDisplayName');
  var userAvatarPreview = document.getElementById('userAvatarPreview');
  var assistantAvatarPreview = document.getElementById('assistantAvatarPreview');
  var userAvatarFile = document.getElementById('userAvatarFile');
  var assistantAvatarFile = document.getElementById('assistantAvatarFile');
  var removeUserAvatar = document.getElementById('removeUserAvatar');
  var removeAssistantAvatar = document.getElementById('removeAssistantAvatar');
  var knowledgeTitleInput = document.getElementById('knowledgeTitleInput');
  var knowledgeFile = document.getElementById('knowledgeFile');
  var knowledgeFileName = document.getElementById('knowledgeFileName');
  var knowledgeDrop = document.querySelector('.knowledge-file-drop');
  var uploadKnowledgeButton = document.getElementById('uploadKnowledge');
  var reloadKnowledgeButton = document.getElementById('reloadKnowledge');
  var knowledgeList = document.getElementById('knowledgeList');
  var knowledgeSourceCount = document.getElementById('knowledgeSourceCount');
  var knowledgeChunkCount = document.getElementById('knowledgeChunkCount');
  var knowledgeGlobalCount = document.getElementById('knowledgeGlobalCount');
  var currentPassword = document.getElementById('currentPassword');
  var newPassword = document.getElementById('newPassword');
  var confirmPassword = document.getElementById('confirmPassword');
  var changePasswordButton = document.getElementById('changePassword');
  var loginSessionList = document.getElementById('loginSessionList');
  var revokeOtherSessionsButton = document.getElementById('revokeOtherSessions');
  var exportAccountDataButton = document.getElementById('exportAccountData');
  var saveTimer = 0;
  var csrfToken = '';
  var promptMax = 6000;
  var selectedKnowledgeFile = null;
  var selectedModelOverride = '';

  function api(url, options) {
    options = options || {};
    options.credentials = 'include';
    options.cache = 'no-store';
    options.headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
    if (options.method === 'POST') {
      options.headers['X-CoreUI-CSRF'] = csrfToken;
      if (!(options.body instanceof FormData)) options.headers['Content-Type'] = 'application/json';
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
        if (accountDisplayName) accountDisplayName.textContent = (data.display_name || 'ANGEMELDETER OPERATOR').toUpperCase();
        setAvatarPreview(accountAvatar, data.avatars && data.avatars.user, data.display_name || data.username || 'OP');
        if (accountLevel) accountLevel.textContent = 'LV.' + String(data.permission_level == null ? 4 : data.permission_level);
        if (adminEntry && Number(data.permission_level) <= 1) adminEntry.hidden = false;
      })
      .catch(function () {
        if (accountMail) accountMail.textContent = 'STATUS NICHT ERREICHBAR';
      });
  }

  function initials(value, fallback) {
    var clean = String(value || '').trim();
    if (!clean) return fallback || 'OP';
    var parts = clean.split(/\s+/).filter(Boolean);
    if (parts.length > 1) return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    return clean.substring(0, 2).toUpperCase();
  }

  function setAvatarPreview(element, url, name) {
    if (!element) return;
    element.textContent = initials(name, element === assistantAvatarPreview ? 'EM' : 'OP');
    element.classList.toggle('has-image', !!url);
    element.style.backgroundImage = url ? ('url("' + String(url).replace(/"/g, '%22') + '")') : '';
  }

  function applyProfile(profile) {
    profile = profile || {};
    if (profileDisplayName) profileDisplayName.value = profile.display_name || '';
    if (assistantDisplayName) assistantDisplayName.value = profile.assistant_name || 'Ember';
    var avatars = profile.avatars || {};
    setAvatarPreview(userAvatarPreview, avatars.user, profile.display_name || 'Operator');
    setAvatarPreview(assistantAvatarPreview, avatars.assistant, profile.assistant_name || 'Ember');
    setAvatarPreview(accountAvatar, avatars.user, profile.display_name || 'Operator');
    if (accountDisplayName) accountDisplayName.textContent = (profile.display_name || 'ANGEMELDETER OPERATOR').toUpperCase();
  }

  function loadProfile() {
    return api('./api/profile.php').then(function (data) {
      csrfToken = data.csrf_token || csrfToken;
      applyProfile(data.profile || {});
    }).catch(function (error) {
      announceSaved(error.message === 'missing_schema_004' ? 'MIGRATION 004 FEHLT' : 'PROFILE NICHT ERREICHBAR', true);
    });
  }

  function saveProfile() {
    if (!saveProfileButton) return;
    saveProfileButton.disabled = true;
    api('./api/profile.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'save',
        display_name: profileDisplayName ? profileDisplayName.value : '',
        assistant_name: assistantDisplayName ? assistantDisplayName.value : 'Ember'
      })
    }).then(function (data) {
      applyProfile(data.profile || {});
      announceSaved('PROFILE SERVERSEITIG GESPEICHERT');
    }).catch(function (error) {
      announceSaved('PROFILFEHLER: ' + error.message, true);
    }).finally(function () { saveProfileButton.disabled = false; });
  }

  function uploadAvatar(slot, file) {
    if (!file) return;
    var form = new FormData();
    form.append('slot', slot);
    form.append('file', file, file.name || 'avatar');
    announceSaved('PROFILBILD WIRD VERARBEITET');
    api('./api/profile.php', { method: 'POST', body: form }).then(function (data) {
      applyProfile(data.profile || {});
      announceSaved(slot === 'assistant' ? 'COREAI-BILD GESPEICHERT' : 'BENUTZERBILD GESPEICHERT');
    }).catch(function (error) {
      announceSaved('BILDUPLOAD FEHLGESCHLAGEN: ' + error.message, true);
    }).finally(function () {
      if (slot === 'assistant' && assistantAvatarFile) assistantAvatarFile.value = '';
      if (slot === 'user' && userAvatarFile) userAvatarFile.value = '';
    });
  }

  function deleteAvatar(slot) {
    if (!window.confirm((slot === 'assistant' ? 'Das CoreAI-Bild' : 'Dein Profilbild') + ' wirklich entfernen?')) return;
    api('./api/profile.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'avatar_delete', slot: slot })
    }).then(function (data) {
      applyProfile(data.profile || {});
      announceSaved('PROFILBILD ENTFERNT');
    }).catch(function (error) { announceSaved('ENTFERNEN FEHLGESCHLAGEN: ' + error.message, true); });
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
      if (aiThinkingEnabled) aiThinkingEnabled.checked = s.thinking_enabled !== false;
      if (aiMemoryLimit) aiMemoryLimit.value = String(s.memory_limit == null ? 16 : s.memory_limit);
      if (aiNumPredict) aiNumPredict.value = String(s.num_predict == null ? 6500 : s.num_predict);
      if (aiTemperature) aiTemperature.value = String(s.temperature == null ? 1 : s.temperature);
      selectedModelOverride = s.model_override || '';
      if (aiModelOverride) {
        var modelOptionExists = Array.prototype.some.call(aiModelOverride.options || [], function (option) {
          return option.value === selectedModelOverride;
        });
        if (selectedModelOverride && !modelOptionExists) {
          var storedOption = document.createElement('option');
          storedOption.value = selectedModelOverride;
          storedOption.textContent = selectedModelOverride + ' // GESPEICHERT';
          aiModelOverride.appendChild(storedOption);
        }
        aiModelOverride.value = selectedModelOverride;
      }
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
      announceSaved(
        error.message === 'missing_schema_005' ? 'MIGRATION 005 FEHLT' : 'KI-EINSTELLUNGEN NICHT ERREICHBAR',
        true
      );
    });
  }

  function loadModels() {
    if (!aiModelOverride) return Promise.resolve();
    if (refreshModelsButton) refreshModelsButton.disabled = true;
    var desired = aiModelOverride.value || selectedModelOverride || '';
    return api('./api/models.php').then(function (data) {
      aiModelOverride.innerHTML = '';
      var standard = document.createElement('option');
      standard.value = '';
      standard.textContent = data.default_model
        ? 'SERVERSTANDARD // ' + String(data.default_model)
        : 'SERVERSTANDARD';
      aiModelOverride.appendChild(standard);
      var found = desired === '';
      (data.models || []).forEach(function (model) {
        var name = String(model.name || '');
        if (!name) return;
        var option = document.createElement('option');
        option.value = name;
        option.textContent = name + (model.parameter_size ? ' // ' + model.parameter_size : '');
        aiModelOverride.appendChild(option);
        if (name === desired) found = true;
      });
      if (desired && !found) {
        var unavailable = document.createElement('option');
        unavailable.value = desired;
        unavailable.textContent = desired + ' // NICHT MEHR INSTALLIERT';
        unavailable.disabled = true;
        aiModelOverride.appendChild(unavailable);
      }
      aiModelOverride.value = desired;
    }).catch(function (error) {
      announceSaved('OLLAMA-MODELLLISTE NICHT ERREICHBAR: ' + error.message, true);
    }).finally(function () { if (refreshModelsButton) refreshModelsButton.disabled = false; });
  }

  function saveAiSettings() {
    if (!aiSaveButton) return;
    aiSaveButton.disabled = true;
    var body = {
      system_prompt: aiSystemPrompt ? aiSystemPrompt.value : '',
      memory_enabled: aiMemoryEnabled ? aiMemoryEnabled.checked : true,
      thinking_enabled: aiThinkingEnabled ? aiThinkingEnabled.checked : true,
      memory_limit: aiMemoryLimit ? Number(aiMemoryLimit.value) : 16,
      num_predict: aiNumPredict ? Number(aiNumPredict.value) : 6500,
      temperature: aiTemperature ? Number(aiTemperature.value) : 1,
      provider: aiProvider ? aiProvider.value : 'local',
      model_override: aiModelOverride ? aiModelOverride.value.trim() : ''
    };
    api('./api/user_settings.php', { method: 'POST', body: JSON.stringify(body) })
      .then(function () {
        announceSaved('KI-PROFIL SERVERSEITIG GESPEICHERT');
        return loadAiSettings();
      })
      .catch(function (error) {
        var labels = {
          model_not_available: 'MODELL IST NICHT MEHR IN OLLAMA INSTALLIERT',
          model_catalog_unavailable: 'OLLAMA-MODELLLISTE IST NICHT ERREICHBAR',
          model_catalog_invalid_response: 'OLLAMA-MODELLLISTE IST UNGÜLTIG',
          invalid_model_name: 'UNGÜLTIGER MODELLNAME'
        };
        announceSaved(labels[error.message] || ('SPEICHERN FEHLGESCHLAGEN: ' + error.message), true);
      })
      .finally(function () { aiSaveButton.disabled = false; });
  }

  function formatSessionDate(value) {
    if (!value) return 'UNBEKANNT';
    var parsed = new Date(String(value).replace(' ', 'T') + (/Z$|[+-]\d\d:\d\d$/.test(String(value)) ? '' : 'Z'));
    return isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString('de-DE');
  }

  function renderLoginSessions(items) {
    if (!loginSessionList) return;
    loginSessionList.innerHTML = '';
    if (!items || !items.length) {
      var empty = document.createElement('div');
      empty.className = 'memory-empty';
      empty.textContent = 'KEINE AKTIVEN ANMELDUNGEN GEMELDET';
      loginSessionList.appendChild(empty);
      return;
    }
    items.forEach(function (item) {
      var row = document.createElement('article');
      row.className = 'login-session-item' + (item.current ? ' current' : '');
      var copy = document.createElement('div');
      var label = document.createElement('strong');
      label.textContent = item.device_label || 'Unbekanntes Gerät';
      var meta = document.createElement('small');
      meta.textContent = 'ZULETZT AKTIV: ' + formatSessionDate(item.last_seen_at)
        + ' // LÄUFT AB: ' + formatSessionDate(item.expires_at);
      copy.appendChild(label);
      copy.appendChild(meta);
      row.appendChild(copy);
      if (item.current) {
        var current = document.createElement('span');
        current.className = 'login-session-current';
        current.textContent = 'DIESES GERÄT';
        row.appendChild(current);
      } else {
        var revoke = document.createElement('button');
        revoke.className = 'settings-action compact danger';
        revoke.type = 'button';
        revoke.textContent = 'WIDERRUFEN';
        revoke.addEventListener('click', function () { revokeLoginSession(Number(item.id)); });
        row.appendChild(revoke);
      }
      loginSessionList.appendChild(row);
    });
  }

  function loadAccountSecurity() {
    return api('./api/account_security.php').then(function (data) {
      csrfToken = data.csrf_token || csrfToken;
      renderLoginSessions(data.sessions || []);
    }).catch(function (error) {
      renderLoginSessions([]);
      announceSaved(error.message === 'missing_schema_006' ? 'MIGRATION 006 FEHLT' : 'KONTOSICHERHEIT NICHT ERREICHBAR', true);
    });
  }

  function changePassword() {
    var currentValue = currentPassword ? currentPassword.value : '';
    var nextValue = newPassword ? newPassword.value : '';
    var confirmValue = confirmPassword ? confirmPassword.value : '';
    if (nextValue.length < 12) {
      announceSaved('NEUES PASSWORT MUSS MINDESTENS 12 ZEICHEN HABEN', true);
      return;
    }
    if (nextValue !== confirmValue) {
      announceSaved('PASSWORTBESTÄTIGUNG STIMMT NICHT ÜBEREIN', true);
      return;
    }
    if (changePasswordButton) changePasswordButton.disabled = true;
    api('./api/account_security.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'password_change',
        current_password: currentValue,
        new_password: nextValue,
        confirm_password: confirmValue
      })
    }).then(function (data) {
      if (currentPassword) currentPassword.value = '';
      if (newPassword) newPassword.value = '';
      if (confirmPassword) confirmPassword.value = '';
      csrfToken = data.csrf_token || csrfToken;
      renderLoginSessions(data.sessions || []);
      announceSaved('PASSWORT GEÄNDERT, ANDERE GERÄTE ABGEMELDET');
    }).catch(function (error) {
      var labels = {
        invalid_current_password: 'AKTUELLES PASSWORT IST FALSCH',
        invalid_new_password: 'NEUES PASSWORT IST UNGÜLTIG',
        password_confirmation_mismatch: 'PASSWORTBESTÄTIGUNG STIMMT NICHT ÜBEREIN',
        password_unchanged: 'NEUES PASSWORT ENTSPRICHT DEM ALTEN',
        rate_limited: 'ZU VIELE VERSUCHE, BITTE SPÄTER ERNEUT VERSUCHEN'
      };
      announceSaved(labels[error.message] || ('PASSWORTÄNDERUNG FEHLGESCHLAGEN: ' + error.message), true);
    }).finally(function () { if (changePasswordButton) changePasswordButton.disabled = false; });
  }

  function revokeLoginSession(sessionId) {
    if (!sessionId || !window.confirm('Diese Geräte-Anmeldung wirklich widerrufen?')) return;
    api('./api/account_security.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'session_revoke', session_id: sessionId })
    }).then(function (data) {
      renderLoginSessions(data.sessions || []);
      announceSaved('GERÄTE-ANMELDUNG WIDERRUFEN');
    }).catch(function (error) { announceSaved('WIDERRUF FEHLGESCHLAGEN: ' + error.message, true); });
  }

  function revokeOtherLoginSessions() {
    if (!window.confirm('Alle anderen Geräte sofort von CoreUI abmelden?')) return;
    if (revokeOtherSessionsButton) revokeOtherSessionsButton.disabled = true;
    api('./api/account_security.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'sessions_revoke_others' })
    }).then(function (data) {
      renderLoginSessions(data.sessions || []);
      announceSaved(String(data.revoked_count || 0) + ' ANDERE ANMELDUNGEN WIDERRUFEN');
    }).catch(function (error) { announceSaved('ABMELDEN FEHLGESCHLAGEN: ' + error.message, true); })
      .finally(function () { if (revokeOtherSessionsButton) revokeOtherSessionsButton.disabled = false; });
  }

  function exportAccountData() {
    if (!exportAccountDataButton) return;
    exportAccountDataButton.disabled = true;
    fetch('./api/account_export.php', {
      method: 'POST',
      credentials: 'include',
      cache: 'no-store',
      headers: { 'Accept': 'application/json', 'X-CoreUI-CSRF': csrfToken }
    }).then(function (response) {
      if (!response.ok) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          throw new Error(data.error || ('HTTP_' + response.status));
        });
      }
      var disposition = response.headers.get('Content-Disposition') || '';
      var match = disposition.match(/filename="([^"]+)"/i);
      return response.blob().then(function (blob) { return { blob: blob, name: match ? match[1] : 'ember-coreui-account.json' }; });
    }).then(function (download) {
      var url = URL.createObjectURL(download.blob);
      var link = document.createElement('a');
      link.href = url;
      link.download = download.name;
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      announceSaved('PRIVATER KONTOEXPORT ERSTELLT');
    }).catch(function (error) {
      announceSaved('DATENEXPORT FEHLGESCHLAGEN: ' + error.message, true);
    }).finally(function () { exportAccountDataButton.disabled = false; });
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

  function formatBytes(value) {
    var bytes = Number(value) || 0;
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
  }

  function renderKnowledge(data) {
    var totals = data.totals || {};
    if (knowledgeSourceCount) knowledgeSourceCount.textContent = String(totals.sources || 0);
    if (knowledgeChunkCount) knowledgeChunkCount.textContent = String(totals.chunks || 0);
    if (knowledgeGlobalCount) knowledgeGlobalCount.textContent = String(totals.global_chunks || 0) + ' CHUNKS';
    if (!knowledgeList) return;
    knowledgeList.innerHTML = '';
    var sources = data.sources || [];
    if (!sources.length) {
      var empty = document.createElement('div');
      empty.className = 'memory-empty';
      empty.textContent = 'NOCH KEINE PRIVATEN WISSENSQUELLEN';
      knowledgeList.appendChild(empty);
      return;
    }
    sources.forEach(function (source) {
      var row = document.createElement('article');
      row.className = 'knowledge-item';
      var copy = document.createElement('div');
      var title = document.createElement('strong');
      title.textContent = source.title || source.original_name || 'Dokument';
      var meta = document.createElement('small');
      meta.textContent = String(source.chunk_count || 0) + ' CHUNKS // ' + formatBytes(source.file_size)
        + ' // ' + String(source.status || 'ready').toUpperCase();
      copy.appendChild(title);
      copy.appendChild(meta);
      var remove = document.createElement('button');
      remove.className = 'settings-action compact danger';
      remove.type = 'button';
      remove.textContent = 'LÖSCHEN';
      remove.addEventListener('click', function () { deleteKnowledge(source.uuid, source.title || source.original_name); });
      row.appendChild(copy);
      row.appendChild(remove);
      knowledgeList.appendChild(row);
    });
  }

  function loadKnowledge() {
    if (reloadKnowledgeButton) reloadKnowledgeButton.disabled = true;
    return api('./api/knowledge.php').then(function (data) {
      csrfToken = data.csrf_token || csrfToken;
      renderKnowledge(data);
    }).catch(function (error) {
      renderKnowledge({ sources: [], totals: {} });
      announceSaved(error.message === 'missing_schema_004' ? 'MIGRATION 004 FEHLT' : 'RAG-LITE NICHT ERREICHBAR: ' + error.message, true);
    }).finally(function () { if (reloadKnowledgeButton) reloadKnowledgeButton.disabled = false; });
  }

  function selectKnowledgeFile(file) {
    selectedKnowledgeFile = file || null;
    if (knowledgeFileName) knowledgeFileName.textContent = file ? file.name : 'TXT, MD, PDF ODER DOCX AUSWÄHLEN';
  }

  function uploadKnowledge() {
    var file = selectedKnowledgeFile || (knowledgeFile && knowledgeFile.files && knowledgeFile.files[0]);
    if (!file) {
      announceSaved('BITTE ZUERST EIN DOKUMENT AUSWÄHLEN', true);
      return;
    }
    if (uploadKnowledgeButton) uploadKnowledgeButton.disabled = true;
    var form = new FormData();
    form.append('file', file, file.name || 'dokument');
    form.append('title', knowledgeTitleInput ? knowledgeTitleInput.value.trim() : '');
    announceSaved('DOKUMENT WIRD GELESEN UND GECHUNKT');
    api('./api/knowledge.php', { method: 'POST', body: form }).then(function (data) {
      renderKnowledge(data);
      selectKnowledgeFile(null);
      if (knowledgeFile) knowledgeFile.value = '';
      if (knowledgeTitleInput) knowledgeTitleInput.value = '';
      announceSaved('RAG-QUELLE MIT ' + String(data.source && data.source.chunk_count || 0) + ' CHUNKS BEREIT');
    }).catch(function (error) {
      var labels = {
        pdf_has_no_text_layer: 'PDF HAT KEINE TEXTEBENE',
        pdf_text_support_unavailable: 'PDF-TEXTWERKZEUG IST NICHT VERFÜGBAR',
        docx_support_unavailable: 'DOCX-UNTERSTÜTZUNG IST NICHT VERFÜGBAR',
        knowledge_file_too_large: 'DOKUMENT IST ZU GROSS',
        knowledge_format_not_allowed: 'DATEIFORMAT NICHT ERLAUBT',
        knowledge_mime_mismatch: 'DATEIINHALT PASST NICHT ZUM DATEIFORMAT',
        knowledge_text_too_short: 'DOKUMENT ENTHÄLT ZU WENIG LESBAREN TEXT',
        knowledge_chunk_limit_exceeded: 'DOKUMENT ERZEUGT ZU VIELE CHUNKS',
        knowledge_busy: 'EIN ANDERER UPLOAD FÜR DIESES KONTO LÄUFT NOCH',
        knowledge_total_limit_reached: 'PERSÖNLICHES WISSENSLIMIT ERREICHT',
        knowledge_source_limit_reached: 'QUELLENLIMIT ERREICHT'
      };
      announceSaved(labels[error.message] || ('RAG-UPLOAD FEHLGESCHLAGEN: ' + error.message), true);
    }).finally(function () { if (uploadKnowledgeButton) uploadKnowledgeButton.disabled = false; });
  }

  function deleteKnowledge(uuid, title) {
    if (!uuid || !window.confirm('Wissensquelle "' + String(title || 'Dokument') + '" inklusive aller Chunks löschen?')) return;
    api('./api/knowledge.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'delete', uuid: uuid })
    }).then(function (data) {
      renderKnowledge(data);
      announceSaved('QUELLE, DATEI UND CHUNKS GELÖSCHT');
    }).catch(function (error) { announceSaved('RAG-LÖSCHEN FEHLGESCHLAGEN: ' + error.message, true); });
  }

  Object.keys(fields).forEach(function (key) {
    if (fields[key]) fields[key].addEventListener('change', saveLocal);
  });
  if (aiSystemPrompt) aiSystemPrompt.addEventListener('input', updatePromptCounter);
  if (aiSaveButton) aiSaveButton.addEventListener('click', saveAiSettings);
  if (refreshModelsButton) refreshModelsButton.addEventListener('click', loadModels);
  if (saveProfileButton) saveProfileButton.addEventListener('click', saveProfile);
  if (userAvatarFile) userAvatarFile.addEventListener('change', function () { uploadAvatar('user', userAvatarFile.files && userAvatarFile.files[0]); });
  if (assistantAvatarFile) assistantAvatarFile.addEventListener('change', function () { uploadAvatar('assistant', assistantAvatarFile.files && assistantAvatarFile.files[0]); });
  if (removeUserAvatar) removeUserAvatar.addEventListener('click', function () { deleteAvatar('user'); });
  if (removeAssistantAvatar) removeAssistantAvatar.addEventListener('click', function () { deleteAvatar('assistant'); });
  if (addMemoryButton) addMemoryButton.addEventListener('click', addMemory);
  if (reloadMemoriesButton) reloadMemoriesButton.addEventListener('click', loadMemories);
  if (knowledgeFile) knowledgeFile.addEventListener('change', function () { selectKnowledgeFile(knowledgeFile.files && knowledgeFile.files[0]); });
  if (uploadKnowledgeButton) uploadKnowledgeButton.addEventListener('click', uploadKnowledge);
  if (reloadKnowledgeButton) reloadKnowledgeButton.addEventListener('click', loadKnowledge);
  if (changePasswordButton) changePasswordButton.addEventListener('click', changePassword);
  if (revokeOtherSessionsButton) revokeOtherSessionsButton.addEventListener('click', revokeOtherLoginSessions);
  if (exportAccountDataButton) exportAccountDataButton.addEventListener('click', exportAccountData);
  if (knowledgeDrop) {
    ['dragenter', 'dragover'].forEach(function (eventName) {
      knowledgeDrop.addEventListener(eventName, function (event) { event.preventDefault(); knowledgeDrop.classList.add('dragging'); });
    });
    ['dragleave', 'drop'].forEach(function (eventName) {
      knowledgeDrop.addEventListener(eventName, function (event) { event.preventDefault(); knowledgeDrop.classList.remove('dragging'); });
    });
    knowledgeDrop.addEventListener('drop', function (event) {
      selectKnowledgeFile(event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]);
    });
  }
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
    return Promise.all([
      loadProfile(),
      loadAiSettings().then(loadModels),
      loadKnowledge(),
      loadMemories(),
      loadHealth(),
      loadAccountSecurity()
    ]);
  });
})();
