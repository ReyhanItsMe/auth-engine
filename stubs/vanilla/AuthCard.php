<?php

declare(strict_types=1);

/**
 * AuthCard — centered card that frames every auth screen.
 *
 *   echo AuthCard(['title' => 'Welcome back', 'subtitle' => '...'], $innerHtml);
 *
 * Props: title, subtitle, logo (trusted HTML), footer (trusted HTML).
 * $slot is trusted HTML produced by the other components.
 */
if (!function_exists('AuthCard')) {
    function AuthCard(array $props, string $slot): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start(); ?>
<div class="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-12">
  <div class="w-full max-w-md">
    <div class="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-900/5 sm:p-10">
      <?php if (!empty($props['logo'])): ?>
        <div class="mb-6 flex justify-center"><?= $props['logo'] ?></div>
      <?php endif; ?>
      <div class="mb-8 text-center">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900"><?= $e($props['title'] ?? 'Sign in') ?></h1>
        <?php if (!empty($props['subtitle'])): ?>
          <p class="mt-2 text-sm text-slate-500"><?= $e($props['subtitle']) ?></p>
        <?php endif; ?>
      </div>
      <div class="space-y-6"><?= $slot ?></div>
    </div>
    <?php if (!empty($props['footer'])): ?>
      <div class="mt-6 text-center text-sm text-slate-500"><?= $props['footer'] ?></div>
    <?php endif; ?>
  </div>
</div>
        <?php return (string) ob_get_clean();
    }
}
