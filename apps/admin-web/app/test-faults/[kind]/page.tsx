import { notFound } from "next/navigation";
import { connection } from "next/server";

import { Fault } from "@food-delivery/ui/fault";

// Test-only: throws on purpose so browser tests can prove the error screens
// (tests/browser/error-states.spec.ts). It does not exist in a production build.
export default async function TestFault({ params }: PageProps<"/test-faults/[kind]">) {
  if (process.env.NODE_ENV === "production") notFound();
  await connection();

  const { kind } = await params;
  if (kind === "client") return <Fault message="CLIENT-SECRET-DETAIL stack trace" />;
  if (kind === "server") throw new Error("SERVER-SECRET-DETAIL stack trace");
  notFound();
}
