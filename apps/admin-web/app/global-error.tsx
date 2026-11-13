"use client";

import { ErrorScreen } from "@food-delivery/ui/problem-page";

import "./globals.css";

// Replaces the root layout when the layout itself fails, so it brings its own
// document and styles (backlog U-03).
export default function GlobalError({ error, retry }: { error: unknown; retry: () => void }) {
  return (
    <html lang="en" className="h-full antialiased">
      <head>
        <title>Something went wrong</title>
      </head>
      <body className="flex min-h-full flex-col font-sans">
        <ErrorScreen error={error} retry={retry} />
      </body>
    </html>
  );
}
