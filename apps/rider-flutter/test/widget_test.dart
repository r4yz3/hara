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
      find.bySemanticsLabel(
        'Phase 1 scaffold. This app has no live features.',
      ),
      findsOneWidget,
    );
    handle.dispose();
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
