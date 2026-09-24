/*
 * The editor's built-in extensions, listed the way VS Code lists its own:
 * what each does, the open-source project it comes from, its licence, and -
 * where it can be - a switch. The switch is kept in this browser
 * (localStorage) and applies when the editor next loads, as a VS Code
 * extension that needs a restart does; unsaved edits are kept as drafts.
 *
 * `core` ones are the editor itself and cannot be switched off. The list is
 * resources/data/extensions.json: the home page shows the same entries.
 */
import EXTENSIONS from '../data/extensions.json';

export { EXTENSIONS };

const KEY = 'cic.extensions.off';

function offSet() {
  try {
    return new Set(JSON.parse(localStorage.getItem(KEY) ?? '[]'));
  } catch {
    return new Set();
  }
}

/** Whether an extension is on in this browser. Core ones always are. */
export function enabled(id) {
  const ext = EXTENSIONS.find((e) => e.id === id);
  return Boolean(ext) && (ext.core || !offSet().has(id));
}

/** Switch one on or off. Returns false for a core or unknown one. */
export function setEnabled(id, on) {
  const ext = EXTENSIONS.find((e) => e.id === id);
  if (!ext || ext.core) return false;
  const off = offSet();
  if (on) off.delete(id); else off.add(id);
  try {
    localStorage.setItem(KEY, JSON.stringify([...off]));
  } catch {
    return false;
  }
  return true;
}

/** Every extension with its state, for the view and for cic.extensions(). */
export function listExtensions(loadedState) {
  return EXTENSIONS.map((e) => ({
    ...e,
    enabled: enabled(e.id),
    // Differs from `enabled` after a switch, until the editor is reloaded.
    running: e.core || Boolean(loadedState?.[e.id]),
  }));
}
