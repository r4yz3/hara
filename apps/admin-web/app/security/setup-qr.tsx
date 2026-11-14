"use client";

import qrcode from "qrcode-generator";
import { useMemo } from "react";

const QUIET_ZONE = 4; // modules; the QR specification asks for at least four.

/**
 * The setup link as a QR code, drawn as inline SVG from the module matrix.
 *
 * The otpauth URI holds the secret, so it is encoded here in the browser and
 * goes nowhere else: no request, URL, storage or console. No HTML string is
 * built or injected (no dangerouslySetInnerHTML); React creates the SVG nodes.
 * Colours are fixed black on white, in both themes, because phone cameras need
 * that contrast; they are attributes, not inline styles, so the CSP holds.
 * The only attribute derived from the code is the module count, not the secret.
 */
export function SetupQr({ uri }: { uri: string }) {
  const { count, path } = useMemo(() => {
    const qr = qrcode(0, "M");
    qr.addData(uri);
    qr.make();
    const size = qr.getModuleCount();
    let d = "";
    for (let row = 0; row < size; row++) {
      for (let col = 0; col < size; col++) {
        if (qr.isDark(row, col)) d += `M${col + QUIET_ZONE} ${row + QUIET_ZONE}h1v1h-1z`;
      }
    }
    return { count: size, path: d };
  }, [uri]);

  const total = count + QUIET_ZONE * 2;

  return (
    <svg
      role="img"
      aria-label="QR code for your authenticator app"
      data-modules={count}
      viewBox={`0 0 ${total} ${total}`}
      shapeRendering="crispEdges"
      className="size-full max-h-72 max-w-72 self-start rounded-xl border border-border"
    >
      <rect width={total} height={total} fill="#ffffff" />
      <path d={path} fill="#000000" />
    </svg>
  );
}
