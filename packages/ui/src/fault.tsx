"use client";

/**
 * Throws while rendering. It exists only so browser tests can prove the error
 * screens; the routes that use it return "not found" in a production build.
 * The message holds a marker that the tests assert is never shown.
 */
export function Fault({ message }: { message: string }): never {
  throw new Error(message);
}
