/* Project STΛRLIɢHT: Ember CoreUI
   Lokale Oberflaechen-Einstellungen ohne Abhaengigkeit von fremden Pfaden. */
(function () {
  'use strict';

  var STORAGE_KEY = 'emberCoreUI.preferences.v1';
  var defaults = {
    reduceMotion: false,
    compactFeed: false,
    thinkingExpanded: false,
    liveFrameMotion: true,
    showTelemetry: true
  };

  function normalise(input) {
    var source = input && typeof input === 'object' ? input : {};
    return {
      reduceMotion: source.reduceMotion === true,
      compactFeed: source.compactFeed === true,
      thinkingExpanded: source.thinkingExpanded === true,
      liveFrameMotion: source.liveFrameMotion !== false,
      showTelemetry: source.showTelemetry !== false
    };
  }

  function load() {
    try {
      var raw = window.localStorage.getItem(STORAGE_KEY);
      return raw ? normalise(JSON.parse(raw)) : normalise(defaults);
    } catch (error) {
      return normalise(defaults);
    }
  }

  function apply(targetDocument, value) {
    var doc = targetDocument || document;
    var root = doc.documentElement;
    var prefs = normalise(value || load());
    if (!root) return prefs;

    root.classList.toggle('pref-reduced-motion', prefs.reduceMotion);
    root.classList.toggle('pref-compact-feed', prefs.compactFeed);
    root.classList.toggle('pref-thinking-expanded', prefs.thinkingExpanded);
    root.classList.toggle('pref-live-static', !prefs.liveFrameMotion);
    root.classList.toggle('pref-hide-telemetry', !prefs.showTelemetry);
    return prefs;
  }

  function save(value) {
    var prefs = normalise(value);
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
    } catch (error) {}
    apply(document, prefs);
    try {
      window.dispatchEvent(new CustomEvent('coreui:preferences', { detail: prefs }));
    } catch (error) {}
    return prefs;
  }

  function reset() {
    try {
      window.localStorage.removeItem(STORAGE_KEY);
    } catch (error) {}
    return save(defaults);
  }

  window.CoreUIPreferences = Object.freeze({
    key: STORAGE_KEY,
    defaults: Object.freeze(normalise(defaults)),
    load: load,
    save: save,
    reset: reset,
    apply: apply
  });

  apply(document, load());
  window.addEventListener('storage', function (event) {
    if (event.key === STORAGE_KEY) apply(document, load());
  });
})();
