#!/usr/bin/env bash
# Re-vendors the conformance vectors from email-guard-spec at the pinned tag.
set -euo pipefail

SPEC_TAG="${1:-v1.0.0}"
BASE="https://raw.githubusercontent.com/Emailsherlock1/email-guard-spec/${SPEC_TAG}/vectors"
DIR="$(cd "$(dirname "$0")/vectors" && pwd)"

for f in syntax reserved-tld disposable decision api-mapping fail-open schema; do
  curl -fsSL "${BASE}/${f}.json" -o "${DIR}/${f}.json"
done
curl -fsSL "${BASE}/fixtures/disposable-snapshot.json" -o "${DIR}/fixtures/disposable-snapshot.json"

echo "Vectors synced from email-guard-spec ${SPEC_TAG}"
