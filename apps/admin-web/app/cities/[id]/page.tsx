import type { Metadata } from "next";
import { Suspense } from "react";

import { ConsoleShell } from "@/components/console-shell";
import { Panel } from "@/components/ui";

import { CityScreen } from "./city-screen";

export const metadata: Metadata = { title: "City" };

export default function CityPage() {
  return (
    <ConsoleShell current="Cities" title="City">
      {/* The id is read in the browser, so this part renders at request time. */}
      <Suspense
        fallback={
          <Panel>
            <p role="status" aria-busy="true" className="text-text-secondary">
              Loading city…
            </p>
          </Panel>
        }
      >
        <CityScreen />
      </Suspense>
    </ConsoleShell>
  );
}
