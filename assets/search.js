/* The Search block's behaviour: filled in by its own task; for now it only guards against running
   twice (every block emits its own script tag). */
(function () {
  if (window.thalloSearch) { return; }
  window.thalloSearch = { init: function () {} };
})();
