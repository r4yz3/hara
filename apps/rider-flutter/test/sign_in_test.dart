import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rider_app/app/rider_app.dart';
import 'package:rider_app/core/api/rider_api.dart';
import 'package:rider_app/features/sign_in/sign_in_screen.dart';

import 'support/fake_rider_api.dart';

void main() {
  late FakeRiderApi api;

  setUp(() => api = FakeRiderApi());

  testWidgets('the app starts on sign-in, not on home', (tester) async {
    await tester.pumpWidget(RiderApp(api: api));

    expect(find.byKey(const Key('email-field')), findsOneWidget);
    expect(find.byKey(const Key('password-field')), findsOneWidget);
    expect(find.byKey(const Key('sign-in-button')), findsOneWidget);
    expect(find.text('Phase 1 scaffold'), findsNothing);
    expect(api.signInCalls, isEmpty);
  });

  testWidgets('success shows home with the name from /rider/me', (
    tester,
  ) async {
    await tester.pumpWidget(RiderApp(api: api));
    await submitSignIn(tester, email: '  rider@example.test ');
    await tester.pumpAndSettle();

    expect(api.signInCalls.single.email, 'rider@example.test');
    expect(api.meTokens, ['fake-token']);
    expect(find.text('Signed in as Test Rider'), findsOneWidget);
    expect(find.text('Phase 1 scaffold'), findsOneWidget);
    expect(find.byKey(const Key('email-field')), findsNothing);
  });

  testWidgets('401 shows one generic message and keeps the form', (
    tester,
  ) async {
    api.signInResult = const ApiFailure(
      ApiFailureKind.unauthorized,
      status: 401,
    );
    await tester.pumpWidget(RiderApp(api: api));
    await submitSignIn(tester);
    await tester.pumpAndSettle();

    expect(find.text(SignInScreen.invalidMessage), findsOneWidget);
    expect(find.byKey(const Key('email-field')), findsOneWidget);
    final shown = tester
        .widgetList<Text>(find.byType(Text))
        .map((t) => t.data ?? '')
        .join(' | ')
        .toLowerCase();
    expect(shown.contains('suspended'), isFalse);
    expect(shown.contains('not approved'), isFalse);
  });

  testWidgets('422 puts the messages under the fields', (tester) async {
    api.signInResult = const ApiFailure(
      ApiFailureKind.validation,
      status: 422,
      fields: {
        'email': ['The email field is required.'],
        'password': ['The password field is required.'],
      },
    );
    await tester.pumpWidget(RiderApp(api: api));
    await submitSignIn(tester, email: '', password: '');
    await tester.pumpAndSettle();

    expect(find.text('The email field is required.'), findsOneWidget);
    expect(find.text('The password field is required.'), findsOneWidget);
    expect(find.byKey(const Key('sign-in-message')), findsNothing);
  });

  testWidgets('422 on a field the form does not show is still explained', (
    tester,
  ) async {
    api.signInResult = const ApiFailure(
      ApiFailureKind.validation,
      status: 422,
      fields: {
        'device_name': ['The device name is too long.'],
      },
    );
    await tester.pumpWidget(RiderApp(api: api));
    await submitSignIn(tester);
    await tester.pumpAndSettle();

    expect(find.text('The device name is too long.'), findsOneWidget);
  });

  testWidgets('429 has its own message', (tester) async {
    api.signInResult = const ApiFailure(
      ApiFailureKind.rateLimited,
      status: 429,
    );
    await tester.pumpWidget(RiderApp(api: api));
    await submitSignIn(tester);
    await tester.pumpAndSettle();

    expect(find.text(SignInScreen.rateLimitedMessage), findsOneWidget);
    expect(find.text(SignInScreen.invalidMessage), findsNothing);
  });

  testWidgets('offline has its own message', (tester) async {
    api.signInResult = const ApiFailure(ApiFailureKind.offline);
    await tester.pumpWidget(RiderApp(api: api));
    await submitSignIn(tester);
    await tester.pumpAndSettle();

    expect(find.text(SignInScreen.offlineMessage), findsOneWidget);
  });

  testWidgets('an unexpected status says so without detail', (tester) async {
    api.signInResult = const ApiFailure(ApiFailureKind.unexpected, status: 500);
    await tester.pumpWidget(RiderApp(api: api));
    await submitSignIn(tester);
    await tester.pumpAndSettle();

    expect(find.text(SignInScreen.unexpectedMessage), findsOneWidget);
  });

  testWidgets('a rider whose profile cannot be read is not signed in', (
    tester,
  ) async {
    api.meResult = const ApiFailure(ApiFailureKind.unauthorized, status: 401);
    await tester.pumpWidget(RiderApp(api: api));
    await submitSignIn(tester);
    await tester.pumpAndSettle();

    expect(find.text(SignInScreen.invalidMessage), findsOneWidget);
    expect(find.text('Phase 1 scaffold'), findsNothing);
    expect(api.signOutTokens, ['fake-token']);
  });

  testWidgets('an empty API address says so and does not claim success', (
    tester,
  ) async {
    api = FakeRiderApi(configured: false);
    api.signInResult = const ApiFailure(ApiFailureKind.notConfigured);
    await tester.pumpWidget(RiderApp(api: api));

    expect(find.byKey(const Key('not-configured-notice')), findsOneWidget);
    expect(find.text(SignInScreen.notConfiguredMessage), findsOneWidget);

    await submitSignIn(tester);
    await tester.pumpAndSettle();
    expect(find.text(SignInScreen.notConfiguredMessage), findsOneWidget);
    expect(find.text('Phase 1 scaffold'), findsNothing);
  });

  testWidgets('the button is disabled while submitting', (tester) async {
    api.signInGate = Completer<void>();
    await tester.pumpWidget(RiderApp(api: api));
    await submitSignIn(tester);

    FilledButton button() =>
        tester.widget<FilledButton>(find.byKey(const Key('sign-in-button')));
    expect(button().onPressed, isNull);
    expect(find.text('Signing in'), findsOneWidget);

    // A second tap must not send a second request.
    await tester.tap(find.byKey(const Key('sign-in-button')));
    await tester.pump();
    expect(api.signInCalls, hasLength(1));

    api.signInGate!.complete();
    await tester.pumpAndSettle();
    expect(find.text('Signed in as Test Rider'), findsOneWidget);
  });

  testWidgets('the button is enabled again after a failure', (tester) async {
    api.signInResult = const ApiFailure(ApiFailureKind.offline);
    await tester.pumpWidget(RiderApp(api: api));
    await submitSignIn(tester);
    await tester.pumpAndSettle();

    final button = tester.widget<FilledButton>(
      find.byKey(const Key('sign-in-button')),
    );
    expect(button.onPressed, isNotNull);
  });

  testWidgets('the password is hidden until the rider asks', (tester) async {
    await tester.pumpWidget(RiderApp(api: api));

    EditableText field() => tester.widget<EditableText>(
      find.descendant(
        of: find.byKey(const Key('password-field')),
        matching: find.byType(EditableText),
      ),
    );
    expect(field().obscureText, isTrue);

    await tester.tap(find.byKey(const Key('toggle-password')));
    await tester.pump();
    expect(field().obscureText, isFalse);
    expect(find.byTooltip('Hide password'), findsOneWidget);

    await tester.tap(find.byKey(const Key('toggle-password')));
    await tester.pump();
    expect(field().obscureText, isTrue);
  });

  testWidgets('sign out calls the API and returns to sign-in', (tester) async {
    await pumpSignedInApp(tester, api);

    await tester.tap(find.byKey(const Key('sign-out-button')));
    await tester.pumpAndSettle();

    expect(api.signOutTokens, ['fake-token']);
    expect(find.byKey(const Key('email-field')), findsOneWidget);
    expect(find.text('Signed in as Test Rider'), findsNothing);
    // The form starts empty: nothing from the last session is kept.
    final email = tester.widget<TextField>(
      find.byKey(const Key('email-field')),
    );
    expect(email.controller!.text, isEmpty);
  });

  testWidgets('sign out returns to sign-in even if the call fails', (
    tester,
  ) async {
    api.signOutResult = const ApiFailure(ApiFailureKind.offline);
    await pumpSignedInApp(tester, api);

    await tester.tap(find.byKey(const Key('sign-out-button')));
    await tester.pumpAndSettle();

    expect(api.signOutTokens, ['fake-token']);
    expect(find.byKey(const Key('sign-in-button')), findsOneWidget);
  });

  testWidgets('sign out returns to sign-in even if the call throws', (
    tester,
  ) async {
    final throwing = _ThrowingSignOutApi();
    await pumpSignedInApp(tester, throwing);

    await tester.tap(find.byKey(const Key('sign-out-button')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('sign-in-button')), findsOneWidget);
  });

  for (final brightness in Brightness.values) {
    testWidgets('fits 320 px wide without overflow (${brightness.name})', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(320, 568);
      tester.view.devicePixelRatio = 1;
      tester.platformDispatcher.platformBrightnessTestValue = brightness;
      addTearDown(tester.view.reset);
      addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);

      // The longest content: every message and the busy state.
      api = FakeRiderApi(configured: false);
      api.signInResult = const ApiFailure(
        ApiFailureKind.validation,
        fields: {
          'email': ['The email field is required, and it must be an address.'],
          'password': ['The password field is required.'],
          'device_name': ['The device name is too long for this account.'],
        },
      );
      await tester.pumpWidget(RiderApp(api: api));
      expect(tester.takeException(), isNull);

      await submitSignIn(tester);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);

      // Also at a large text scale.
      tester.platformDispatcher.textScaleFactorTestValue = 1.6;
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('home after sign-in fits 320 px wide', (tester) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    api.meResult = const ApiOk(
      RiderProfile(
        id: 'r1',
        name: 'A Rider With A Rather Long Full Name',
        email: 'rider@example.test',
      ),
    );
    await pumpSignedInApp(tester, api);

    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('sign-out-button')), findsOneWidget);
  });

  testWidgets('fields and buttons have accessible labels', (tester) async {
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(RiderApp(api: api));

    expect(find.bySemanticsLabel('Email'), findsOneWidget);
    expect(find.bySemanticsLabel(RegExp('^Password')), findsOneWidget);
    expect(find.byTooltip('Show password'), findsOneWidget);
    expect(find.bySemanticsLabel('Sign in'), findsWidgets);
    final button = find.ancestor(
      of: find.text('Sign in').last,
      matching: find.byType(FilledButton),
    );
    expect(button, findsOneWidget);
    expect(tester.getSemantics(button).flagsCollection.isButton, isTrue);
    handle.dispose();
  });

  testWidgets('sign out is a labelled button', (tester) async {
    final handle = tester.ensureSemantics();
    await pumpSignedInApp(tester, api);

    expect(find.bySemanticsLabel('Sign out'), findsOneWidget);
    handle.dispose();
  });

  testWidgets('an error message is announced as a live region', (tester) async {
    final handle = tester.ensureSemantics();
    api.signInResult = const ApiFailure(ApiFailureKind.offline);
    await tester.pumpWidget(RiderApp(api: api));
    await submitSignIn(tester);
    await tester.pumpAndSettle();

    final node = tester.getSemantics(
      find
          .descendant(
            of: find.byKey(const Key('sign-in-message')),
            matching: find.byType(Semantics),
          )
          .first,
    );
    expect(node.flagsCollection.isLiveRegion, isTrue);
    handle.dispose();
  });
}

class _ThrowingSignOutApi extends FakeRiderApi {
  @override
  Future<ApiResult<void>> signOut(RiderToken token) async {
    throw StateError('network layer blew up');
  }
}
