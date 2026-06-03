#!/bin/bash
# SPDX-FileCopyrightText: 2026 Out of Control, Inc.
# SPDX-License-Identifier: MIT

set -euo pipefail

# Local single-architecture build for development and testing.
#
# This builds an image for the HOST architecture only and loads it into the
# local Docker daemon so you can run it immediately:
#
#   docker/build.sh
#   docker run -v "$PWD:/app" mediawiki-to-gfm --filename=export.xml
#
# To build and publish a multi-architecture image to a registry, use release.sh
# instead (a multi-arch manifest cannot be loaded into the local daemon, only
# pushed).
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/.." && pwd)"

cd "${repo_root}"
DOCKER_BUILDKIT=1 docker image build \
    -t mediawiki-to-gfm \
    -f docker/Dockerfile \
    .
