import type { Metadata } from "next";

import { ConsoleShell } from "@/components/console-shell";

import { CitiesScreen } from "./cities-screen";

export const metadata: Metadata = { title: "Cities" };

export default function CitiesPage() {
  return (
    <ConsoleShell current="Cities" title="Cities">
      <CitiesScreen />
    </ConsoleShell>
  );
}
