// Talks to the API's session endpoints (backend/openapi/openapi.yaml).
// Cookies are sent with every call; the API decides which app's session
// applies from the browser's Origin header, so nothing here names a surface.

import type { operations, Schemas } from "@food-delivery/api-client";

const apiUrl = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000").replace(/\/$/, "");

export type CurrentUser = Schemas["CurrentUser"];
export type Grant = CurrentUser["grants"][number];

/** What went wrong, in terms a screen can act on. */
export type Failure =
  | { kind: "credentials" }
  | { kind: "validation"; fields: Record<string, string[]> }
  | { kind: "rate_limited"; retryAfterSeconds: number | null }
  | { kind: "forbidden"; message: string }
  | { kind: "unavailable" }
  | { kind: "network" };

export type Result<T> = { ok: true; value: T } | { ok: false; failure: Failure };

type ErrorBody = { error?: Partial<Schemas["ErrorResponse"]["error"]> };

export async function call(path: string, init: RequestInit = {}): Promise<Response> {
  return fetch(`${apiUrl}${path}`, {
    ...init,
    credentials: "include",
    headers: { Accept: "application/json", ...init.headers },
  });
}

export async function csrfToken(): Promise<string | null> {
  const response = await call("/api/v1/auth/csrf");
  if (!response.ok) return null;

  const body = (await response.json()) as Partial<operations["authCsrf"]["responses"][200]["content"]["application/json"]>;
  return body.data?.csrf_token ?? null;
}

async function toFailure(response: Response): Promise<Failure> {
  const body = (await response.json().catch(() => ({}))) as ErrorBody;

  switch (response.status) {
    case 401:
      return { kind: "credentials" };
    case 422:
      return { kind: "validation", fields: body.error?.fields ?? {} };
    case 429: {
      const retry = Number(response.headers.get("Retry-After"));
      return { kind: "rate_limited", retryAfterSeconds: Number.isFinite(retry) && retry > 0 ? retry : null };
    }
    case 403:
      return { kind: "forbidden", message: body.error?.message ?? "You are not allowed to do that." };
    default:
      return { kind: "unavailable" };
  }
}

async function post(path: string, payload?: unknown): Promise<Response | Failure> {
  try {
    const token = await csrfToken();
    if (token === null) return { kind: "unavailable" };

    return await call(path, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": token },
      body: payload === undefined ? undefined : JSON.stringify(payload),
    });
  } catch {
    return { kind: "network" };
  }
}

export async function signIn(email: string, password: string): Promise<Result<CurrentUser>> {
  const payload: operations["authLogin"]["requestBody"]["content"]["application/json"] = { email, password };
  const response = await post("/api/v1/auth/login", payload);
  if (!(response instanceof Response)) return { ok: false, failure: response };
  if (!response.ok) return { ok: false, failure: await toFailure(response) };

  const body = (await response.json()) as Schemas["CurrentUserResponse"];
  return { ok: true, value: body.data };
}

export async function signOut(): Promise<Result<null>> {
  const response = await post("/api/v1/auth/logout");
  if (!(response instanceof Response)) return { ok: false, failure: response };
  // 401 means there was no session left to end, which is the desired state.
  if (!response.ok && response.status !== 401) return { ok: false, failure: await toFailure(response) };

  return { ok: true, value: null };
}

/** `null` value means nobody is signed in on this app. */
export async function currentUser(): Promise<Result<CurrentUser | null>> {
  try {
    const response = await call("/api/v1/me");
    if (response.status === 401) return { ok: true, value: null };
    if (!response.ok) return { ok: false, failure: await toFailure(response) };

    const body = (await response.json()) as Schemas["CurrentUserResponse"];
    return { ok: true, value: body.data };
  } catch {
    return { ok: false, failure: { kind: "network" } };
  }
}

/**
 * Where to go after signing in. Only a path on this same site is accepted,
 * so a crafted link cannot send someone to another site (threat T27).
 */
export function safeReturnPath(candidate: string | null | undefined, fallback = "/"): string {
  if (typeof candidate !== "string") return fallback;
  if (!candidate.startsWith("/") || candidate.startsWith("//") || candidate.startsWith("/\\")) return fallback;
  if (/[\u0000-\u001f\\]/.test(candidate)) return fallback;

  return candidate;
}
