import 'package:flutter/material.dart';

import 'tokens.g.dart';

/// The colours a screen may use, resolved for the current brightness.
///
/// Only pairs listed in docs/ux/contrast-report.md are approved: for example
/// [onHeader] and [action] on [header], [onAction] on [action], and
/// [text] / [textMuted] on [canvas] or [card], and [onBrand] on [brand] for
/// filled buttons ([brand] against [canvas] is the 3:1 control boundary).
class RiderPalette {
  const RiderPalette._({
    required this.header,
    required this.onHeader,
    required this.onHeaderMuted,
    required this.action,
    required this.onAction,
    required this.canvas,
    required this.card,
    required this.text,
    required this.textMuted,
    required this.outline,
    required this.brand,
    required this.onBrand,
    required this.tint,
  });

  final Color header;
  final Color onHeader;
  final Color onHeaderMuted;
  final Color action;
  final Color onAction;
  final Color canvas;
  final Color card;
  final Color text;
  final Color textMuted;
  final Color outline;
  final Color brand;
  final Color onBrand;

  /// `brand-tint`: fill behind the selected navigation item, with [text].
  final Color tint;

  static const light = RiderPalette._(
    header: LightTokens.nav,
    onHeader: LightTokens.onNav,
    onHeaderMuted: LightTokens.onNavMuted,
    action: LightTokens.actionBright,
    onAction: LightTokens.onActionBright,
    canvas: LightTokens.surface2,
    card: LightTokens.surface,
    text: LightTokens.textPrimary,
    textMuted: LightTokens.textSecondary,
    outline: LightTokens.borderStrong,
    brand: LightTokens.brand600,
    onBrand: LightTokens.onBrand,
    tint: LightTokens.brandTint,
  );

  static const dark = RiderPalette._(
    header: DarkTokens.nav,
    onHeader: DarkTokens.onNav,
    onHeaderMuted: DarkTokens.onNavMuted,
    action: DarkTokens.actionBright,
    onAction: DarkTokens.onActionBright,
    canvas: DarkTokens.surface,
    card: DarkTokens.surface2,
    text: DarkTokens.textPrimary,
    textMuted: DarkTokens.textSecondary,
    outline: DarkTokens.borderStrong,
    brand: DarkTokens.brand600,
    onBrand: DarkTokens.onBrand,
    tint: DarkTokens.brandTint,
  );

  static RiderPalette of(BuildContext context) =>
      Theme.of(context).brightness == Brightness.dark ? dark : light;
}
