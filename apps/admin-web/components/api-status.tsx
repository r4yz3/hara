import { connection } from "next/server";

const apiUrl = process.env.API_URL ?? "http://localhost:8000";

type Result = "up" | "down";

/**
 * Asks the API's public liveness route, from the server, on every request.
 * This is the only live value on the page: it is measured, never assumed.
 */
async function checkApi(): Promise<Result> {
  try {
    const response = await fetch(`${apiUrl}/health/live`, {
      cache: "no-store",
      signal: AbortSignal.timeout(2500),
    });

    return response.ok ? "up" : "down";
  } catch {
    return "down";
  }
}

export async function ApiStatus() {
  await connection();
  const result = await checkApi();

  return result === "up" ? (
    <StatusRow tone="ok" title="API is reachable" detail="The liveness check answered just now." />
  ) : (
    <StatusRow
      tone="problem"
      title="API is not reachable"
      detail="The liveness check did not answer. Start the stack, then reload this page."
    />
  );
}

export function StatusRow({
  tone,
  title,
  detail,
}: {
  tone: "ok" | "problem" | "waiting";
  title: string;
  detail: string;
}) {
  // Status is carried by the word and the symbol, never by colour alone.
  const mark = { ok: "✓", problem: "!", waiting: "…" }[tone];
  const word = { ok: "Ready", problem: "Problem", waiting: "Not built yet" }[tone];
  const colour = { ok: "text-success", problem: "text-danger", waiting: "text-text-secondary" }[tone];

  return (
    <li className="flex items-start gap-3 rounded-2xl border border-border bg-surface p-4">
      <span
        aria-hidden="true"
        className={`grid size-8 shrink-0 place-items-center rounded-full border border-border-strong font-bold ${colour}`}
      >
        {mark}
      </span>
      <div className="flex min-w-0 flex-col gap-0.5">
        <p className="font-medium">{title}</p>
        <p className="text-sm text-text-secondary">{detail}</p>
        <p className={`text-sm font-semibold ${colour}`}>{word}</p>
      </div>
    </li>
  );
}
