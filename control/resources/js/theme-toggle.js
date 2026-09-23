// The theme switch: system -> light -> dark -> system. The theme itself was
// applied before paint by /theme.js; this only changes and labels it.
const order = ['system', 'light', 'dark'];
const names = { system: 'System', light: 'Light', dark: 'Dark' };

export function initThemeToggle() {
  if (!window.cicTheme) return;
  const label = () => document.querySelectorAll('[data-theme-label]').forEach((el) => { el.textContent = names[window.cicTheme.get()]; });
  document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const next = order[(order.indexOf(window.cicTheme.get()) + 1) % order.length];
      window.cicTheme.set(next);
      label();
    });
  });
  label();
}
