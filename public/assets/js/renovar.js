// Renovación automática de la sesión cuando el access token expiró en una
// navegación completa. Archivo local (la CSP prohíbe scripts en línea).
// Solo se envía sola si el servidor lo indica (data-auto="1"); tras un 409
// (otra pestaña renovó) se espera al botón para no entrar en bucle.
(function () {
  'use strict';
  var f = document.getElementById('form-renovar');
  if (f && f.getAttribute('data-auto') === '1') {
    f.submit();
  }
})();
