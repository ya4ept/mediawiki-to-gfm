<?php
// SPDX-FileCopyrightText: 2026 Out of Control, Inc.
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace App;

/**
 * Callback class that rewrites [[wiki links]] into clean, normalized links.
 */
class CleanLink
{
    /**
     * @param bool $flatten When true, path separators are replaced with underscores.
     * @param array<string, string> $meta File information for the page being converted.
     */
    public function __construct(
        private bool $flatten,
        private array $meta,
    ) {
    }

    /**
     * Generate a cleaned link from a regex match.
     *
     * @param array<int, string> $matches The full match data for a [[link]].
     */
    public function cleanLink(array $matches): string
    {
        $linkToClean = $matches[1];

        // A link starting with http is a malformed wiki link; return it as a plain link.
        if (preg_match('/^https?:\/\//', $linkToClean)) {
            return '[' . $linkToClean . ']';
        }

        // Convert relative paths to absolute paths.
        if (preg_match('/^\.*?\//', $linkToClean)) {
            $linkToClean = $this->meta['url'] . '/' . $linkToClean;
        }

        if (!str_contains($linkToClean, '|')) {
            $link = $linkToClean;
            $link_text = $linkToClean;
        } else {
            [$link, $link_text] = explode('|', $linkToClean);
        }

        // Normalize path, removing extra ../ segments.
        $link = $this->normalizePath(trim($link));

        // Flatten a nested structure by replacing / with _.
        if ($this->flatten) {
            $link = str_replace('/', '_', $link);
        }

        // Clean up remaining artifacts.
        $link = str_replace(' ', '_', $link);

        $link_text = trim($link_text);

        return "[[$link|$link_text]]";
    }

    /**
     * Normalize a path by resolving ./ and ../ segments.
     *
     * @see http://php.net/manual/en/function.realpath.php
     */
    public function normalizePath(string $path): string
    {
        $parts = [];                                // Good path segments collected here.
        $path = str_replace('\\', '/', $path);      // Normalize backslashes to forward slashes.
        $path = preg_replace('/\/+/', '/', $path);  // Collapse repeated slashes.
        $segments = explode('/', $path);            // Split into segments.

        foreach ($segments as $segment) {
            if ($segment === '.') {
                continue;
            }

            $test = array_pop($parts);
            if (is_null($test)) {
                $parts[] = $segment;
            } elseif ($segment === '..') {
                if ($test === '..') {
                    $parts[] = $test;
                }
                if ($test === '..' || $test === '') {
                    $parts[] = $segment;
                }
            } else {
                $parts[] = $test;
                $parts[] = $segment;
            }
        }

        return implode('/', $parts);
    }
}
