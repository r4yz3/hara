import 'package:flutter/material.dart';

import '../features/home/home_screen.dart';

/// Root widget. Colours follow the starting palette in the Phase 1
/// specification (section 8.1); contrast is not yet verified (backlog U-01).
class RiderApp extends StatelessWidget {
  const RiderApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Rider app',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF0F766E)),
        materialTapTargetSize: MaterialTapTargetSize.padded,
      ),
      darkTheme: ThemeData(
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFF0F766E),
          brightness: Brightness.dark,
        ),
      ),
      home: const HomeScreen(),
    );
  }
}
