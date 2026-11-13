import type { Metadata } from "next";
import { ConsoleShell } from "@/components/console-shell";
import { SystemStatus } from "./system-status";

export const metadata: Metadata = { title: "Settings" };

export default function SettingsPage() {
  return (
    <ConsoleShell current="Settings" title="Settings">
      <SystemStatus />
    </ConsoleShell>
  );
}
