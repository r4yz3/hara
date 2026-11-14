// Authenticated calls to the rest of the API, for screens behind sign-in.
// Hand-written request helpers; the response and request shapes come from
// @food-delivery/api-client at the call site.

import { call, csrfToken } from "./client";

/** What went wrong, in terms a screen can act on. */
export type ApiFailure =
  | { kind: "signed_out" }
  | { kind: "forbidden"; message: string }
  | { kind: "not_found" }
  | { kind: "conflict"; message: string }
  | { kind: "validation"; fields: Record<string, string[]> }
  | { kind: "rate_limited"; retryAfterSeconds?: number }
  | { kind: "unavailable" }
  | { kind: "network" };

export type ApiResult<T> = { ok: true; value: T } | { ok: false; failure: ApiFailure };

type ErrorBody = { error?: { message?: string; fields?: Record<string, string[]> } };

async function failureOf(response: Response): Promise<ApiFailure> {
  const body = (await response.json().catch(() => ({}))) as ErrorBody;
  const message = body.error?.message ?? "";

  switch (response.status) {
    case 401:
      return { kind: "signed_out" };
    case 403:
      return { kind: "forbidden", message: message || "You are not allowed to do that." };
    case 404:
      return { kind: "not_found" };
    case 409:
      return { kind: "conflict", message: message || "This changed since you loaded it. Reload and try again." };
    case 422:
      return { kind: "validation", fields: body.error?.fields ?? {} };
    case 429: {
      const retry = Number(response.headers.get("Retry-After"));
      return Number.isFinite(retry) && retry > 0 ? { kind: "rate_limited", retryAfterSeconds: Math.ceil(retry) } : { kind: "rate_limited" };
    }
    default:
      return { kind: "unavailable" };
  }
}

async function finish<T>(response: Response): Promise<ApiResult<T>> {
  if (!response.ok) return { ok: false, failure: await failureOf(response) };
  if (response.status === 204) return { ok: true, value: undefined as T };

  return { ok: true, value: (await response.json()) as T };
}

/** GET a JSON document. `T` is the whole response body, envelope included. */
export async function apiGet<T>(path: string): Promise<ApiResult<T>> {
  try {
    return await finish<T>(await call(path));
  } catch {
    return { ok: false, failure: { kind: "network" } };
  }
}

/** POST, PATCH or DELETE with the CSRF token for this app's session. */
export async function apiSend<T>(method: "POST" | "PATCH" | "DELETE", path: string, body?: unknown): Promise<ApiResult<T>> {
  try {
    const token = await csrfToken();
    if (token === null) return { ok: false, failure: { kind: "unavailable" } };

    return await finish<T>(
      await call(path, {
        method,
        headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": token },
        body: body === undefined ? undefined : JSON.stringify(body),
      }),
    );
  } catch {
    return { ok: false, failure: { kind: "network" } };
  }
}

/** One sentence a person can act on. Field errors are shown next to fields instead. */
export function describeFailure(failure: ApiFailure): string {
  switch (failure.kind) {
    case "signed_out":
      return "You are signed out. Sign in and try again.";
    case "forbidden":
      return failure.message;
    case "not_found":
      return "That does not exist, or you do not have access to it.";
    case "conflict":
      return failure.message;
    case "validation":
      return "Some fields need attention.";
    case "rate_limited":
      return "Too many requests. Wait a moment and try again.";
    case "network":
      return "We could not reach the server. Check your connection and try again.";
    case "unavailable":
      return "The server could not complete that. Try again shortly.";
  }
}
