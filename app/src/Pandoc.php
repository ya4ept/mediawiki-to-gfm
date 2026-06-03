<?php
// SPDX-FileCopyrightText: 2023 Ryan Kadwell
// SPDX-FileCopyrightText: 2026 Out of Control, Inc.
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace App;

/**
 * Minimal wrapper around the Pandoc command-line binary.
 *
 * This is a trimmed, modernized rewrite of ryakad/pandoc-php
 * (MIT, Copyright (c) 2013 Ryan Kadwell), which is no longer maintained.
 * It keeps only what this project needs: run a text-in/text-out conversion and
 * report the binary's version. Content is passed via a temporary file and the
 * converted result is read from Pandoc's standard output.
 */
class Pandoc
{
    /** Absolute path to the pandoc executable. */
    private string $executable;

    /** Temporary file used to hand input content to pandoc. */
    private string $tmpFile;

    /**
     * @param string|null $executable Path to the pandoc binary; located on PATH when null.
     * @param string|null $tmpDir Directory for the temporary input file; system temp when null.
     *
     * @throws PandocException When pandoc cannot be located or the temp dir is unusable.
     */
    public function __construct(?string $executable = null, ?string $tmpDir = null)
    {
        $tmpDir ??= sys_get_temp_dir();

        if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
            throw new PandocException(sprintf('The directory %s is not writable.', $tmpDir));
        }

        $this->tmpFile = tempnam($tmpDir, 'pandoc');

        $this->executable = $executable ?? $this->locateExecutable();

        if (!is_executable($this->executable)) {
            throw new PandocException(sprintf('Pandoc executable is not executable: %s', $this->executable));
        }
    }

    /**
     * Convert content using the given Pandoc options (e.g. ['from' => 'mediawiki', 'to' => 'gfm']).
     *
     * @param array<string, string|null> $options Long options; a null value renders as a bare flag.
     *
     * @throws PandocException When the conversion exits non-zero.
     */
    public function runWith(string $content, array $options): string
    {
        file_put_contents($this->tmpFile, $content);

        $arguments = [];
        foreach ($options as $key => $value) {
            $arguments[] = $value === null ? "--{$key}" : "--{$key}=" . escapeshellarg($value);
        }

        $command = sprintf(
            '%s %s %s',
            escapeshellarg($this->executable),
            implode(' ', $arguments),
            escapeshellarg($this->tmpFile)
        );

        exec($command, $output, $returnValue);

        if ($returnValue !== 0) {
            throw new PandocException(sprintf(
                'Pandoc could not convert successfully, exit code %d. Command: %s',
                $returnValue,
                $command
            ));
        }

        return implode("\n", $output);
    }

    /**
     * Return the pandoc version string (e.g. "3.9").
     */
    public function getVersion(): string
    {
        exec(escapeshellarg($this->executable) . ' --version', $output);

        return trim(str_replace('pandoc', '', $output[0] ?? ''));
    }

    /**
     * Remove the temporary file (and any siblings pandoc may have produced).
     */
    public function __destruct()
    {
        foreach (glob($this->tmpFile . '*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Find the pandoc binary on PATH.
     *
     * @throws PandocException When pandoc is not found.
     */
    private function locateExecutable(): string
    {
        exec('command -v pandoc', $output, $returnValue);

        if ($returnValue !== 0 || empty($output[0])) {
            throw new PandocException('Unable to locate the pandoc executable on PATH.');
        }

        return $output[0];
    }
}
