<?php

declare(strict_types=1);

/**
 * Guards the working-contract status banners in FORMAT.md and CONTRACTS.md (#50).
 */
it('marks FORMAT.md and CONTRACTS.md as the approved working contract', function (): void {
    $format = file_get_contents(base_path('docs/FORMAT.md'));
    $contracts = file_get_contents(base_path('docs/CONTRACTS.md'));

    expect($format)->not->toBeFalse();
    expect($contracts)->not->toBeFalse();

    expect($format)->not->toContain('Draft, pending maintainer review');
    expect($contracts)->not->toContain('Draft, pending maintainer review');

    expect($format)->toContain('Working contract (approved 2026-11-13)');
    expect($contracts)->toContain('Working contract (approved 2026-11-13)');

    expect($format)->toContain('area:contract');
    expect($contracts)->toContain('area:contract');

    expect($format)->toContain('v1.0.0-beta.1');
    expect($contracts)->toContain('v1.0.0-beta.1');
});
