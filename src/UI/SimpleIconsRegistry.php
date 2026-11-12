<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\UI;

/**
 * Brand icons from SimpleIcons (CC0) bundled with the package, so a
 * frontend can render provider buttons without any extra request.
 */
final class SimpleIconsRegistry
{
    /** @var array<string, array{title: string, hex: string, path: string}>|null */
    private static ?array $icons = null;

    private function __construct()
    {
    }

    public static function has(string $slug): bool
    {
        return isset(self::icons()[$slug]);
    }

    /** @return array{slug: string, title: string, hex: string, path: string, svg: string}|null */
    public static function get(string $slug): ?array
    {
        $icon = self::icons()[$slug] ?? null;

        return $icon === null ? null : ['slug' => $slug] + $icon + ['svg' => self::render($icon)];
    }

    /**
     * Raw SVG markup exactly as published by SimpleIcons (24x24 viewBox, single path).
     * Returns null for unknown slugs.
     *
     * @param array<string, string> $attributes extra attributes for the <svg> tag, e.g. ['class' => 'h-5 w-5', 'fill' => 'currentColor']
     */
    public static function svg(string $slug, array $attributes = []): ?string
    {
        $icon = self::icons()[$slug] ?? null;

        return $icon === null ? null : self::render($icon, $attributes);
    }

    /** Brand colour as "#RRGGBB", or null for unknown slugs. */
    public static function color(string $slug): ?string
    {
        $icon = self::icons()[$slug] ?? null;

        return $icon === null ? null : '#' . $icon['hex'];
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::icons());
    }

    /**
     * Add or replace an icon (e.g. for a custom OAuth provider).
     *
     * @param string $path the "d" attribute of a 24x24 SVG path
     */
    public static function register(string $slug, string $title, string $hex, string $path): void
    {
        if (preg_match('/^[0-9A-Fa-f]{6}$/', $hex) !== 1) {
            throw new \InvalidArgumentException('The colour must be a 6-digit hex value without "#".');
        }

        if (preg_match('/^[MmZzLlHhVvCcSsQqTtAa0-9eE\s,.+-]+$/', $path) !== 1) {
            throw new \InvalidArgumentException('The path may only contain SVG path commands and numbers.');
        }

        self::icons();
        self::$icons[$slug] = ['title' => $title, 'hex' => strtoupper($hex), 'path' => $path];
    }

    /**
     * @param array{title: string, hex: string, path: string} $icon
     * @param array<string, string> $attributes
     */
    private static function render(array $icon, array $attributes = []): string
    {
        $extra = '';
        foreach ($attributes as $name => $value) {
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9:-]*$/', $name) === 1) {
                $extra .= sprintf(' %s="%s"', $name, htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
        }

        return sprintf(
            '<svg role="img" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"%s><title>%s</title><path d="%s"/></svg>',
            $extra,
            htmlspecialchars($icon['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $icon['path'],
        );
    }

    /** @return array<string, array{title: string, hex: string, path: string}> */
    private static function icons(): array
    {
        return self::$icons ??= require __DIR__ . '/simple-icons.php';
    }
}
