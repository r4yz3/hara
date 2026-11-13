import 'package:flutter/material.dart';

import '../../core/palette.dart';

/// Phase 1 home. The layout follows docs/ux/design-direction.md: a dark
/// header holding the one action that matters (going online), then jobs.
///
/// Going online needs sign-in, rider approval and the shift backend, none of
/// which exist yet, so the toggle is disabled and says why. No earnings,
/// distances or offers are shown because none exist. The app requests no
/// location permission.
class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  static const notBuiltYet = <String>[
    'Sign-in and device registration',
    'Shifts and delivery offers',
    'Pickup and delivery routes',
    'Earnings and cash remittance',
  ];

  static const destinations = <({String label, IconData icon})>[
    (label: 'Home', icon: Icons.home_outlined),
    (label: 'Jobs', icon: Icons.receipt_long_outlined),
    (label: 'Earnings', icon: Icons.account_balance_wallet_outlined),
    (label: 'Account', icon: Icons.person_outline),
  ];

  @override
  Widget build(BuildContext context) {
    final p = RiderPalette.of(context);
    final text = Theme.of(context).textTheme;

    return Scaffold(
      body: ListView(
        padding: EdgeInsets.zero,
        children: [
          _Header(palette: p),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 24, 16, 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        'New jobs',
                        style: text.titleLarge?.copyWith(
                          color: p.text,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                    _Tag(label: 'Later phase', palette: p),
                  ],
                ),
                const SizedBox(height: 12),
                _Panel(
                  palette: p,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'No delivery offers',
                        style: text.titleMedium?.copyWith(color: p.text),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Offers will appear here with the pickup, the '
                        'distance and what the delivery pays.',
                        style: text.bodyMedium?.copyWith(color: p.textMuted),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 24),
                Text(
                  'Not built yet',
                  style: text.titleMedium?.copyWith(color: p.text),
                ),
                const SizedBox(height: 8),
                for (final item in notBuiltYet)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(Icons.lock_outline, color: p.textMuted),
                    title: Text(item, style: TextStyle(color: p.text)),
                  ),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: 0,
        destinations: [
          for (final (index, d) in destinations.indexed)
            NavigationDestination(
              icon: Icon(d.icon),
              label: d.label,
              enabled: index == 0,
              tooltip: index == 0 ? d.label : '${d.label}, not built yet',
            ),
        ],
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.palette});

  final RiderPalette palette;

  @override
  Widget build(BuildContext context) {
    final p = palette;
    final text = Theme.of(context).textTheme;

    return Container(
      decoration: BoxDecoration(
        color: p.header,
        borderRadius: const BorderRadius.vertical(bottom: Radius.circular(28)),
      ),
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'Rider app',
                      style: text.titleLarge?.copyWith(
                        color: p.onHeader,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  Semantics(
                    container: true,
                    label: 'Phase 1 scaffold. This app has no live features.',
                    child: ExcludeSemantics(
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 6,
                        ),
                        decoration: BoxDecoration(
                          color: p.action,
                          borderRadius: BorderRadius.circular(999),
                        ),
                        child: Text(
                          'Phase 1 scaffold',
                          style: text.labelLarge?.copyWith(color: p.onAction),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                'Not signed in',
                style: text.bodyMedium?.copyWith(color: p.onHeaderMuted),
              ),
              const SizedBox(height: 16),
              DecoratedBox(
                decoration: BoxDecoration(
                  border: Border.all(color: p.onHeaderMuted),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: SwitchListTile(
                  key: const Key('online-toggle'),
                  value: false,
                  onChanged: null,
                  contentPadding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 4,
                  ),
                  title: Text(
                    'Go online',
                    style: text.titleMedium?.copyWith(color: p.onHeader),
                  ),
                  subtitle: Text(
                    'Unavailable. Shifts are not built yet.',
                    style: text.bodyMedium?.copyWith(color: p.onHeaderMuted),
                  ),
                ),
              ),
              const SizedBox(height: 20),
              Text(
                "Today's earnings",
                style: text.bodyMedium?.copyWith(color: p.onHeaderMuted),
              ),
              const SizedBox(height: 2),
              Text(
                'Nothing to show yet',
                style: text.headlineSmall?.copyWith(
                  color: p.action,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Panel extends StatelessWidget {
  const _Panel({required this.palette, required this.child});

  final RiderPalette palette;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: palette.card,
        border: Border.all(color: palette.outline),
        borderRadius: BorderRadius.circular(20),
      ),
      child: child,
    );
  }
}

class _Tag extends StatelessWidget {
  const _Tag({required this.label, required this.palette});

  final String label;
  final RiderPalette palette;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        border: Border.all(color: palette.outline),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: Theme.of(
          context,
        ).textTheme.labelMedium?.copyWith(color: palette.textMuted),
      ),
    );
  }
}
