# Phase 10: CI Pipeline - Context

**Gathered:** 2026-01-29
**Status:** Ready for planning

<domain>
## Phase Boundary

GitHub Actions workflow that runs all test tiers (unit, integration, E2E) automatically on PRs to main and pushes to main. Both jobs must pass for PR mergeability. Uses Docker Compose to stand up the labki-platform environment.

</domain>

<decisions>
## Implementation Decisions

### Workflow structure
- Single workflow file at `.github/workflows/ci.yml`
- Triggers on PRs to main and pushes to main
- Cancel in-progress runs when new commits push to the same PR (concurrency groups)
- Runner: `ubuntu-latest`

### Docker environment
- Reuse the existing `docker-compose.yml` in the repo root (already mounts extension, test configs, Apache config)
- Health check strategy and whether a CI-specific override file is needed: Claude's discretion
- Environment includes wiki (labki-platform) + MariaDB, with the extension volume-mounted

### Failure behavior
- Infrastructure failure (Docker won't start): retry once, then fail
- Console output only for test results — no JUnit XML artifact uploads
- GitHub Actions annotations to highlight failed tests in the PR (summary annotation)
- GitHub UI only for notifications — no Slack or email

### Caching & speed
- Cache Docker layers between CI runs to speed up environment startup
- Hard timeout: 15 minutes for the overall workflow
- Cancel stale runs on new commits to same PR

### Claude's Discretion
- Job structure: single workflow with parallel vs sequential jobs (Claude picks based on test dependencies)
- Merge gate configuration: which jobs are required status checks
- Health check approach: HTTP polling vs Docker healthcheck directive
- Whether a `docker-compose.ci.yml` override is needed or the existing file suffices
- Exact Docker layer caching strategy (GitHub Actions cache vs registry cache)

</decisions>

<specifics>
## Specific Ideas

- Existing `docker-compose.yml` already sets up the full environment with wiki + DB, extension volume mount, test LocalSettings, and Apache config
- labki-platform image is at `ghcr.io/labki-org/labki-platform:latest`
- Test LocalSettings at `tests/LocalSettings.test.php` and Apache config at `tests/apache-filepermissions.conf` are already mounted

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 10-ci-pipeline*
*Context gathered: 2026-01-29*
