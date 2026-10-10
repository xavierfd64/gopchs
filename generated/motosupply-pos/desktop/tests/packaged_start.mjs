// Start-up test of the PACKAGED app (asar, packaged mode), installed in a folder whose name contains a
// space, like the Windows default "…\Programs\MotoSupply POS". Catches a blank window at start-up.
//   npx electron-builder --linux dir --x64 --publish never -c.directories.output=<out>
//   xvfb-run node tests/packaged_start.mjs <out>/linux-unpacked [screenshot.png]
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const require = createRequire(import.meta.url);
const { _electron } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const [built, shot] = process.argv.slice(2);
if (!built || !fs.existsSync(built)) {
  console.error('Usage: node tests/packaged_start.mjs <linux-unpacked folder> [screenshot.png]');
  process.exit(2);
}
const within = (p, fallback, ms = 8000) => Promise.race([p.catch(() => fallback), new Promise((r) => setTimeout(() => r(fallback), ms))]);
const work = fs.mkdtempSync(path.join(os.tmpdir(), 'moto-start-'));
let failed = 0;
try {
  for (const name of ['MotoSupplyPOS', 'MotoSupply POS']) {
    const dir = path.join(work, name);
    fs.cpSync(built, dir, { recursive: true });
    let app;
    try {
      app = await _electron.launch({
        executablePath: path.join(dir, 'motosupply-pos-desktop'),
        args: ['--no-sandbox', `--user-data-dir=${path.join(work, 'ud-' + name.replace(' ', '_'))}`],
        timeout: 30000,
      });
    } catch {
      // A blocking "could not start" error box during start-up keeps Playwright from attaching.
      try { execFileSync('pkill', ['-9', '-f', dir]); } catch { /* already gone */ }
      failed++;
      console.log(`  FAIL  first screen shown when installed in "${name}" (app did not finish starting)`);
      continue;
    }
    const proc = app.process();
    const page = await app.firstWindow();
    await page.waitForSelector('#screen-setup:not([hidden])', { timeout: 15000 }).catch(() => {});
    // A failed start shows a blocking error box, so every step has a time limit.
    const r = await within(page.evaluate(() => ({
      url: location.href,
      screens: [...document.querySelectorAll('main.screen')].filter((m) => !m.hidden).map((m) => m.id),
    })), { url: 'no answer (error box shown?)', screens: [] });
    if (shot && name.includes(' ')) await within(page.screenshot({ path: shot }), null);
    await within(app.close(), null);
    proc.kill('SIGKILL');
    const ok = r.url.startsWith('file:') && r.screens.includes('screen-setup');
    if (!ok) failed++;
    console.log(`  ${ok ? 'PASS' : 'FAIL'}  first screen shown when installed in "${name}" (${r.url}; ${r.screens.join(',') || 'nothing'})`);
  }
} finally {
  fs.rmSync(work, { recursive: true, force: true });
}
console.log(failed ? `${failed} failed` : 'all passed');
process.exit(failed ? 1 : 0);
