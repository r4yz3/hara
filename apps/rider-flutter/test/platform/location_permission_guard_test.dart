// Guards spec section 8.4 and acceptance row "Rider app requests no location
// permission": in Phase 1 the rider app must not declare or request any location
// permission, and must not depend on a location plugin, until the location
// feature is built, policy-approved and explicitly enabled.
//
// It also keeps INTERNET declared in the main Android manifest, a regression
// guard for the 2026-11-15 release bug (every API call failed as "offline").
//
// Files are read relative to the app root, which is the working directory
// when `flutter test` runs from apps/rider-flutter.

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

const _androidSrc = 'android/app/src';
const _mainManifest = 'android/app/src/main/AndroidManifest.xml';
const _infoPlist = 'ios/Runner/Info.plist';
const _pubspec = 'pubspec.yaml';

const _locationPlugins = [
  'geolocator',
  'location',
  'background_location',
  'flutter_background_geolocation',
];

String _stripXmlComments(String xml) =>
    xml.replaceAll(RegExp(r'<!--[\s\S]*?-->'), '');

/// Names of every uses-permission and permission element in an Android manifest.
List<String> _declaredPermissions(String manifest) {
  final elements = RegExp(
    r'<(?:uses-permission(?:-sdk-\d+)?|permission)\b[^>]*>',
  );
  final names = RegExp(r'android:name="([^"]*)"');
  return [
    for (final element in elements.allMatches(_stripXmlComments(manifest)))
      for (final name in names.allMatches(element.group(0)!))
        name.group(1)!,
  ];
}

/// Any declared permission whose name contains LOCATION. This covers
/// ACCESS_FINE_LOCATION, ACCESS_COARSE_LOCATION and ACCESS_BACKGROUND_LOCATION.
List<String> _locationPermissions(String manifest) => _declaredPermissions(
  manifest,
).where((name) => name.toUpperCase().contains('LOCATION')).toList();

/// Usage-description keys beginning with NSLocation in an iOS Info.plist.
List<String> _locationPlistKeys(String plist) {
  final keys = RegExp(
    r'<key>\s*(NSLocation[^<]*?)\s*</key>',
    caseSensitive: false,
  );
  return [
    for (final key in keys.allMatches(_stripXmlComments(plist)))
      key.group(1)!,
  ];
}

/// Location plugins listed as a dependency in pubspec.yaml, at any depth.
List<String> _locationPluginDeps(String pubspec) {
  final deps = RegExp(
    r'^\s+(' + _locationPlugins.join('|') + r')\s*:',
    multiLine: true,
  );
  return [for (final m in deps.allMatches(pubspec)) m.group(1)!];
}

bool _declaresInternet(String manifest) => _declaredPermissions(
  manifest,
).contains('android.permission.INTERNET');

void main() {
  group('location permission guard (spec 8.4)', () {
    test('every Android manifest declares no location permission', () {
      final src = Directory(_androidSrc);
      expect(src.existsSync(), isTrue, reason: '$_androidSrc is missing');

      final manifests = [
        for (final dir in src.listSync().whereType<Directory>())
          File('${dir.path}/AndroidManifest.xml'),
      ].where((file) => file.existsSync()).toList();
      expect(
        manifests.map((f) => f.path.replaceAll('\\', '/')),
        contains(_mainManifest),
        reason: 'the main AndroidManifest.xml was not found',
      );

      final violations = <String, List<String>>{
        for (final file in manifests)
          file.path.replaceAll('\\', '/'): _locationPermissions(
            file.readAsStringSync(),
          ),
      }..removeWhere((_, found) => found.isEmpty);

      expect(
        violations,
        isEmpty,
        reason: 'location permissions must not be declared in Phase 1',
      );
    });

    test('iOS Info.plist has no NSLocation usage description', () {
      final plist = File(_infoPlist);
      if (!plist.existsSync()) {
        // ios/ is not part of this app yet. Nothing to check, so this part passes.
        // ignore: avoid_print
        print('note: $_infoPlist does not exist; NSLocation check skipped.');
        return;
      }

      expect(
        _locationPlistKeys(plist.readAsStringSync()),
        isEmpty,
        reason: 'NSLocation* usage descriptions must not be present in Phase 1',
      );
    });

    test('pubspec.yaml depends on no location plugin', () {
      expect(
        _locationPluginDeps(File(_pubspec).readAsStringSync()),
        isEmpty,
        reason: 'a location plugin is a dependency; Phase 1 has no location feature',
      );
    });

    test('main manifest declares INTERNET (release bug guard)', () {
      final manifest = File(_mainManifest).readAsStringSync();
      expect(
        _declaresInternet(manifest),
        isTrue,
        reason:
            'android.permission.INTERNET must be declared in $_mainManifest; '
            'without it release builds report every API call as offline',
      );
    });
  });

  group('detectors used by the guard above', () {
    test('a location permission is flagged and a commented one is not', () {
      const declared = '''
<manifest>
  <uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION"/>
</manifest>''';
      const commented = '''
<manifest>
  <!-- <uses-permission android:name="android.permission.ACCESS_FINE_LOCATION"/> -->
</manifest>''';

      expect(_locationPermissions(declared), [
        'android.permission.ACCESS_BACKGROUND_LOCATION',
      ]);
      expect(_locationPermissions(commented), isEmpty);
    });

    test('a sdk-23 or custom location permission is flagged', () {
      const manifest = '''
<manifest>
  <uses-permission-sdk-23 android:name="android.permission.ACCESS_COARSE_LOCATION"/>
  <permission android:name="com.example.rider.LOCATION_UPDATES"/>
</manifest>''';
      expect(_locationPermissions(manifest), hasLength(2));
    });

    test('INTERNET detection needs an actual uses-permission element', () {
      expect(
        _declaresInternet(
          '<manifest><uses-permission android:name="android.permission.INTERNET"/></manifest>',
        ),
        isTrue,
      );
      expect(
        _declaresInternet(
          '<manifest><!-- android.permission.INTERNET --></manifest>',
        ),
        isFalse,
      );
    });

    test('an NSLocation usage description is flagged', () {
      const plist = '''
<dict>
  <key>NSLocationWhenInUseUsageDescription</key>
  <string>Shown on the map.</string>
  <key>CFBundleName</key>
  <string>Rider</string>
</dict>''';
      expect(_locationPlistKeys(plist), [
        'NSLocationWhenInUseUsageDescription',
      ]);
    });

    test('a location plugin in any dependency section is flagged', () {
      const pubspec = '''
dependencies:
  flutter:
    sdk: flutter
  geolocator: ^14.0.0
dev_dependencies:
  location: ^8.0.0
''';
      expect(_locationPluginDeps(pubspec), ['geolocator', 'location']);
    });
  });
}
