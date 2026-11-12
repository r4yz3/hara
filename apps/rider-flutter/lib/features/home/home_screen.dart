import 'package:flutter/material.dart';

/// Phase 1 placeholder. Going online needs sign-in, rider approval and the
/// shift backend, none of which exist yet, so the toggle is disabled and says
/// why. The app requests no location permission.
class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  static const notBuiltYet = <String>[
    'Sign-in and device registration',
    'Shifts and delivery offers',
    'Pickup and delivery routes',
    'Earnings and cash remittance',
  ];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Rider app')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Semantics(
              label: 'Phase 1 scaffold. This app has no live features.',
              child: ExcludeSemantics(
                child: Chip(
                  label: const Text('Phase 1 scaffold'),
                  backgroundColor: theme.colorScheme.surfaceContainerHighest,
                ),
              ),
            ),
            const SizedBox(height: 16),
            Card(
              child: SwitchListTile(
                key: const Key('online-toggle'),
                value: false,
                onChanged: null,
                title: const Text('Go online'),
                subtitle: const Text(
                  'Unavailable. Shifts are not built yet.',
                ),
              ),
            ),
            const SizedBox(height: 24),
            Text('Not built yet', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            for (final item in notBuiltYet)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.lock_outline),
                title: Text(item),
              ),
          ],
        ),
      ),
    );
  }
}
