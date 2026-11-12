import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Customer app",
  description: "Customer app. Phase 1 scaffold; not a live service.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className="h-full antialiased">
      <body className="min-h-full flex flex-col font-sans">{children}</body>
    </html>
  );
}
