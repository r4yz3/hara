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

  static ThemeData _theme(RiderPalette p, Brightness brightness) {
    final scheme =
        ColorScheme.fromSeed(
          seedColor: p.brand,
          brightness: brightness,
        ).copyWith(
          surface: p.canvas,
          onSurface: p.text,
          primary: p.brand,
          error: brightness == Brightness.dark
              ? DarkTokens.danger
              : LightTokens.danger,
        );

    return ThemeData(
      colorScheme: scheme,
      scaffoldBackgroundColor: p.canvas,
      materialTapTargetSize: MaterialTapTargetSize.padded,
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: p.card,
        height: 72,
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
