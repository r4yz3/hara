import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Admin and dispatch console",
  description: "Admin and dispatch console. Phase 1 scaffold; not a live service.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className="h-full antialiased">
      <body className="flex min-h-full flex-col font-sans">{children}</body>
    </html>
  );
}
