// Laravel /api/v1 istemcisi. Sanctum SPA: oturum HttpOnly cookie'de; durum değiştiren isteklerde
// XSRF-TOKEN cookie'sindeki değer X-XSRF-TOKEN başlığıyla gönderilir. Token JS'de saklanmaz.

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, string[]> = {},
  ) {
    super(message);
  }

  /** Alan hatası varsa ilkini, yoksa genel mesajı döner. */
  firstMessage(): string {
    const first = Object.values(this.errors)[0]?.[0];
    return first ?? this.message;
  }
}

export function readCookie(cookies: string, name: string): string | null {
  const match = cookies.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

let onUnauthorized: (() => void) | null = null;
export function setUnauthorizedHandler(handler: () => void) {
  onUnauthorized = handler;
}

export async function ensureCsrfCookie(force = false) {
  if (!force && readCookie(document.cookie, "XSRF-TOKEN")) return;
  await fetch("/sanctum/csrf-cookie", { credentials: "same-origin", headers: { Accept: "application/json" } });
}

type Options = { method?: "GET" | "POST" | "PATCH"; body?: unknown };

export async function api<T>(path: string, { method = "GET", body }: Options = {}, retry = true): Promise<T> {
  if (method !== "GET") await ensureCsrfCookie();

  const headers: Record<string, string> = { Accept: "application/json" };
  if (body !== undefined) headers["Content-Type"] = "application/json";
  const xsrf = readCookie(document.cookie, "XSRF-TOKEN");
  if (xsrf) headers["X-XSRF-TOKEN"] = xsrf;

  const res = await fetch(`/api/v1${path}`, {
    method,
    headers,
    credentials: "same-origin",
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  // 419: CSRF/oturum süresi doldu — cookie'yi yenileyip bir kez tekrar dene.
  if (res.status === 419 && retry) {
    await ensureCsrfCookie(true);
    return api<T>(path, { method, body }, false);
  }

  const data = await res.json().catch(() => null);

  if (!res.ok) {
    if (res.status === 401) onUnauthorized?.();
    const message =
      res.status === 403
        ? "Bu işlem için yetkiniz yok."
        : res.status === 429
          ? "Çok fazla deneme. Lütfen biraz bekleyin."
          : (data?.message ?? "İstek başarısız oldu.");
    throw new ApiError(res.status, message, data?.errors ?? {});
  }

  return data as T;
}
