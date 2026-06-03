#!/bin/bash
# SPDX-FileCopyrightText: 2026 Out of Control, Inc.
# SPDX-License-Identifier: MIT

set -euo pipefail

# Build a multi-architecture image (amd64 + arm64) and push it to Docker Hub in
# one step.
#
# A multi-arch manifest cannot be loaded into the local Docker daemon, it only
# exists once pushed to a registry, so build and push are necessarily combined
# here (unlike the local-only build.sh).
#
# Requires Docker Buildx (bundled with current Docker Desktop / Docker Engine)
# and a logged-in registry session (docker login).
#
# Usage:
#   docker/release.sh                 # pushes :latest and :1.0.1 as oooc/...
#   VERSION=1.1.0 docker/release.sh   # override the version tag
#   DOCKER_USERNAME=me docker/release.sh
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/.." && pwd)"

DOCKER_USERNAME="${DOCKER_USERNAME:-oooc}"
VERSION="${VERSION:-1.0.1}"
PLATFORMS="${PLATFORMS:-linux/amd64,linux/arm64}"
IMAGE="${DOCKER_USERNAME}/mediawiki-to-gfm"

cd "${repo_root}"
docker buildx build \
    --platform "${PLATFORMS}" \
    -f docker/Dockerfile \
    -t "${IMAGE}:latest" \
    -t "${IMAGE}:${VERSION}" \
    --push \
    .
