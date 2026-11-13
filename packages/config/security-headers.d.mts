export interface HeaderRule {
  source: string;
  headers: { key: string; value: string }[];
}

export function securityHeaders(options?: { geolocation?: boolean }): HeaderRule[];
