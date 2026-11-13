<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialize an activity log entry for the Activity UI.
 *
 * @mixin Activity
 */
class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $properties = $this->properties?->toArray() ?? [];
        $meta = collect($properties)
            ->except(['ip', 'user_agent', 'organisation_id'])
            ->all();

        return [
            'id' => $this->id,
            'organisation_id' => $this->organisation_id,
            'event' => $this->event,
            'description' => $this->description,
            'log_name' => $this->log_name,
            'created_at' => $this->created_at?->toIso8601String(),
            'causer' => $this->whenLoaded('causer', fn () => $this->causer instanceof User ? [
                'id' => $this->causer->id,
                'name' => $this->causer->name,
                'email' => $this->causer->email,
            ] : null),
            'subject' => $this->whenLoaded('subject', fn () => $this->subject !== null ? [
                'type' => class_basename((string) $this->subject_type),
                'id' => $this->subject_id,
                'label' => $this->resolveSubjectLabel($this->subject),
            ] : null),
            'changes' => $this->attribute_changes?->toArray() ?? [],
            'properties' => [
                'ip' => $properties['ip'] ?? null,
                'user_agent' => $properties['user_agent'] ?? null,
                'meta' => $meta,
            ],
        ];
    }

    protected function resolveSubjectLabel(Model $subject): string
    {
        if ($subject instanceof User) {
            return $subject->name;
        }

        if (isset($subject->name) && is_string($subject->name) && trim($subject->name) !== '') {
            return trim($subject->name);
        }

        if (isset($subject->title) && is_string($subject->title) && trim($subject->title) !== '') {
            return trim($subject->title);
        }

        return '#'.$subject->getKey();
    }
}
