export interface HeaderRule {
  source: string;
  headers: { key: string; value: string }[];
}

export function securityHeaders(options?: { geolocation?: boolean; baselineCsp?: boolean; staticFloorCsp?: boolean }): HeaderRule[];

export function apiOriginFrom(env?: Record<string, string | undefined>): string;
export function createNonce(): string;
export function tileOriginFrom(env?: Record<string, string | undefined>): string | null;
export function contentSecurityPolicy(options: { nonce: string; apiOrigin: string; tileOrigin?: string | null; dev?: boolean }): string;
export const NONCE_HEADER: "x-nonce";
export function nonceCsp(options: { env: Record<string, string | undefined>; dev?: boolean }): { nonce: string; policy: string };
export const PROXY_SKIPPED_SOURCES: string[];
export const PROXY_MATCHER: string;
