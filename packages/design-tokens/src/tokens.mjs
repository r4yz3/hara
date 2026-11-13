// Single source of truth for colour tokens. tokens.css and the contrast
// report are generated from this file: run `pnpm --filter @food-delivery/design-tokens build`.
//
// The first ten tokens per theme are the starting palette from the Phase 1
// specification (section 8.1), unchanged. Measuring it showed three gaps, so
// four tokens were ADDED (marked below). Nothing from the spec was altered.

export const tokens = {
  light: {
    "brand-600": "#0F766E",
    "brand-700": "#115E59",
    "accent-500": "#F97316",
    surface: "#FFFFFF",
    "surface-2": "#F8FAFC",
    "text-primary": "#0F172A",
    "text-secondary": "#475569",
    border: "#E2E8F0",
    danger: "#B91C1C",
    success: "#15803D",
    // Added:
    "on-brand": "#FFFFFF", // text on brand-600 / brand-700 fills
    "on-danger": "#FFFFFF", // text on danger fills
    "accent-text": "#C2410C", // accent-500 is 2.8:1 on white, too low for text
    "border-strong": "#7C8BA1", // border is 1.2:1, too low for control outlines
    // Added for the customer app's warm look (docs/ux/design-direction.md):
    "warm-50": "#FFF7ED", // page wash
    "warm-100": "#FFEDD5", // tinted cards and chips
    cta: "#C2410C", // filled call-to-action; accent-500 is too light for white labels
    "on-cta": "#FFFFFF",
    // Added for the admin console's dark navigation rail:
    nav: "#0F172A",
    "on-nav": "#E2E8F0",
    "on-nav-muted": "#A3B1C6",
    // Added for the merchant portal's attention colour (new orders, alerts):
    highlight: "#FACC15",
    "on-highlight": "#0F172A",
    "brand-tint": "#CCFBF1",
    // Added for the rider app's single bright action on its dark header:
    "action-bright": "#BEF264",
    "on-action-bright": "#0F172A",
  },
  dark: {
    "brand-600": "#2DD4BF",
    "brand-700": "#5EEAD4",
    "accent-500": "#FB923C",
    surface: "#111827",
    "surface-2": "#1F2937",
    "text-primary": "#F9FAFB",
    "text-secondary": "#CBD5E1",
    border: "#374151",
    danger: "#F87171",
    success: "#4ADE80",
    // Added:
    "on-brand": "#0F172A", // white on the light teal is 1.9:1
    "on-danger": "#0F172A",
    "accent-text": "#FB923C",
    "border-strong": "#748094",
    "warm-50": "#1C1410",
    "warm-100": "#2B1D14",
    cta: "#FB923C",
    "on-cta": "#0F172A",
    nav: "#0B1220",
    "on-nav": "#E2E8F0",
    "on-nav-muted": "#A3B1C6",
    // Added for the merchant portal's attention colour (new orders, alerts):
    highlight: "#FACC15",
    "on-highlight": "#0F172A",
    "brand-tint": "#134E4A",
    "action-bright": "#BEF264",
    "on-action-bright": "#0F172A",
  },
};

const TEXT = 4.5; // WCAG 2.2 AA, normal-size text (1.4.3)
const NON_TEXT = 3; // WCAG 2.2 AA, UI component boundaries and focus rings (1.4.11)

/**
 * Every foreground/background pair the apps are allowed to use.
 * A pair that is not listed here has not been measured and must not be used.
 */
export const pairs = [
  ...["surface", "surface-2"].flatMap((background) => [
    { foreground: "text-primary", background, min: TEXT, use: "body text" },
    { foreground: "text-secondary", background, min: TEXT, use: "secondary text" },
    { foreground: "brand-600", background, min: TEXT, use: "links, active labels" },
    { foreground: "brand-700", background, min: TEXT, use: "link hover" },
    { foreground: "accent-text", background, min: TEXT, use: "accent-coloured text" },
    { foreground: "danger", background, min: TEXT, use: "error text" },
    { foreground: "success", background, min: TEXT, use: "success text" },
    { foreground: "border-strong", background, min: NON_TEXT, use: "input and control outlines" },
    { foreground: "brand-600", background, min: NON_TEXT, use: "focus ring" },
  ]),
  { foreground: "on-brand", background: "brand-600", min: TEXT, use: "primary button label" },
  { foreground: "on-brand", background: "brand-700", min: TEXT, use: "primary button label, hover" },
  { foreground: "on-danger", background: "danger", min: TEXT, use: "destructive button label" },
  { foreground: "on-cta", background: "cta", min: TEXT, use: "call-to-action label" },
  { foreground: "on-nav", background: "nav", min: TEXT, use: "navigation label" },
  { foreground: "on-nav-muted", background: "nav", min: TEXT, use: "unavailable navigation label" },
  { foreground: "on-highlight", background: "highlight", min: TEXT, use: "label on attention fill" },
  { foreground: "on-action-bright", background: "action-bright", min: TEXT, use: "label on the rider's bright action" },
  { foreground: "action-bright", background: "nav", min: TEXT, use: "bright text and controls on the dark header" },
  { foreground: "text-primary", background: "brand-tint", min: TEXT, use: "text on tinted brand fill" },
  { foreground: "text-secondary", background: "brand-tint", min: TEXT, use: "secondary text on tinted brand fill" },
  ...["warm-50", "warm-100"].flatMap((background) => [
    { foreground: "text-primary", background, min: TEXT, use: "text on warm surfaces" },
    { foreground: "text-secondary", background, min: TEXT, use: "secondary text on warm surfaces" },
    { foreground: "accent-text", background, min: TEXT, use: "accent text on warm surfaces" },
    { foreground: "border-strong", background, min: NON_TEXT, use: "control outlines on warm surfaces" },
    { foreground: "cta", background, min: NON_TEXT, use: "filled control against warm surfaces" },
  ]),
];

/**
 * Tokens that fail a threshold on purpose and are therefore restricted.
 */
export const restricted = [
  { token: "accent-500", rule: "Decorative fills and illustrations only. Never text, icons that carry meaning, or control outlines." },
  { token: "border", rule: "Decorative separators only. Use border-strong where the line is the only thing marking a control." },
];
