/* Ember CoreUI standalone bootstrap.
   Browser, PWA and WebView are gleichberechtigte Clients. Eine fremde
   Root-Seite oder ein STU-Host wird bewusst nicht vorausgesetzt. */
(function () {
  var script = document.currentScript;
  var scriptUrl = script && script.src ? new URL(script.src, document.baseURI) : new URL('./js/', document.baseURI);
  var projectUrl = new URL('../', scriptUrl);

  window.EMBER_COREUI = Object.freeze({
    version: '0.5.3-alpha',
    projectUrl: projectUrl.href,
    apiUrl: new URL('api/', projectUrl).href
  });
})();
