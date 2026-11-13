export interface HeaderRule {
  source: string;
  headers: { key: string; value: string }[];
}

export function securityHeaders(options?: { geolocation?: boolean }): HeaderRule[];

export function apiOriginFrom(env?: Record<string, string | undefined>): string;
export function createNonce(): string;
export function contentSecurityPolicy(options: { nonce: string; apiOrigin: string; dev?: boolean }): string;
