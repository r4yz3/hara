"use client";

/**
 * Throws while rendering. It exists only so browser tests can prove the error
 * screens; the routes that use it return "not found" in a production build.
 * The message holds a marker that the tests assert is never shown.
 */
export function Fault({ message }: { message: string }): never {
  throw new Error(message);
}

/**
 * Makes the root layout fail in the browser, so the browser tests can reach `global-error.tsx`.
 * `global-error.tsx` cannot be reached in `next dev` (the development overlay takes over), so the tests use a
 * production build made with NEXT_PUBLIC_TEST_FAULTS=1 (playwright.config.ts). That variable is inlined at build
 * time: in a normal build the condition is the constant false and this renders nothing.
 * It fires only at /test-faults/layout and only in the browser (it reads `window`), so server rendering and every
 * other address are untouched.
 */
export function LayoutFault() {
  if (
    process.env.NEXT_PUBLIC_TEST_FAULTS === "1" &&
    typeof window !== "undefined" &&
    window.location.pathname === "/test-faults/layout"
  ) {
    throw new Error("LAYOUT-SECRET-DETAIL stack trace");
  }
  return null;
}
