import 'package:flutter/material.dart';

import '../core/api/rider_api.dart';
import '../core/palette.dart';
import '../core/tokens.g.dart';
import '../features/home/home_screen.dart';
import '../features/sign_in/sign_in_screen.dart';

/// Root widget. Colours come from the shared design tokens
/// (packages/design-tokens), the same source the web apps use.
class RiderApp extends StatefulWidget {
  /// [api] defaults to the real client for `--dart-define=API_BASE_URL`.
  RiderApp({super.key, RiderApi? api}) : api = api ?? HttpRiderApi();

  final RiderApi api;

  @override
  State<RiderApp> createState() => _RiderAppState();

  /// Every colour the Material widgets would otherwise derive from the seed is
  /// set here to a pair listed in docs/ux/contrast-report.md.
  static ThemeData _theme(RiderPalette p, Brightness brightness) {
    final danger = brightness == Brightness.dark
        ? DarkTokens.danger
        : LightTokens.danger;
    final scheme =
        ColorScheme.fromSeed(
          seedColor: p.brand,
          brightness: brightness,
        ).copyWith(
          surface: p.canvas,
          onSurface: p.text,
          onSurfaceVariant: p.textMuted,
          primary: p.brand,
          onPrimary: p.onBrand,
          secondaryContainer: p.tint,
          onSecondaryContainer: p.text,
          outline: p.outline,
          error: danger,
        );

    BorderSide side(Color color, double width) =>
        BorderSide(color: color, width: width);
    OutlineInputBorder border(Color color, double width) =>
        OutlineInputBorder(borderSide: side(color, width));
    // Visible keyboard focus: a 2 px ring in the text colour (3:1 or more
    // against the page in both themes).
    final focusRing = WidgetStateProperty.resolveWith<BorderSide?>(
      (states) => states.contains(WidgetState.focused) ? side(p.text, 2) : null,
    );

    return ThemeData(
      colorScheme: scheme,
      scaffoldBackgroundColor: p.canvas,
      materialTapTargetSize: MaterialTapTargetSize.padded,
      inputDecorationTheme: InputDecorationTheme(
        border: border(p.outline, 1),
        enabledBorder: border(p.outline, 1),
        disabledBorder: border(p.outline, 1),
        focusedBorder: border(p.brand, 2),
        errorBorder: border(danger, 1.5),
        focusedErrorBorder: border(danger, 2),
        labelStyle: WidgetStateTextStyle.resolveWith((states) {
          if (states.contains(WidgetState.error)) {
            return TextStyle(color: danger);
          }
          if (states.contains(WidgetState.focused)) {
            return TextStyle(color: p.brand);
          }
          return TextStyle(color: p.textMuted);
        }),
        errorStyle: TextStyle(color: danger),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: ButtonStyle(side: focusRing),
      ),
      iconButtonTheme: IconButtonThemeData(style: ButtonStyle(side: focusRing)),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: p.card,
        indicatorColor: p.tint,
        height: 72,
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(
            color: states.contains(WidgetState.disabled)
                ? p.textMuted.withValues(alpha: 0.6)
                : states.contains(WidgetState.selected)
                ? p.text
                : p.textMuted,
          ),
        ),
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontSize: 12,
            fontWeight: states.contains(WidgetState.selected)
                ? FontWeight.w700
                : FontWeight.w500,
            color: states.contains(WidgetState.disabled)
                ? p.textMuted.withValues(alpha: 0.6)
                : states.contains(WidgetState.selected)
                ? p.text
                : p.textMuted,
          ),
        ),
      ),
    );
  }
}

class _RiderAppState extends State<RiderApp> {
  // In memory only. Persistent secure storage is a later decision, so the
  // rider signs in again after the app is closed.
  RiderSession? _session;

  void _signOut() {
    final session = _session;
    // Return to sign-in at once, whatever the server answers. The revoke
    // call is best effort; the token expires after 60 minutes anyway.
    setState(() => _session = null);
    if (session != null) widget.api.signOut(session.token).ignore();
  }

  @override
  Widget build(BuildContext context) {
    final session = _session;

    return MaterialApp(
      title: 'Rider app',
      debugShowCheckedModeBanner: false,
      theme: RiderApp._theme(RiderPalette.light, Brightness.light),
      darkTheme: RiderApp._theme(RiderPalette.dark, Brightness.dark),
      home: session == null
          ? SignInScreen(
              api: widget.api,
              onSignedIn: (s) => setState(() => _session = s),
            )
          : HomeScreen(riderName: session.profile.name, onSignOut: _signOut),
    );
  }
}
