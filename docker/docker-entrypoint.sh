#!/bin/sh
# SPDX-FileCopyrightText: 2026 Out of Control, Inc.
# SPDX-License-Identifier: MIT

set -e

# Entrypoint for the mediawiki-to-gfm image.
#
# Usage:
#   docker run -v "$PWD:/app" mediawiki-to-gfm --filename=export.xml
#
# The user's current directory is mounted at /app. Converted files are always
# written to /app/output, so callers do not pass --output themselves (and the
# container cannot see anything outside the mounted directory anyway).

# Help and version are informational; run them without forcing an output dir.
case "$1" in
    --help|-h|"")
        exec convert.php --help
        ;;
    --version|-v)
        exec convert.php --version
        ;;
esac

mkdir -p /app/output
exec convert.php "$@" --output=/app/output
