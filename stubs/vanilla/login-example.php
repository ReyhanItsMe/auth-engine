<?php

declare(strict_types=1);

/*
 * Example sign-in page for plain PHP. Copy it next to your front controller,
 * fix the two require paths, and adjust the URLs to your routes.
 *
 * Tailwind is loaded from the Play CDN so the page works out of the box;
 * for production, compile Tailwind and include these files in its "content" paths.
 */

use ReyhanItsMe\AuthEngine\Auth;

require __DIR__ . '/../../vendor/autoload.php';

foreach (['AuthCard', 'CredentialForm', 'SocialLoginRow', 'PasskeyButton'] as $component) {
    require_once __DIR__ . "/{$component}.php";
}

Auth::boot(__DIR__ . '/../../config/auth-engine.php');

// Minimal CSRF protection for the form below.
$session = Auth::manager()->session();
$csrf = $session->get('csrf_token') ?? bin2hex(random_bytes(32));
$session->put('csrf_token', $csrf);

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['identifier'] = (string) ($_POST['identifier'] ?? '');

    if (!hash_equals($csrf, (string) ($_POST['_token'] ?? ''))) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $result = Auth::attempt($old['identifier'], (string) ($_POST['password'] ?? ''));

        if ($result->ok()) {
            header('Location: /dashboard');
            exit;
        }
        if ($result->requiresMfa()) {
            header('Location: /mfa');
            exit;
        }
        $errors['form'] = $result->retryAfter > 0
            ? sprintf('Too many attempts. Try again in %d seconds.', $result->retryAfter)
            : 'These credentials do not match our records.';
    }
}

$body = CredentialForm([
    'action' => '',
    'csrfToken' => $csrf,
    'errors' => $errors,
    'old' => $old,
    'forgotPasswordHref' => '/forgot-password',
]);

if (Auth::config()->isDriverEnabled('webauthn')) {
    $body .= PasskeyButton(['optionsUrl' => '/passkey/options', 'verifyUrl' => '/passkey/verify', 'redirectTo' => '/dashboard', 'csrfToken' => $csrf]);
}

$body .= SocialLoginRow(['providers' => Auth::getActiveProviders()]);
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full antialiased">
<?= AuthCard([
    'title' => 'Welcome back',
    'subtitle' => 'Sign in to your account to continue',
    'footer' => 'No account yet? <a href="/register" class="font-medium text-indigo-600 transition hover:text-indigo-500">Create one</a>',
], $body) ?>
</body>
</html>
