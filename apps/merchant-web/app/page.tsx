const notAvailable = [
  "Sign-in for invited staff",
  "Branch selector",
  "Orders, menu, staff and settings",
];

export default function Home() {
  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 px-4 py-12">
      <p className="w-fit rounded-lg border border-border bg-surface-2 px-3 py-1 text-sm text-text-secondary">
        Phase 1 scaffold
      </p>
      <h1 className="text-3xl font-semibold">Merchant portal</h1>
      <p className="text-text-secondary">This is an empty shell. No merchant data is shown because none exists.</p>
      <section aria-labelledby="not-available" className="rounded-xl border border-border bg-surface-2 p-4">
        <h2 id="not-available" className="font-medium">
          Not built yet
        </h2>
        <ul className="mt-2 list-disc pl-5 text-text-secondary">
          {notAvailable.map((item) => (
            <li key={item}>{item}</li>
          ))}
        </ul>
      </section>
    </main>
  );
}
