import type { Metadata } from "next";

import { ConsoleShell } from "@/components/console-shell";

import { AccessScreen } from "./access-screen";

export const metadata: Metadata = { title: "Roles and access" };

export default function AccessPage() {
  return (
    <ConsoleShell current="Roles and access" title="Roles and access">
      <AccessScreen />
    </ConsoleShell>
  );
}
