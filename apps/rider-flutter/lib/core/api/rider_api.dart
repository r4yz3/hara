import 'dart:async';
import 'dart:convert';
import 'dart:io';

/// Why a call to the API did not give the expected answer.
enum ApiFailureKind {
  /// `API_BASE_URL` is empty, so nothing was called.
  notConfigured,

  /// 401. The API gives one answer for a wrong password, an unknown address,
  /// a non-rider, an unapproved rider and a suspended account, and so does
  /// the app.
  unauthorized,

  /// 422, with messages per field in [ApiFailure.fields].
  validation,

  /// 429.
  rateLimited,

  /// The request never got an answer (no network, DNS, timeout).
  offline,

  /// Any other status, or a body that is not the documented shape.
  unexpected,
}

sealed class ApiResult<T> {
  const ApiResult();
}

class ApiOk<T> extends ApiResult<T> {
  const ApiOk(this.value);

  final T value;
}

class ApiFailure<T> extends ApiResult<T> {
  const ApiFailure(this.kind, {this.fields = const {}, this.status});

  final ApiFailureKind kind;

  /// Messages per request field, from a 422. Empty for other failures.
  final Map<String, List<String>> fields;

  /// The HTTP status, when there was one.
  final int? status;
}

/// A bearer token as issued by `POST /api/v1/auth/mobile/token`.
///
/// [toString] hides the token so it cannot reach a log by accident.
class RiderToken {
  const RiderToken({required this.token, required this.expiresAt});

  final String token;
  final DateTime expiresAt;

  @override
  String toString() => 'RiderToken(expiresAt: $expiresAt)';
}

/// The signed-in rider, from `GET /api/v1/rider/me`.
class RiderProfile {
  const RiderProfile({
    required this.id,
    required this.name,
    required this.email,
  });

  final String id;
  final String name;
  final String email;
}

/// What the rider app needs from the backend. Tests use a fake.
abstract interface class RiderApi {
  /// False when no API base URL was compiled in.
  bool get isConfigured;

  Future<ApiResult<RiderToken>> signIn({
    required String email,
    required String password,
  });

  Future<ApiResult<RiderProfile>> me(RiderToken token);

  /// Revokes the token on the server.
  Future<ApiResult<void>> signOut(RiderToken token);
}

/// The API base URL, set with `--dart-define=API_BASE_URL=...`. There is no
/// default host: an empty value means "not configured".
const apiBaseUrl = String.fromEnvironment('API_BASE_URL');

/// One HTTP exchange, as far as the client cares.
class RawResponse {
  const RawResponse(this.status, this.body);

  final int status;
  final String body;
}

/// Sends one request. The real one uses `dart:io`; tests can pass their own.
typedef HttpSend =
    Future<RawResponse> Function(
      String method,
      Uri uri,
      Map<String, String> headers,
      String? body,
    );

/// [RiderApi] over `dart:io` HTTP. It never logs a password or a token.
class HttpRiderApi implements RiderApi {
  HttpRiderApi({
    String baseUrl = apiBaseUrl,
    HttpSend? send,
    this.timeout = const Duration(seconds: 15),
  }) : _baseUrl = baseUrl.trim().replaceAll(RegExp(r'/+$'), ''),
       _send = send ?? _ioSend;

  /// Sent with the sign-in request so the server can name the device.
  static const deviceName = 'Rider app (Android)';

  final String _baseUrl;
  final HttpSend _send;
  final Duration timeout;

  @override
  bool get isConfigured => _baseUrl.isNotEmpty;

  @override
  Future<ApiResult<RiderToken>> signIn({
    required String email,
    required String password,
  }) {
    return _call(
      'POST',
      '/api/v1/auth/mobile/token',
      body: {
        'email': email,
        'password': password,
        'device_name': deviceName,
        'platform': 'android',
      },
      okStatus: 201,
      parse: (json) {
        final data = json['data'];
        if (data is! Map<String, dynamic>) return null;
        final token = data['token'];
        final expires = DateTime.tryParse('${data['expires_at']}');
        if (token is! String || token.isEmpty || expires == null) return null;
        return RiderToken(token: token, expiresAt: expires);
      },
    );
  }

