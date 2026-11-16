<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown while materialising when the file, or the section a proposal edits, no longer matches
 * the version its author read. The proposal ends in `conflict` instead of overwriting (#198).
 */
final class ProposalConflictException extends RuntimeException {}
