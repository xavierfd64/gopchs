// The only bridge between the screen and the app. Each function maps to one validated handler in
// main.ts; no Node.js, file system, shell or network access is exposed to the screen.

import { contextBridge, ipcRenderer, type IpcRendererEvent } from 'electron';

const call = (channel: string, ...args: unknown[]): Promise<unknown> => ipcRenderer.invoke(channel, ...args);

const api = {
  init: () => call('moto:init'),
  branding: () => call('moto:branding'),
  checkServer: (url: string) => call('moto:check-server', url),
  authorizeServerChange: (username: string, password: string) => call('moto:authorize-server-change', username, password),
  login: (username: string, password: string) => call('moto:login', username, password),
  logout: () => call('moto:logout'),
  search: (q: string, category: number) => call('moto:search', q, category),
  lookup: (code: string) => call('moto:lookup', code),
  stock: (ids: number[]) => call('moto:stock', ids),
  checkout: (input: unknown) => call('moto:checkout', input),
  discardPending: () => call('moto:discard-pending'),
  receipt: (id: number) => call('moto:receipt', id),
  recent: () => call('moto:recent'),
  print: (saleId: number, mode: 'auto' | 'manual') => call('moto:print', saleId, mode),
  reprint: (saleId: number) => call('moto:reprint', saleId),
  printers: () => call('moto:printers'),
  setPrinter: (name: string) => call('moto:set-printer', name),
  cartState: (hasItems: boolean) => call('moto:cart-state', hasItems),
  onStatus: (cb: (s: string) => void) => {
    const h = (_e: IpcRendererEvent, s: unknown) => cb(String(s));
    ipcRenderer.on('moto:status', h);
  },
  onSession: (cb: (code: string) => void) => {
    const h = (_e: IpcRendererEvent, s: unknown) => cb(String(s));
    ipcRenderer.on('moto:session', h);
  },
};

contextBridge.exposeInMainWorld('moto', api);
export type MotoBridge = typeof api;