  @override
  Future<ApiResult<RiderProfile>> me(RiderToken token) {
    return _call(
      'GET',
      '/api/v1/rider/me',
      bearer: token.token,
      okStatus: 200,
      parse: (json) {
        final data = json['data'];
        if (data is! Map<String, dynamic>) return null;
        final id = data['id'];
        final name = data['name'];
        final email = data['email'];
        if (id is! String || name is! String || email is! String) return null;
        return RiderProfile(id: id, name: name, email: email);
      },
    );
  }

  @override
  Future<ApiResult<void>> signOut(RiderToken token) {
    return _call<void>(
      'DELETE',
      '/api/v1/auth/mobile/token',
      bearer: token.token,
      okStatus: 204,
      parse: (_) {},
      emptyBodyIsOk: true,
    );
  }

  Future<ApiResult<T>> _call<T>(
    String method,
    String path, {
    required int okStatus,
    required T? Function(Map<String, dynamic> json) parse,
    Map<String, Object?>? body,
    String? bearer,
    bool emptyBodyIsOk = false,
  }) async {
    if (!isConfigured) return ApiFailure<T>(ApiFailureKind.notConfigured);

    final Uri uri;
    try {
      uri = Uri.parse('$_baseUrl$path');
    } on FormatException {
      return ApiFailure<T>(ApiFailureKind.notConfigured);
    }

    final headers = <String, String>{
      'Accept': 'application/json',
      if (body != null) 'Content-Type': 'application/json',
      if (bearer != null) 'Authorization': 'Bearer $bearer',
    };

    final RawResponse response;
    try {
      response = await _send(
        method,
        uri,
        headers,
        body == null ? null : jsonEncode(body),
      ).timeout(timeout);
    } on SocketException {
      return ApiFailure<T>(ApiFailureKind.offline);
    } on TimeoutException {
      return ApiFailure<T>(ApiFailureKind.offline);
    } on HttpException {
      return ApiFailure<T>(ApiFailureKind.offline);
    } on Object {
      return ApiFailure<T>(ApiFailureKind.unexpected);
    }

    final status = response.status;
    if (status == okStatus) {
      if (emptyBodyIsOk) return ApiOk<T>(null as T);
      final json = _decode(response.body);
      final value = json == null ? null : parse(json);
      if (value == null) {
        return ApiFailure<T>(ApiFailureKind.unexpected, status: status);
      }
      return ApiOk<T>(value);
    }

    switch (status) {
      case 401:
        return ApiFailure<T>(ApiFailureKind.unauthorized, status: status);
      case 429:
        return ApiFailure<T>(ApiFailureKind.rateLimited, status: status);
      case 422:
        return ApiFailure<T>(
          ApiFailureKind.validation,
          status: status,
          fields: _fields(_decode(response.body)),
        );
      default:
        return ApiFailure<T>(ApiFailureKind.unexpected, status: status);
    }
  }

  static Map<String, dynamic>? _decode(String body) {
    try {
      final json = jsonDecode(body);
      return json is Map<String, dynamic> ? json : null;
    } on FormatException {
      return null;
    }
  }

  /// Reads `error.fields` from the error envelope.
  static Map<String, List<String>> _fields(Map<String, dynamic>? json) {
    final error = json?['error'];
    final fields = error is Map<String, dynamic> ? error['fields'] : null;
    if (fields is! Map<String, dynamic>) return const {};

    return {
      for (final entry in fields.entries)
        if (entry.value is List)
          entry.key: [
            for (final message in entry.value as List)
              if (message is String) message,
          ],
    };
  }

  static Future<RawResponse> _ioSend(
    String method,
    Uri uri,
    Map<String, String> headers,
    String? body,
  ) async {
    final client = HttpClient();
    try {
      final request = await client.openUrl(method, uri);
      headers.forEach(request.headers.set);
      if (body != null) request.add(utf8.encode(body));
      final response = await request.close();
      final text = await response.transform(utf8.decoder).join();
      return RawResponse(response.statusCode, text);
    } finally {
      client.close(force: true);
    }
  }
}
