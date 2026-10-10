// Server address and local preferences.
//
// Where the server address comes from (first match wins):
//   1. Machine configuration written by IT/the administrator (locked: cannot be changed in the app)
//        Windows: %ProgramData%\MotoSupply POS\config.json   {"server_url": "https://…"}
//   2. Per-user settings saved by the first-run setup screen (%APPDATA%\MotoSupply POS\settings.json).
//      Changing it later requires signing in with an account that may manage settings.
// No passwords, tokens or database credentials are ever stored on the computer.

import { app } from 'electron';
import fs from 'node:fs';
import path from 'node:path';
import { validateServerUrl } from '../shared/validate';

export interface MachineConfig { server_url?: string; allow_insecure_localhost?: boolean }
export interface UserSettings { server_url?: string; printer?: string; last_username?: string }

function machineConfigPath(): string {
  // Test/development override (never honoured by the installed app).
  if (!app.isPackaged && process.env.MOTOSUPPLY_MACHINE_CONFIG) return process.env.MOTOSUPPLY_MACHINE_CONFIG;
  if (process.platform === 'win32') {
    return path.join(process.env.ProgramData || 'C:\\ProgramData', 'MotoSupply POS', 'config.json');
  }
  return '/etc/motosupply-pos/config.json';
}

function userSettingsPath(): string {
  return path.join(app.getPath('userData'), 'settings.json');
}

function readJson<T>(file: string): T | null {
  try {
    const raw = fs.readFileSync(file, 'utf8');
    if (raw.length > 64 * 1024) return null;
    const v = JSON.parse(raw.replace(/^\uFEFF/, ''));
    return v && typeof v === 'object' && !Array.isArray(v) ? (v as T) : null;
  } catch {
    return null;
  }
}

export function readMachineConfig(): MachineConfig {
  return readJson<MachineConfig>(machineConfigPath()) ?? {};
}

export function insecureLocalhostAllowed(): boolean {
  if (readMachineConfig().allow_insecure_localhost === true) return true;
  return !app.isPackaged && process.env.MOTOSUPPLY_ALLOW_HTTP_LOCALHOST === '1';
}

export interface ServerSetting { url: string | null; locked: boolean; error?: string }

export function serverSetting(): ServerSetting {
  const m = readMachineConfig();
  const allow = insecureLocalhostAllowed();
  if (typeof m.server_url === 'string' && m.server_url !== '') {
    const v = validateServerUrl(m.server_url, allow);
    return v.ok ? { url: v.url!, locked: true } : { url: null, locked: true, error: `The server address in ${machineConfigPath()} is not valid: ${v.error}` };
  }
  const u = readUserSettings();
  if (typeof u.server_url === 'string' && u.server_url !== '') {
    const v = validateServerUrl(u.server_url, allow);
    if (v.ok) return { url: v.url!, locked: false };
  }
  return { url: null, locked: false };
}

export function readUserSettings(): UserSettings {
  return readJson<UserSettings>(userSettingsPath()) ?? {};
}

export function writeUserSettings(patch: Partial<UserSettings>): void {
  const next = { ...readUserSettings(), ...patch };
  const file = userSettingsPath();
  fs.mkdirSync(path.dirname(file), { recursive: true });
  const tmp = file + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(next, null, 2), { mode: 0o600 });
  fs.renameSync(tmp, file);
}

export function machineConfigLocation(): string {
  return machineConfigPath();
}
