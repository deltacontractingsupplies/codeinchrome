import { initThemeToggle } from './theme-toggle.js';

initThemeToggle();

// Creating a site takes a few seconds, and the page only changes when it is
// done: say so at once, where a person and an agent reading the page can see
// it (data-create-status, data-state="creating"), and stop a second click.
const createForm = document.querySelector('form[data-create-site]');
createForm?.addEventListener('submit', () => {
  const status = createForm.querySelector('[data-create-status]');
  const button = createForm.querySelector('[data-create-button]');
  const name = String(createForm.elements.site_id?.value ?? '').trim().toLowerCase();
  if (status) {
    status.textContent = `Creating ${name}.codeinchrome.com... this usually takes 5 to 20 seconds, and this page changes by itself when it is ready.`;
    status.dataset.state = 'creating';
    status.hidden = false;
  }
  if (button) {
    button.disabled = true;
    button.textContent = 'Creating...';
  }
});
