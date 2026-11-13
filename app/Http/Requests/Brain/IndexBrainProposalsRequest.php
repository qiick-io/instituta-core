<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Enums\ProposalStatusEnum;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Trust-boundary validation for the brain proposals list status filter.
 *
 * Unknown or missing `status` falls back to open (same as the previous
 * controller inline check) so bookmarkable URLs stay usable.
 */
final class IndexBrainProposalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Resolved list status: open, accepted, or rejected (default open).
     */
    public function statusFilter(): ProposalStatusEnum
    {
        $statusFilter = $this->string('status')->toString();

        if (in_array($statusFilter, ['open', 'accepted', 'rejected'], true)) {
            return ProposalStatusEnum::from($statusFilter);
        }

        return ProposalStatusEnum::Open;
    }
}
