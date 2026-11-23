<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VotingSubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! isset($this->contestants_count, $this->votes_count)) {
            $this->resource->loadCount(['contestants', 'votes']);
        }
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $contestants = $this->resource->relationLoaded('contestants')
            ? $this->contestants
            : $this->contestants()->orderBy('display_order')->orderBy('id')
                ->get(['id', 'voting_subject_id', 'name', 'display_order', 'attendee_id']);

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'status' => $this->status,
            'mode' => $this->mode,
            'top_n' => $this->top_n,
            'nomination_topic_id' => $this->nomination_topic_id,
            'category' => $this->category,
            'generated_from_subject_id' => $this->generated_from_subject_id,
            'generated_subject' => $this->mode === 'nomination' && $this->generatedSubject
                ? (new self($this->generatedSubject))->resolve($request) : null,
            'contestant_count' => $this->contestants_count,
            'total_votes' => $this->votes_count,
            'contestants' => $contestants->map(fn ($contestant): array => $contestant->only(['id', 'name', 'display_order', 'attendee_id'])),
            'public_url' => $frontendUrl.$this->publicPath(),
        ];
    }

    private function publicPath(): string
    {
        if ($this->nomination_topic_id !== null) {
            return '/event-poll/'.$this->event->slug;
        }

        if ($this->mode === 'nomination') {
            return '/nomination/'.$this->slug;
        }

        return $this->generated_from_subject_id && $this->generatedFromSubject
            ? '/nomination/'.$this->generatedFromSubject->slug
            : '/vote/'.$this->slug;
    }
}
