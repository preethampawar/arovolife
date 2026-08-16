<?php

declare(strict_types=1);

/**
 * T-5.9 — the Phase-1 exit-gate snapshot command.
 *
 * PHS-001: it reports user stories, compliance items, the audit and the exit criteria
 * PHS-002: an unsigned compliance item is never reported as ready
 * PHS-003: it fails loudly when it cannot see the repository docs, rather than reporting nothing
 * PHS-004: only phase 1 has a specced exit gate
 */

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->docsRoot = sys_get_temp_dir().'/phase-status-'.uniqid();

    File::ensureDirectoryExists($this->docsRoot.'/docs/compliance');
    File::ensureDirectoryExists($this->docsRoot.'/docs/security');
    File::ensureDirectoryExists($this->docsRoot.'/docs/runbooks');
    File::ensureDirectoryExists($this->docsRoot.'/backlog');

    File::put($this->docsRoot.'/docs/roadmap.md', <<<'MD'
        # Roadmap

        ## Final sign-off gate ⏳

        | Item | Owner |
        |---|---|
        | T-6.1 security-auditor 10-point pass | security-auditor |
        | T-6.2 compliance-officer sign-off | compliance-officer |
        MD);

    File::put($this->docsRoot.'/backlog/phase-1-backlog.md', <<<'MD'
        ## Story-to-sprint map

        | Story | Sprint | Status |
        |---|---|---|
        | US-1.01 Invitation URL | 3 | ✅ |
        | US-1.06 Login + MFA | 2 | Login ✅. MFA deferred to Phase 12. |
        | US-1.99 Something unfinished | 9 | |
        MD);

    File::put($this->docsRoot.'/docs/compliance/risk-register.md', <<<'MD'
        | C-01 | Joining free of cost enforced in code | | | Test + PR link |
        | C-02 | No income projection in registration UI | compliance-officer | 2026-08-01 | Memo |
        MD);

    File::put($this->docsRoot.'/docs/security/audit-checklist.md', <<<'MD'
        # Checklist

        ## Audit run — 2026-06-17

        | # | Item | Verdict | Notes |
        |---|---|---|---|
        | 1 | Threat model | PASS | Fine. |
        | 2 | Authentication | **OPEN** | Not done. |
        MD);
});

afterEach(function (): void {
    File::deleteDirectory($this->docsRoot);
});

it('PHS-001: it reports user stories, compliance items, the audit and the exit criteria', function () {
    $this->artisan('phase:status', ['--docs' => $this->docsRoot])
        ->expectsOutputToContain('PHASE 1 EXIT-GATE SNAPSHOT')
        ->expectsOutputToContain('US-1.01')
        ->expectsOutputToContain('C-01')
        ->expectsOutputToContain('EXIT CRITERIA')
        ->expectsOutputToContain('NEXT UP')
        ->assertSuccessful();
});

it('PHS-002: an unsigned compliance item is never reported as ready', function () {
    // C-01 has no sign-off, so the verdict must not be READY FOR UAT however
    // green everything else looks. A status command that grades a sign-off it
    // has never seen lets a phase close on an unfinished checklist.
    $this->artisan('phase:status', ['--docs' => $this->docsRoot])
        ->expectsOutputToContain('UNSIGNED')
        ->expectsOutputToContain('NEEDS-A-HUMAN')
        ->expectsOutputToContain('STILL IN BUILD')
        ->assertSuccessful();
});

it('PHS-003: it fails loudly when it cannot see the repository docs', function () {
    // The Docker image mounts only the Laravel app, so this is the everyday
    // mistake. Reporting "not found" nine times would read as "nothing shipped".
    $this->artisan('phase:status', ['--docs' => '/nonexistent-checkout'])
        ->expectsOutputToContain('Cannot see the repository docs')
        ->assertFailed();
});

it('PHS-004: only phase 1 has a specced exit gate', function () {
    $this->artisan('phase:status', ['--phase' => 2, '--docs' => $this->docsRoot])
        ->assertFailed();
});
