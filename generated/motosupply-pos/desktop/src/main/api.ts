// HTTPS client for the MotoSupply server API (api.php). Runs only in the main process: the
// sign-in token never reaches the screen (renderer) and is kept in memory only.

import { net } from 'electron';

export interface ApiError { code: string; message: string }
export type ApiResult<T = Record<string, unknown>> = ({ ok: true; status: number } & T) | { ok: false; status: number; error: ApiError; [k: string]: unknown };

export class ApiClient {
  private token: string | null = null;
  onSessionLost: (code: string) => void = () => {};

  constructor(public readonly base: string, private readonly userAgent: string) {}

  setToken(t: string | null): void { this.token = t; }
  hasToken(): boolean { return this.token !== null; }

  async request<T = Record<string, unknown>>(method: 'GET' | 'POST', route: string,
    opts: { query?: Record<string, string>; body?: unknown; auth?: boolean; timeoutMs?: number } = {}): Promise<ApiResult<T>> {
    const qs = new URLSearchParams({ r: route, ...(opts.query ?? {}) });
    const headers: Record<string, string> = { Accept: 'application/json', 'User-Agent': this.userAgent };
    if (opts.auth !== false) {
      if (!this.token) return { ok: false, status: 401, error: { code: 'unauthenticated', message: 'Please sign in.' } };
      headers.Authorization = `Bearer ${this.token}`;
      headers['X-MotoSupply-Token'] = this.token; // for hosts that drop Authorization
    }
    let body: string | undefined;
    if (opts.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(opts.body);
    }
    let res: Response;
    try {
      res = await net.fetch(`${this.base}/api.php?${qs.toString()}`, {
        method, headers, body,
        redirect: 'error', // never follow a redirect to another address
        cache: 'no-store',
        credentials: 'omit',
        signal: AbortSignal.timeout(opts.timeoutMs ?? 15000),
      });
    } catch (e) {
      const timeout = e instanceof Error && (e.name === 'TimeoutError' || e.name === 'AbortError');
      return { ok: false, status: 0, error: { code: timeout ? 'timeout' : 'network',
        message: timeout ? 'The server did not answer in time.' : 'Cannot reach the MotoSupply server. Check the internet connection.' } };
    }
    let data: Record<string, unknown> | null = null;
    try {
      const text = await res.text();
      data = text.length < 5_000_000 ? JSON.parse(text) : null;
    } catch {
      data = null;
    }
    if (!data || typeof data !== 'object') {
      return { ok: false, status: res.status, error: { code: res.status >= 500 ? 'server_unavailable' : 'bad_response',
        message: res.status >= 500 ? 'The MotoSupply server is not available right now.' : 'The server sent an unexpected answer. Is this the right address?' } };
    }
    if (data.ok === true) return { ...(data as T), ok: true, status: res.status } as ApiResult<T>;
    const err = (data.error && typeof data.error === 'object' ? data.error : { code: 'error', message: String(data.error ?? 'Request failed.') }) as ApiError;
    if (res.status === 401 && opts.auth !== false && ['session_expired', 'unauthenticated', 'account_disabled'].includes(err.code)) {
      this.token = null;
      this.onSessionLost(err.code);
    }
    if (res.status === 403 && err.code === 'forbidden' && /no longer allowed/.test(err.message)) {
      this.token = null;
      this.onSessionLost('forbidden');
    }
    return { ...data, ok: false, status: res.status, error: { code: String(err.code), message: String(err.message) } } as ApiResult<T>;
  }
}
