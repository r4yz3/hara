import type { Metadata } from "next";
import { LayoutFault } from "@food-delivery/ui/fault";
import { OfflineBanner } from "@food-delivery/ui/offline-banner";

import "./globals.css";

export const metadata: Metadata = {
  title: "Merchant portal",
  description: "Merchant portal. Phase 1 scaffold; not a live service.",
};

// Every page is rendered per request so the proxy's CSP nonce reaches its scripts (ADR-020).
export const dynamic = "force-dynamic";

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className="h-full antialiased">
      <body className="min-h-full flex flex-col font-sans">
        <LayoutFault />
        <OfflineBanner />
        {children}
      </body>
    </html>
  );
}
