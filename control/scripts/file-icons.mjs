// Copies Material Icon Theme (MIT) into public/file-icons: every SVG, its
// licence, and a trimmed manifest the editor looks names up in. The browser
// only ever downloads the icons actually shown in a tree; the rest just sit
// on disk. Run by `npm run build` (package.json).
import { cpSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const pkgDir = dirname(require.resolve('material-icon-theme/package.json'));
const out = join(process.cwd(), 'public/file-icons');

const m = JSON.parse(readFileSync(join(pkgDir, 'dist/material-icons.json'), 'utf8'));
const pick = (o) => Object.fromEntries(['fileExtensions', 'fileNames', 'folderNames', 'folderNamesExpanded'].map((k) => [k, o?.[k] ?? {}]));
const manifest = {
    version: JSON.parse(readFileSync(join(pkgDir, 'package.json'), 'utf8')).version,
    file: m.file, folder: m.folder, folderExpanded: m.folderExpanded,
    ...pick(m),
    // Icons with a lighter variant for light backgrounds.
    light: pick(m.light),
    // Which icon names exist, so a lookup never points at a missing file.
    icons: Object.keys(m.iconDefinitions),
};

rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });
cpSync(join(pkgDir, 'icons'), out, { recursive: true });
cpSync(join(pkgDir, 'LICENSE'), join(out, 'LICENSE'));
writeFileSync(join(out, 'manifest.json'), JSON.stringify(manifest));
console.log(`file icons: ${manifest.icons.length} from material-icon-theme ${manifest.version} (MIT)`);
