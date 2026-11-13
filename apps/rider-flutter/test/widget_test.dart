import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rider_app/app/rider_app.dart';
import 'package:rider_app/features/home/home_screen.dart';

void main() {
  testWidgets('home is labelled as a scaffold', (tester) async {
    await tester.pumpWidget(const RiderApp());

    expect(find.text('Phase 1 scaffold'), findsOneWidget);
    for (final item in HomeScreen.notBuiltYet) {
      expect(find.text(item), findsOneWidget);
    }
  });

  testWidgets('online toggle is off and disabled', (tester) async {
    await tester.pumpWidget(const RiderApp());

    final toggle = tester.widget<SwitchListTile>(
      find.byKey(const Key('online-toggle')),
    );
    expect(toggle.value, isFalse);
    expect(toggle.onChanged, isNull);

    await tester.tap(find.byKey(const Key('online-toggle')));
    await tester.pump();
    expect(
      tester
          .widget<SwitchListTile>(find.byKey(const Key('online-toggle')))
          .value,
      isFalse,
    );
  });

  testWidgets('scaffold label is exposed to screen readers', (tester) async {
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(const RiderApp());

    expect(
      find.bySemanticsLabel('Phase 1 scaffold. This app has no live features.'),
      findsOneWidget,
    );
    handle.dispose();
  });

  testWidgets('only the Home destination is enabled', (tester) async {
    await tester.pumpWidget(const RiderApp());

    final destinations = tester
        .widgetList<NavigationDestination>(find.byType(NavigationDestination))
        .toList();

    expect(destinations.map((d) => d.label), [
      'Home',
      'Jobs',
      'Earnings',
      'Account',
    ]);
    expect(destinations.map((d) => d.enabled), [true, false, false, false]);
  });

  testWidgets('shows no invented money, distance or offer', (tester) async {
    await tester.pumpWidget(const RiderApp());

    final shown = tester
        .widgetList<Text>(find.byType(Text))
        .map((t) => t.data ?? '')
        .join(' | ');

    final invented = RegExp(r'[₱€$]|[0-9]+(\.[0-9]+)? ?(km|min)');
    expect(invented.hasMatch(shown), isFalse, reason: shown);
    expect(find.text('No delivery offers'), findsOneWidget);
    expect(find.text('Nothing to show yet'), findsOneWidget);
  });

  testWidgets('fits a narrow phone without overflow', (tester) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const RiderApp());

    expect(tester.takeException(), isNull);
  });

  test('android manifest requests no location permission', () {
    final manifest = File(
      'android/app/src/main/AndroidManifest.xml',
    ).readAsStringSync();

    expect(manifest.contains('ACCESS_FINE_LOCATION'), isFalse);
    expect(manifest.contains('ACCESS_COARSE_LOCATION'), isFalse);
    expect(manifest.contains('ACCESS_BACKGROUND_LOCATION'), isFalse);
  });
}
