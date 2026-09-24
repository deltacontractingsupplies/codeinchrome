import { test, expect } from '@playwright/test';
import { confirmSignup } from '../helpers/fixtures.js';
import { waitForDns } from '../helpers/dns.js';
import { destroySite } from '../helpers/cleanup.js';

/**
 * The file explorer behaves as VS Code's does (its source is the reference):
 * right-click menus, new files and renames typed into the row itself with
 * VS Code's validation messages, cut/copy/paste with its collision naming,
 * Collapse Folders, and its keys. No native dialog ever opens.
 */

const stamp = Date.now().toString(36);
const siteName = `ex-${stamp}`.slice(0, 40);
const email = `ex-${stamp}@codeinchrome.test`;
const password = `ex-${stamp}-${Math.random().toString(36).slice(2)}-Hv3`;
const mac = process.platform === 'darwin';

test.describe.configure({ mode: 'serial' });
test.afterAll(() => destroySite(siteName));
test.setTimeout(600_000);

test('the explorer works like VS Code: menus, inline create and rename, clipboard, collapse', async ({ page }) => {
  page.on('dialog', (d) => { throw new Error(`native dialog: ${d.message()}`); });
  const node = (path) => page.locator(`#tree .node[data-path="${path}"]`);
  const rowInput = page.locator('#tree input.inline-edit');
  const menuItem = (name) => page.locator('#nodeMenu [role="menuitem"]', { hasText: name });

  await test.step('a site to work in', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill('Explorer Runner');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await confirmSignup(page, email);
    await page.getByPlaceholder('my-shop').fill(siteName);
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText(/is building/)).toBeVisible();
    await waitForDns(`${siteName}.codeinchrome.com`);
    await page.goto(`/sites/${siteName}/edit`);
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
  });

  await test.step('right-click a folder: VS Code\'s menu, in its groups', async () => {
    await node('/app').click({ button: 'right' });
    const labels = await page.locator('#nodeMenu [role="menuitem"] > span:first-child').allTextContents();
    expect(labels).toEqual(['New File…', 'New Folder…', 'Find in Folder…', 'Cut', 'Copy', 'Paste', 'Upload…', 'Zip…',
      'Copy Path', 'Copy Relative Path', 'Rename…', 'Delete', 'Properties']);
    await expect(menuItem('Paste')).toBeDisabled(); // nothing copied yet
    expect(await page.locator('#nodeMenu [role="separator"]').count()).toBe(6);
  });

  await test.step('New File… types into a row inside the folder, and opens the file', async () => {
    await menuItem('New File…').click();
    await expect(rowInput).toBeFocused();
    await expect(page.locator('#tree')).toHaveClass(/editing/); // the rest dimmed
    await rowInput.fill('Probe.php');
    await page.keyboard.press('Enter');
    await expect(node('/app/Probe.php')).toBeVisible();
    await expect(page.locator('.tab.active')).toContainText('Probe.php');
  });

  await test.step('an existing name is refused inline, with VS Code\'s wording; Escape cancels', async () => {
    await node('/app').click({ button: 'right' });
    await menuItem('New File…').click();
    await rowInput.fill('Probe.php');
    await expect(page.locator('#tree .inline-msg')).toHaveText('A file or folder Probe.php already exists at this location. Please choose a different name.');
    await page.keyboard.press('Enter'); // blocked: still editing
    await expect(rowInput).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(rowInput).toHaveCount(0);
  });

  await test.step('F2 renames in place, the name selected without its extension', async () => {
    await node('/app/Probe.php').focus();
    await page.keyboard.press('F2');
    await expect(rowInput).toHaveValue('Probe.php');
    expect(await rowInput.evaluate((i) => [i.selectionStart, i.selectionEnd])).toEqual([0, 5]);
    await page.keyboard.type('Renamed');
    await page.keyboard.press('Enter');
    await expect(node('/app/Renamed.php')).toBeVisible();
    await expect(node('/app/Probe.php')).toHaveCount(0);
    await expect(page.locator('.tab.active')).toContainText('Renamed.php'); // the open tab follows
  });

  await test.step('copy and paste: "name copy.ext", as VS Code names it', async () => {
    await node('/app/Renamed.php').focus();
    await page.keyboard.press(mac ? 'Meta+C' : 'Control+C');
    await page.keyboard.press(mac ? 'Meta+V' : 'Control+V');
    await expect(node('/app/Renamed copy.php')).toBeVisible();
  });

  await test.step('cut and paste into another folder moves it', async () => {
    await node('/app/Renamed copy.php').click({ button: 'right' });
    await menuItem('Cut').click();
    await expect(node('/app/Renamed copy.php')).toHaveClass(/cut/);
    await node('/routes').click({ button: 'right' });
    await menuItem('Paste').click();
    await expect(node('/routes/Renamed copy.php')).toBeVisible();
    await expect(node('/app/Renamed copy.php')).toHaveCount(0);
  });

  await test.step('New Folder with a nested path, from the root\'s own menu', async () => {
    await page.locator('.side-site').click({ button: 'right' });
    await expect(menuItem('Rename…')).toHaveCount(0); // the root cannot be renamed
    await menuItem('New Folder…').click();
    await rowInput.fill('storage/app/reports/2026');
    await page.keyboard.press('Enter');
    await expect(node('/storage/app/reports/2026')).toBeVisible();
  });

  await test.step('Collapse Folders closes everything below the root', async () => {
    await page.getByRole('button', { name: 'Collapse Folders in Explorer' }).click();
    await expect(page.locator('#tree .node[aria-expanded="true"]')).toHaveCount(0);
    await expect(node('/app')).toBeVisible();
  });

  await test.step('Delete asks in the page, never natively, and the file goes to the bin', async () => {
    await node('/app').click();
    await node('/app/Renamed.php').click({ button: 'right' });
    await menuItem('Delete').click();
    await expect(page.locator('#modalText')).toContainText("Are you sure you want to delete 'Renamed.php'?");
    await page.locator('#modalOk').click();
    await expect(node('/app/Renamed.php')).toHaveCount(0);
    const bin = await page.evaluate(() => window.cic.bin());
    expect(bin.bin.some((b) => b.path === 'app/Renamed.php'), JSON.stringify(bin.bin.slice(0, 5))).toBe(true);
  });

  await test.step('Quick Open finds a file by scattered letters; the palette runs commands', async () => {
    await page.locator('.monaco-editor').first().click();
    await page.keyboard.press(mac ? 'Meta+P' : 'Control+P');
    await expect(page.locator('#quickInput')).toBeFocused();
    await page.keyboard.type('rtsweb');
    await expect(page.locator('.quick-row.on .quick-label')).toHaveText('web.php');
    await expect(page.locator('.quick-row.on .quick-detail')).toHaveText('routes');
    await page.keyboard.press('Enter');
    await expect(page.locator('.tab.active')).toContainText('web.php');

    await node('/app').click(); // expand something, so there is something to collapse
    await page.keyboard.press(mac ? 'Meta+Shift+P' : 'Control+Shift+P');
    await expect(page.locator('#quickInput')).toHaveValue('>');
    await page.keyboard.type('collapse');
    await page.keyboard.press('Enter');
    await expect(page.locator('#tree .node[aria-expanded="true"]')).toHaveCount(0);
  });

  await test.step('Emmet expands in Blade, and Format Document formats PHP', async () => {
    expect((await page.evaluate(() => window.cic.writeMany({
      '/resources/views/emmet.blade.php': '\n',
      '/app/Messy.php': "<?php\n$a=[1,2,  3];function f( $x ){return $x+1;}\n",
    }))).ok).toBe(true);
    await page.evaluate(() => window.cic.open('/resources/views/emmet.blade.php'));
    await page.locator('.monaco-editor .view-lines').click();
    await page.keyboard.type('ul>li*2');
    await page.keyboard.press('Tab');
    await expect.poll(() => page.evaluate(() => window.cic.buffer().content)).toMatch(/<ul>\s*<li><\/li>\s*<li><\/li>\s*<\/ul>/);

    await page.evaluate(() => window.cic.open('/app/Messy.php'));
    await page.locator('.monaco-editor .view-lines').click();
    await page.keyboard.press('Shift+Alt+F');
    await expect.poll(() => page.evaluate(() => window.cic.buffer().content), { timeout: 20_000 }).toContain('$a = [1, 2, 3];');
  });

  await test.step('the tab bar has VS Code\'s menu', async () => {
    await page.locator('.tab', { hasText: 'Messy.php' }).click({ button: 'right' });
    const labels = await page.locator('#nodeMenu [role="menuitem"] > span:first-child').allTextContents();
    expect(labels.slice(0, 5)).toEqual(['Close', 'Close Others', 'Close to the Right', 'Close Saved', 'Close All']);
    await page.keyboard.press('Escape');
  });

  await test.step('compact folders: a line of single folders is one row, as in VS Code', async () => {
    expect((await page.evaluate(() => window.cic.write('/src/main/java/App.java', 'class App {}', { expect: 'absent' }))).ok).toBe(true);
    await page.getByRole('button', { name: 'Collapse Folders in Explorer' }).click();
    const row = page.locator('#tree .node.compact', { hasText: 'src/main/java' });
    await expect(row).toBeVisible();
    await expect(row).toHaveAttribute('data-path', '/src/main/java');
    await expect(node('/src')).toHaveCount(0); // no separate row for the folders inside the chain
    await row.click();
    await expect(node('/src/main/java/App.java')).toBeVisible();
  });

  await test.step('Tailwind classes complete inside class="" on a site that uses Tailwind', async () => {
    expect((await page.evaluate(() => window.cic.write('/resources/views/tw.blade.php', '<div class=""></div>\n'))).ok).toBe(true);
    await page.evaluate(() => window.cic.open('/resources/views/tw.blade.php'));
    await page.locator('.monaco-editor .view-line span', { hasText: 'class' }).first().click();
    await page.keyboard.press('Home');
    for (let i = 0; i < 12; i++) await page.keyboard.press('ArrowRight'); // inside the quotes
    await page.keyboard.type('md:bg-teal-5');
    await expect(page.locator('.suggest-widget .monaco-list-row', { hasText: 'md:bg-teal-500' }).first()).toBeVisible({ timeout: 20_000 });
    await page.keyboard.press('Escape');
  });

  await test.step('Replace All changes every file that has the text, as one version', async () => {
    expect((await page.evaluate(() => window.cic.writeMany({
      '/app/ReplA.php': '<?php // OLDNAME here',
      '/app/ReplB.php': '<?php // oldname and OLDNAME',
    }))).ok).toBe(true);
    await page.getByRole('button', { name: 'Search in files' }).click();
    await page.locator('#searchInput').fill('OLDNAME');
    await page.locator('#replaceInput').fill('NewName');
    await page.getByRole('button', { name: 'Replace All' }).click();
    await expect(page.locator('#modalText')).toContainText('Replace 3 occurrences of "OLDNAME" across 2 files');
    await page.locator('#modalOk').click();
    await expect.poll(async () => (await page.evaluate(() => window.cic.readMany(['/app/ReplA.php', '/app/ReplB.php']))).files)
      .toEqual({ '/app/ReplA.php': '<?php // NewName here', '/app/ReplB.php': '<?php // NewName and NewName' });
    const versions = await page.evaluate(() => window.cic.history('/app/ReplB.php'));
    expect(versions.versions[0].message).toContain('replace "OLDNAME" with "NewName"');
  });
});
