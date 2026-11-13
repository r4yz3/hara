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

// ---- Staff two-step sign-in (admin app only) ----

/** The 403 the API gives a staff session that must enrol before anything else. */
export const ENROLMENT_ONLY_MESSAGE = "Set up two-step sign-in before using the admin console.";

export function isEnrolmentOnly(failure: { kind: string; message?: string }): boolean {
  return failure.kind === "forbidden" && failure.message === ENROLMENT_ONLY_MESSAGE;
}

/** A failure plus the 409 the enrolment routes can answer. */
export type MfaFailure = Failure | { kind: "conflict"; message: string };
export type MfaResult<T> = { ok: true; value: T } | { ok: false; failure: MfaFailure };

export type PasswordStep = { step: "signed_in"; user: CurrentUser } | { step: "code_required" };

async function toMfaFailure(response: Response): Promise<MfaFailure> {
  if (response.status === 409) {
    const body = (await response.clone().json().catch(() => ({}))) as ErrorBody;
    return { kind: "conflict", message: body.error?.message ?? "Two-step sign-in is already set up." };
  }

  return toFailure(response);
}

async function mfaPost<T>(path: string, payload: unknown, read: (body: unknown) => T): Promise<MfaResult<T>> {
  const response = await post(path, payload);
  if (!(response instanceof Response)) return { ok: false, failure: response };
  if (!response.ok) return { ok: false, failure: await toMfaFailure(response) };

  return { ok: true, value: read(await response.json()) };
}

/** Password step for an app that has a second step: the API may ask for a code. */
export async function signInFirstStep(email: string, password: string): Promise<Result<PasswordStep>> {
  const payload: operations["authLogin"]["requestBody"]["content"]["application/json"] = { email, password };
  const response = await post("/api/v1/auth/login", payload);
  if (!(response instanceof Response)) return { ok: false, failure: response };
  if (!response.ok) return { ok: false, failure: await toFailure(response) };

  const body = (await response.json()) as Schemas["CurrentUserResponse"] | Schemas["MfaRequiredResponse"];
  if ("mfa_required" in body.data) return { ok: true, value: { step: "code_required" } };

  return { ok: true, value: { step: "signed_in", user: body.data } };
}

/** Second step: an authenticator code or one recovery code. */
export async function verifySecondStep(input: { code: string } | { recovery_code: string }): Promise<Result<CurrentUser>> {
  const payload: operations["authMfaVerify"]["requestBody"]["content"]["application/json"] = input;
  const result = await mfaPost("/api/v1/auth/mfa/verify", payload, (body) => (body as Schemas["CurrentUserResponse"]).data);
  if (result.ok || result.failure.kind !== "conflict") return result as Result<CurrentUser>;

  return { ok: false, failure: { kind: "unavailable" } };
}

export type TotpEnrolment = { secret: string; otpauthUri: string };

export function startTotpEnrolment(): Promise<MfaResult<TotpEnrolment>> {
  return mfaPost("/api/v1/me/mfa/totp", undefined, (body) => {
    const data = (body as operations["meMfaTotpStart"]["responses"][201]["content"]["application/json"]).data;
    return { secret: data.secret, otpauthUri: data.otpauth_uri };
  });
}

export function confirmTotpEnrolment(code: string): Promise<MfaResult<string[]>> {
  const payload: operations["meMfaTotpConfirm"]["requestBody"]["content"]["application/json"] = { code };
  return mfaPost(
    "/api/v1/me/mfa/totp/confirm",
    payload,
    (body) => (body as operations["meMfaTotpConfirm"]["responses"][200]["content"]["application/json"]).data.recovery_codes,
  );
}
