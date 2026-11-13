import 'package:flutter/material.dart';

import '../core/palette.dart';
import '../features/home/home_screen.dart';

/// Root widget. Colours come from the shared design tokens
/// (packages/design-tokens), the same source the web apps use.
class RiderApp extends StatelessWidget {
  const RiderApp({super.key});

  static ThemeData _theme(RiderPalette p, Brightness brightness) {
    final scheme = ColorScheme.fromSeed(
      seedColor: p.brand,
      brightness: brightness,
    ).copyWith(surface: p.canvas, onSurface: p.text, primary: p.brand);

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

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Rider app',
      debugShowCheckedModeBanner: false,
      theme: _theme(RiderPalette.light, Brightness.light),
      darkTheme: _theme(RiderPalette.dark, Brightness.dark),
      home: const HomeScreen(),
    );
  }
}
