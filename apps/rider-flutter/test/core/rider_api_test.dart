import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:rider_app/core/api/rider_api.dart';

typedef _Sent = ({
  String method,
  Uri uri,
  Map<String, String> headers,
  String? body,
});

const _tokenBody =
    '{"data":{"token":"issued-token","token_type":"Bearer",'
    '"abilities":["rider:profile"],"expires_at":"2030-01-01T01:00:00Z"},'
    '"meta":{}}';

void main() {
  late List<_Sent> sent;
  final token = RiderToken(
    token: 'secret-token',
    expiresAt: DateTime.utc(2030),
  );

  HttpRiderApi clientAnswering(
    Future<RawResponse> Function() answer, {
    Duration timeout = const Duration(seconds: 5),
  }) {
    return HttpRiderApi(
      baseUrl: 'https://api.example.test/',
      timeout: timeout,
      send: (method, uri, headers, body) {
        sent.add((method: method, uri: uri, headers: headers, body: body));
        return answer();
      },
    );
  }

  HttpRiderApi clientWith(int status, [String body = '']) =>
      clientAnswering(() async => RawResponse(status, body));

  setUp(() => sent = []);

  test('an empty base URL is not configured and calls nothing', () async {
    final unset = HttpRiderApi(
      baseUrl: '',
      send: (m, u, h, b) => fail('must not send'),
    );

    expect(unset.isConfigured, isFalse);
    final result = await unset.signIn(email: 'a@b.test', password: 'pw');
    expect((result as ApiFailure).kind, ApiFailureKind.notConfigured);
    expect(await unset.me(token), isA<ApiFailure<RiderProfile>>());
    expect(await unset.signOut(token), isA<ApiFailure<void>>());
  });

  group('signIn', () {
    test('posts the documented body without an Origin header', () async {
      final result = await clientWith(
        201,
        _tokenBody,
      ).signIn(email: 'a@b.test', password: 'pw');

      final request = sent.single;
      expect(request.method, 'POST');
      expect(
        request.uri.toString(),
        'https://api.example.test/api/v1/auth/mobile/token',
      );
      expect(
        request.headers.keys.map((k) => k.toLowerCase()),
        isNot(contains('origin')),
      );
      expect(request.headers.containsKey('Authorization'), isFalse);
      expect(jsonDecode(request.body!), {
        'email': 'a@b.test',
        'password': 'pw',
        'device_name': HttpRiderApi.deviceName,
        'platform': 'android',
      });
      final ok = result as ApiOk<RiderToken>;
      expect(ok.value.token, 'issued-token');
      expect(ok.value.expiresAt, DateTime.utc(2030, 1, 1, 1));
    });

    test('401 is unauthorized', () async {
      final result = await clientWith(
        401,
        '{"error":{"code":"AUTH_REQUIRED"}}',
      ).signIn(email: 'a', password: 'b');
      expect((result as ApiFailure).kind, ApiFailureKind.unauthorized);
    });

    test('422 carries the field messages', () async {
      final result = await clientWith(
        422,
        '{"error":{"code":"VALIDATION_ERROR","message":"x","request_id":"1",'
        '"fields":{"email":["Enter an email."],"password":["a","b"]}}}',
      ).signIn(email: '', password: '');

      final failure = result as ApiFailure<RiderToken>;
      expect(failure.kind, ApiFailureKind.validation);
      expect(failure.fields, {
        'email': ['Enter an email.'],
        'password': ['a', 'b'],
      });
    });

    test('422 without a readable body has no fields', () async {
      final result = await clientWith(
        422,
        'not json',
      ).signIn(email: '', password: '');
      expect((result as ApiFailure).fields, isEmpty);
    });

    test('429 is rate limited', () async {
      final result = await clientWith(429).signIn(email: 'a', password: 'b');
      expect((result as ApiFailure).kind, ApiFailureKind.rateLimited);
    });

    test('other statuses are unexpected', () async {
      final result = await clientWith(
        500,
        '{}',
      ).signIn(email: 'a', password: 'b');
      final failure = result as ApiFailure;
      expect(failure.kind, ApiFailureKind.unexpected);
      expect(failure.status, 500);
    });

    test('a 201 with the wrong shape is unexpected', () async {
      final result = await clientWith(
        201,
        '{"data":{}}',
      ).signIn(email: 'a', password: 'b');
      expect((result as ApiFailure).kind, ApiFailureKind.unexpected);
    });

    test('a socket error is offline', () async {
      final api = clientAnswering(() => throw const SocketException('down'));
      final result = await api.signIn(email: 'a', password: 'b');
      expect((result as ApiFailure).kind, ApiFailureKind.offline);
    });

    test('a timeout is offline', () async {
      final api = clientAnswering(
        () => Completer<RawResponse>().future,
        timeout: const Duration(milliseconds: 10),
      );
      final result = await api.signIn(email: 'a', password: 'b');
      expect((result as ApiFailure).kind, ApiFailureKind.offline);
    });

    test('any other error is unexpected', () async {
      final api = clientAnswering(() => throw StateError('boom'));
      final result = await api.signIn(email: 'a', password: 'b');
      expect((result as ApiFailure).kind, ApiFailureKind.unexpected);
    });
  });

  group('me', () {
    test('sends the bearer token and reads the profile', () async {
      final api = clientWith(
        200,
        '{"data":{"id":"r1","user_id":"u1","name":"Ana Reyes",'
        '"email":"ana@example.test","status":"approved","approved_at":null},'
        '"meta":{}}',
      );
      final result = await api.me(token);

      expect(sent.single.method, 'GET');
      expect(sent.single.uri.path, '/api/v1/rider/me');
      expect(sent.single.headers['Authorization'], 'Bearer secret-token');
      expect((result as ApiOk<RiderProfile>).value.name, 'Ana Reyes');
    });

    test('401 is unauthorized', () async {
      final result = await clientWith(401).me(token);
      expect((result as ApiFailure).kind, ApiFailureKind.unauthorized);
    });
  });

  group('signOut', () {
    test('sends DELETE with the bearer token; 204 is ok', () async {
      final result = await clientWith(204).signOut(token);

      expect(sent.single.method, 'DELETE');
      expect(sent.single.uri.path, '/api/v1/auth/mobile/token');
      expect(sent.single.headers['Authorization'], 'Bearer secret-token');
      expect(result, isA<ApiOk<void>>());
    });

    test('401 is reported, not thrown', () async {
      final result = await clientWith(401).signOut(token);
      expect((result as ApiFailure).kind, ApiFailureKind.unauthorized);
    });
  });

  test('a token does not appear in its string form', () {
    expect(token.toString(), isNot(contains('secret-token')));
  });

  group('over a real socket', () {
    late HttpServer server;
    late Map<String, String> seenHeaders;
    late String seenBody;

    setUp(() async {
      server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      seenHeaders = {};
      seenBody = '';
      server.listen((request) async {
        request.headers.forEach(
          (name, values) => seenHeaders[name] = values.join(','),
        );
        seenBody = await utf8.decoder.bind(request).join();
        request.response
          ..statusCode = 201
          ..headers.contentType = ContentType.json
          ..write(_tokenBody);
        await request.response.close();
      });
    });

    tearDown(() => server.close(force: true));

    test('the default dart:io sender works', () async {
      final api = HttpRiderApi(baseUrl: 'http://127.0.0.1:${server.port}');
      final result = await api.signIn(email: 'a@b.test', password: 'pw');

      expect((result as ApiOk<RiderToken>).value.token, 'issued-token');
      expect(jsonDecode(seenBody)['email'], 'a@b.test');
      expect(seenHeaders['content-type'], contains('application/json'));
      expect(seenHeaders.containsKey('origin'), isFalse);
    });

    test('a closed port is offline', () async {
      final port = server.port;
      await server.close(force: true);
      final api = HttpRiderApi(baseUrl: 'http://127.0.0.1:$port');
      final result = await api.signIn(email: 'a', password: 'b');
      expect((result as ApiFailure).kind, ApiFailureKind.offline);
    });
  });
}
