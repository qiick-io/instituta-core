<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Serialize an activity log entry for the Activity UI.
 *
 * Only public UUIDs cross the resource boundary; database keys never do.
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
            'id' => $this->public_id,
            'organisation_id' => $this->organisation_id,
            'event' => $this->event,
            'description' => $this->description,
            'log_name' => $this->log_name,
            'created_at' => $this->created_at?->toIso8601String(),
            'causer' => $this->whenLoaded('causer', fn () => $this->causer instanceof User ? [
                'id' => $this->publicIdFor($this->causer),
                'name' => $this->causer->trashed() ? 'Deleted user' : $this->causer->name,
                'email' => $this->causer->trashed() ? null : $this->causer->email,
            ] : null),
            'subject' => $this->whenLoaded('subject', fn () => $this->subject !== null ? [
                'type' => class_basename((string) $this->subject_type),
                'id' => $this->publicIdFor($this->subject),
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

        return $this->publicIdFor($subject) ?? 'record';
    }

    /**
     * A public UUID from the model's public_id or from a UUID primary key.
     */
    private function publicIdFor(Model $subject): ?string
    {
        if (array_key_exists('public_id', $subject->getAttributes())) {
            $publicId = $subject->getAttribute('public_id');

            if (is_string($publicId) && Str::isUuid($publicId)) {
                return $publicId;
            }
        }

        $databaseKey = $subject->getKey();

        return is_string($databaseKey) && Str::isUuid($databaseKey) ? $databaseKey : null;
    }
}
