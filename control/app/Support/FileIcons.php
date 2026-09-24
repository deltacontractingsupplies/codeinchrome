<?php

namespace App\Support;

/**
 * File and folder icons for pages drawn on the server - the same Material
 * Icon Theme manifest (public/file-icons, MIT) and the same rules as the
 * editor's iconFor() in resources/js/editor.js, so a read-only view shows
 * exactly the icons the editor does. Keep the two in step.
 */
class FileIcons
{
    private ?array $manifest = null;

    /**
     * @return array{dark: ?string, light: ?string} icon URLs; light differs
     *                                              only where the theme has a light variant
     */
    public function for(string $name, bool $dir = false, bool $open = false): array
    {
        return ['dark' => $this->resolve($name, $dir, $open, false), 'light' => $this->resolve($name, $dir, $open, true)];
    }

    private function resolve(string $name, bool $dir, bool $open, bool $light): ?string
    {
        $m = $this->manifest();
        if (! $m) {
            return null;
        }
        $pick = fn (string $table, string $key) => ($light ? ($m['light'][$table][$key] ?? null) : null) ?? ($m[$table][$key] ?? null);
        $name = strtolower($name);
        if ($dir) {
            $icon = $pick($open ? 'folderNamesExpanded' : 'folderNames', $name) ?? ($open ? $m['folderExpanded'] : $m['folder']);
        } else {
            $icon = $pick('fileNames', $name);
            // Longest extension first: "blade.php" before "php".
            $parts = explode('.', $name);
            for ($i = 1; ! $icon && $i < count($parts); $i++) {
                $icon = $pick('fileExtensions', implode('.', array_slice($parts, $i)));
            }
            if (! $icon && ($name === '.env' || str_starts_with($name, '.env.'))) {
                $icon = $pick('fileNames', '.env.example');
            }
            $icon ??= $m['file'];
        }

        return isset($m['known'][$icon]) ? "/file-icons/$icon.svg" : null;
    }

    private function manifest(): ?array
    {
        if ($this->manifest === null) {
            $raw = @file_get_contents(public_path('file-icons/manifest.json'));
            $m = $raw ? json_decode($raw, true) : null;
            $this->manifest = is_array($m) ? $m + ['known' => array_flip($m['icons'] ?? [])] : [];
        }

        return $this->manifest ?: null;
    }
}
