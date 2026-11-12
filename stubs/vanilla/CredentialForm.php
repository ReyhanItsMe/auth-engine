<?php

declare(strict_types=1);

/**
 * CredentialForm — sign-in / sign-up form.
 *
 *   echo CredentialForm(['action' => '/login', 'errors' => ['form' => 'Invalid credentials.']]);
 *
 * Props:
 *   action            form target (POST)
 *   mode              'login' (default) | 'register'
 *   identifierLabel   'Email' (default), 'Email or username', ...
 *   identifierType    'email' (default) | 'text' | 'tel'
 *   old               previously submitted values: ['identifier' => ..., 'name' => ...]
 *   errors            ['form' => ..., 'identifier' => ..., 'password' => ..., 'name' => ...]
 *   csrfToken         rendered as a hidden "_token" field
 *   forgotPasswordHref, minPasswordLength
 *
 * Submits: identifier, password (and name when registering).
 */
if (!function_exists('CredentialForm')) {
    function CredentialForm(array $props = []): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $register = ($props['mode'] ?? 'login') === 'register';
        $errors = (array) ($props['errors'] ?? []);
        $old = (array) ($props['old'] ?? []);
        $type = in_array($props['identifierType'] ?? 'email', ['email', 'text', 'tel'], true) ? $props['identifierType'] ?? 'email' : 'email';
        $input = 'block w-full rounded-lg border-0 px-3.5 py-2.5 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 '
            . 'placeholder:text-slate-400 transition focus:ring-2 focus:ring-inset focus:ring-indigo-600';

        ob_start(); ?>
<form method="post" action="<?= $e($props['action'] ?? '') ?>" class="space-y-5">
  <?php if (!empty($props['csrfToken'])): ?>
    <input type="hidden" name="_token" value="<?= $e($props['csrfToken']) ?>">
  <?php endif; ?>

  <?php if (!empty($errors['form'])): ?>
    <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200"><?= $e($errors['form']) ?></div>
  <?php endif; ?>

  <?php if ($register): ?>
    <div>
      <label for="auth-name" class="mb-1.5 block text-sm font-medium text-slate-700">Name</label>
      <input id="auth-name" name="name" type="text" autocomplete="name" value="<?= $e($old['name'] ?? '') ?>" class="<?= $input ?>">
      <?php if (!empty($errors['name'])): ?><p class="mt-1.5 text-sm text-red-600"><?= $e($errors['name']) ?></p><?php endif; ?>
    </div>
  <?php endif; ?>

  <div>
    <label for="auth-identifier" class="mb-1.5 block text-sm font-medium text-slate-700"><?= $e($props['identifierLabel'] ?? 'Email') ?></label>
    <input id="auth-identifier" name="identifier" type="<?= $e($type) ?>" required autocomplete="username"
           value="<?= $e($old['identifier'] ?? '') ?>" class="<?= $input ?>">
    <?php if (!empty($errors['identifier'])): ?><p class="mt-1.5 text-sm text-red-600"><?= $e($errors['identifier']) ?></p><?php endif; ?>
  </div>

  <div>
    <div class="mb-1.5 flex items-center justify-between">
      <label for="auth-password" class="block text-sm font-medium text-slate-700">Password</label>
      <?php if (!$register && !empty($props['forgotPasswordHref'])): ?>
        <a href="<?= $e($props['forgotPasswordHref']) ?>" class="text-sm font-medium text-indigo-600 transition hover:text-indigo-500">Forgot password?</a>
      <?php endif; ?>
    </div>
    <input id="auth-password" name="password" type="password" required
           <?php if ($register): ?>minlength="<?= (int) ($props['minPasswordLength'] ?? 8) ?>"<?php endif; ?>
           autocomplete="<?= $register ? 'new-password' : 'current-password' ?>" class="<?= $input ?>">
    <?php if (!empty($errors['password'])): ?><p class="mt-1.5 text-sm text-red-600"><?= $e($errors['password']) ?></p><?php endif; ?>
  </div>

  <button type="submit" class="flex w-full justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
    <?= $register ? 'Create account' : 'Sign in' ?>
  </button>
</form>
        <?php return (string) ob_get_clean();
    }
}
