import 'package:flutter/material.dart';

import '../../core/api/rider_api.dart';
import '../../core/palette.dart';

/// A signed-in rider: the token and the profile.
///
/// The token is kept in memory only, never written to disk. Persistent secure
/// storage (the Android keystore) is a later decision, so the rider signs in
/// again after the app is closed.
class RiderSession {
  const RiderSession({required this.token, required this.profile});

  final RiderToken token;
  final RiderProfile profile;
}

/// Sign-in with email and password against the rider token API.
class SignInScreen extends StatefulWidget {
  const SignInScreen({super.key, required this.api, required this.onSignedIn});

  final RiderApi api;
  final ValueChanged<RiderSession> onSignedIn;

  static const notConfiguredMessage =
      'Sign-in is not set up in this build. The API address was not provided '
      'when the app was built.';
  static const invalidMessage =
      'The email or password is not right, or this account cannot use the '
      'rider app.';
  static const rateLimitedMessage =
      'Too many sign-in attempts. Wait a minute, then try again.';
  static const offlineMessage =
      'No connection. Check your internet and try again.';
  static const unexpectedMessage =
      'Something went wrong on our side. Try again in a moment.';
  static const checkDetailsMessage = 'Check the details and try again.';

  @override
  State<SignInScreen> createState() => _SignInScreenState();
}

class _SignInScreenState extends State<SignInScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();

  bool _submitting = false;
  bool _showPassword = false;
  String? _message;
  Map<String, List<String>> _fieldErrors = const {};

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  String? _field(String name) {
    final messages = _fieldErrors[name];
    return messages == null || messages.isEmpty ? null : messages.join(' ');
  }

  Future<void> _submit() async {
    if (_submitting) return;
    setState(() {
      _submitting = true;
      _message = null;
      _fieldErrors = const {};
    });

    final signedIn = await widget.api.signIn(
      email: _email.text.trim(),
      password: _password.text,
    );
    if (!mounted) return;

    switch (signedIn) {
      case ApiFailure<RiderToken> failure:
        _fail(failure);
      case ApiOk<RiderToken>(value: final token):
        final profile = await widget.api.me(token);
        if (!mounted) return;
        switch (profile) {
          case ApiOk<RiderProfile>(value: final rider):
            widget.onSignedIn(RiderSession(token: token, profile: rider));
          case ApiFailure<RiderProfile> failure:
            // Do not leave a token behind that the rider cannot use.
            widget.api.signOut(token).ignore();
            _fail(failure);
        }
    }
  }

  void _fail(ApiFailure<Object?> failure) {
    setState(() {
      _submitting = false;
      switch (failure.kind) {
        case ApiFailureKind.notConfigured:
          _message = SignInScreen.notConfiguredMessage;
        case ApiFailureKind.unauthorized:
          _message = SignInScreen.invalidMessage;
        case ApiFailureKind.rateLimited:
          _message = SignInScreen.rateLimitedMessage;
        case ApiFailureKind.offline:
          _message = SignInScreen.offlineMessage;
        case ApiFailureKind.unexpected:
          _message = SignInScreen.unexpectedMessage;
        case ApiFailureKind.validation:
          _fieldErrors = failure.fields;
          // Fields the form does not show still need to be explained.
          final hidden = failure.fields.entries
              .where((e) => e.key != 'email' && e.key != 'password')
              .expand((e) => e.value);
          if (failure.fields.isEmpty) {
            _message = SignInScreen.checkDetailsMessage;
          } else if (hidden.isNotEmpty) {
            _message = hidden.join(' ');
          }
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final p = RiderPalette.of(context);
    final text = Theme.of(context).textTheme;
    final error = Theme.of(context).colorScheme.error;
    final notConfigured = !widget.api.isConfigured;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: AutofillGroup(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Semantics(
                      header: true,
                      child: Text(
                        'Sign in',
                        style: text.headlineMedium?.copyWith(
                          color: p.text,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Rider app. Use the email and password of your approved '
                      'rider account.',
                      style: text.bodyMedium?.copyWith(color: p.textMuted),
                    ),
                    const SizedBox(height: 24),
                    if (_message != null)
                      _Notice(
                        key: const Key('sign-in-message'),
                        message: _message!,
                        color: error,
                      )
                    else if (notConfigured)
                      _Notice(
                        key: const Key('not-configured-notice'),
                        message: SignInScreen.notConfiguredMessage,
                        color: p.textMuted,
                      ),
                    TextField(
                      key: const Key('email-field'),
                      controller: _email,
                      style: TextStyle(color: p.text),
                      enabled: !_submitting,
                      keyboardType: TextInputType.emailAddress,
                      textInputAction: TextInputAction.next,
                      autofillHints: const [AutofillHints.email],
                      autocorrect: false,
                      enableSuggestions: false,
                      decoration: InputDecoration(
                        labelText: 'Email',
                        errorText: _field('email'),
                      ),
                    ),
                    const SizedBox(height: 16),
                    TextField(
                      key: const Key('password-field'),
                      controller: _password,
                      style: TextStyle(color: p.text),
                      enabled: !_submitting,
                      obscureText: !_showPassword,
                      textInputAction: TextInputAction.done,
                      autofillHints: const [AutofillHints.password],
                      autocorrect: false,
                      enableSuggestions: false,
                      onSubmitted: (_) => _submit(),
                      decoration: InputDecoration(
                        labelText: 'Password',
                        errorText: _field('password'),
                        suffixIcon: IconButton(
                          key: const Key('toggle-password'),
                          tooltip: _showPassword
                              ? 'Hide password'
                              : 'Show password',
                          icon: Icon(
                            _showPassword
                                ? Icons.visibility_off_outlined
                                : Icons.visibility_outlined,
                            color: p.textMuted,
                          ),
                          onPressed: () =>
                              setState(() => _showPassword = !_showPassword),
                        ),
                      ),
                    ),
                    const SizedBox(height: 24),
                    FilledButton(
                      key: const Key('sign-in-button'),
                      onPressed: _submitting ? null : _submit,
                      style: FilledButton.styleFrom(
                        backgroundColor: p.brand,
                        foregroundColor: p.onBrand,
                        // While signing in the button keeps the listed brand
                        // pair so "Signing in" stays readable.
                        disabledBackgroundColor: p.brand,
                        disabledForegroundColor: p.onBrand,
                        minimumSize: const Size.fromHeight(52),
                      ),
                      child: _submitting
                          ? Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                    color: p.onBrand,
                                  ),
                                ),
                                const SizedBox(width: 12),
                                const Flexible(child: Text('Signing in')),
                              ],
                            )
                          : const Text('Sign in'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({super.key, required this.message, required this.color});

  final String message;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final p = RiderPalette.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Semantics(
        liveRegion: true,
        container: true,
        child: Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: p.card,
            border: Border.all(color: color, width: 1.5),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ExcludeSemantics(
                child: Icon(Icons.info_outline, color: color, size: 20),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(message, style: TextStyle(color: p.text)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
