// Renders the sign-in and home screens to PNG files under docs/qa/evidence so the layout
// can be reviewed without an Android device. It is not a regression test:
// it only runs when RENDER_EVIDENCE=1, and it always rewrites the images.
//
//   RENDER_EVIDENCE=1 flutter test test/render_evidence_test.dart --update-goldens

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rider_app/app/rider_app.dart';
import 'support/fake_rider_api.dart';

Future<void> _loadFont(String family, String path) async {
  final file = File(path);
  if (!file.existsSync()) return;

  final loader = FontLoader(family)
    ..addFont(Future.value(ByteData.sublistView(file.readAsBytesSync())));
  await loader.load();
}

void main() {
  final enabled = Platform.environment['RENDER_EVIDENCE'] == '1';
  final fonts =
      '${Platform.environment['FLUTTER_ROOT']}/bin/cache/artifacts/material_fonts';

  for (final brightness in Brightness.values) {
    testWidgets('render sign-in (${brightness.name})', (tester) async {
      await _loadFont('Roboto', '$fonts/Roboto-Regular.ttf');
      await _loadFont('Roboto', '$fonts/Roboto-Medium.ttf');
      await _loadFont('Roboto', '$fonts/Roboto-Bold.ttf');
      await _loadFont('MaterialIcons', '$fonts/MaterialIcons-Regular.otf');

      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      tester.platformDispatcher.platformBrightnessTestValue = brightness;
      addTearDown(tester.view.reset);
      addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);

      await tester.pumpWidget(RiderApp(api: FakeRiderApi()));
      await tester.pumpAndSettle();

      await expectLater(
        find.byType(MaterialApp),
        matchesGoldenFile(
          '../../../docs/qa/evidence/rider-signin-390-${brightness.name}.png',
        ),
      );
    }, skip: !enabled);

    testWidgets('render home (${brightness.name})', (tester) async {
      await _loadFont('Roboto', '$fonts/Roboto-Regular.ttf');
      await _loadFont('Roboto', '$fonts/Roboto-Medium.ttf');
      await _loadFont('Roboto', '$fonts/Roboto-Bold.ttf');
      await _loadFont('MaterialIcons', '$fonts/MaterialIcons-Regular.otf');

      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      tester.platformDispatcher.platformBrightnessTestValue = brightness;
      addTearDown(tester.view.reset);
      addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);

      await pumpSignedInApp(tester);
      await tester.pumpAndSettle();

      await expectLater(
        find.byType(MaterialApp),
        matchesGoldenFile(
          '../../../docs/qa/evidence/rider-home-390-${brightness.name}.png',
        ),
      );
    }, skip: !enabled);
  }
}
