// Builds dist/ from src/: main + preload (CommonJS for Electron), the screen bundle, HTML and CSS.
import { build } from 'esbuild';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const dist = path.join(root, 'dist');
fs.rmSync(dist, { recursive: true, force: true });

const common = { bundle: true, sourcemap: false, minify: false, legalComments: 'none', logLevel: 'warning', target: 'es2022' };
await build({ ...common, entryPoints: [path.join(root, 'src/main/main.ts')], outfile: path.join(dist, 'main/main.js'), platform: 'node', format: 'cjs', external: ['electron'] });
await build({ ...common, entryPoints: [path.join(root, 'src/preload/preload.ts')], outfile: path.join(dist, 'preload/preload.js'), platform: 'node', format: 'cjs', external: ['electron'] });
await build({ ...common, entryPoints: [path.join(root, 'src/renderer/app.ts')], outfile: path.join(dist, 'renderer/app.js'), platform: 'browser', format: 'iife' });
// Pure modules for the unit tests.
for (const m of ['cart', 'validate', 'receipt']) {
  await build({ ...common, entryPoints: [path.join(root, `src/shared/${m}.ts`)], outfile: path.join(dist, `shared/${m}.cjs`), platform: 'node', format: 'cjs' });
}
const icons = fs.readFileSync(path.join(root, 'src/renderer/icons.svg.txt'), 'utf8');
fs.writeFileSync(path.join(dist, 'renderer/index.html'), fs.readFileSync(path.join(root, 'src/renderer/index.html'), 'utf8').replace('{{ICONS}}', icons));
fs.copyFileSync(path.join(root, 'src/renderer/styles.css'), path.join(dist, 'renderer/styles.css'));
console.log('Built dist/');
