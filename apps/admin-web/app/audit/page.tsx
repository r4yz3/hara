import type { Metadata } from "next";

import { ConsoleShell } from "@/components/console-shell";

import { AuditScreen } from "./audit-screen";

export const metadata: Metadata = { title: "Audit log" };

export default function AuditPage() {
  return (
    <ConsoleShell current="Audit log" title="Audit log">
      <AuditScreen />
    </ConsoleShell>
  );
}
