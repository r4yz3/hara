import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rider_app/app/rider_app.dart';
import 'package:rider_app/core/api/rider_api.dart';

/// A [RiderApi] that answers from fields the test sets and records calls.
class FakeRiderApi implements RiderApi {
  FakeRiderApi({this.configured = true});

  final bool configured;

  ApiResult<RiderToken> signInResult = ApiOk(
    RiderToken(token: 'fake-token', expiresAt: DateTime.utc(2030)),
  );
  ApiResult<RiderProfile> meResult = const ApiOk(
    RiderProfile(id: 'r1', name: 'Test Rider', email: 'rider@example.test'),
  );
  ApiResult<void> signOutResult = const ApiOk(null);

  /// When set, sign-in waits for it, so a test can look at the screen while
  /// the request is in flight.
  Completer<void>? signInGate;

  final signInCalls = <({String email, String password})>[];
  final meTokens = <String>[];
  final signOutTokens = <String>[];

  @override
  bool get isConfigured => configured;

  @override
  Future<ApiResult<RiderToken>> signIn({
    required String email,
    required String password,
  }) async {
    signInCalls.add((email: email, password: password));
    await signInGate?.future;
    return signInResult;
  }

  @override
  Future<ApiResult<RiderProfile>> me(RiderToken token) async {
    meTokens.add(token.token);
    return meResult;
  }

  @override
  Future<ApiResult<void>> signOut(RiderToken token) async {
    signOutTokens.add(token.token);
    return signOutResult;
  }
}

/// Fills the sign-in form and submits it.
Future<void> submitSignIn(
  WidgetTester tester, {
  String email = 'rider@example.test',
  String password = 'not-a-real-password',
}) async {
  await tester.enterText(find.byKey(const Key('email-field')), email);
  await tester.enterText(find.byKey(const Key('password-field')), password);
  await tester.tap(find.byKey(const Key('sign-in-button')));
  await tester.pump();
}

/// Starts the app on sign-in and signs in with [api]'s answers, so a test can
/// look at the home screen.
Future<void> pumpSignedInApp(WidgetTester tester, [FakeRiderApi? api]) async {
  await tester.pumpWidget(RiderApp(api: api ?? FakeRiderApi()));
  await submitSignIn(tester);
  await tester.pumpAndSettle();
}
