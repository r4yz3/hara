import type { Metadata } from "next";
import { LayoutFault } from "@food-delivery/ui/fault";

import "./globals.css";

export const metadata: Metadata = {
  title: "Merchant portal",
  description: "Merchant portal. Phase 1 scaffold; not a live service.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className="h-full antialiased">
      <body className="min-h-full flex flex-col font-sans">
        <LayoutFault />
        {children}
      </body>
    </html>
  );
}
