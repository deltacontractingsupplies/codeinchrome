/*
 * The theme, applied BEFORE the first paint so a light-mode visitor never
 * sees a dark flash. Loaded as a plain blocking script from our own origin
 * (the CSP allows no inline script). The choice is the visitor's - light,
 * dark, or system (the default, which follows the OS and its changes).
 */
(function () {
  var KEY = 'cic-theme';
  var root = document.documentElement;
  var media = window.matchMedia('(prefers-color-scheme: light)');
  var choice;
  try { choice = localStorage.getItem(KEY); } catch (e) { choice = null; }

  function apply() {
    var theme = choice === 'light' || choice === 'dark' ? choice : (media.matches ? 'light' : 'dark');
    root.setAttribute('data-theme', theme);
  }
  apply();
  if (media.addEventListener) {
    media.addEventListener('change', function () { if (choice !== 'light' && choice !== 'dark') apply(); });
  }

  window.cicTheme = {
    get: function () { return choice === 'light' || choice === 'dark' ? choice : 'system'; },
    set: function (value) {
      choice = value;
      try { localStorage.setItem(KEY, value); } catch (e) { /* private mode: this page only */ }
      apply();
    },
  };
})();
