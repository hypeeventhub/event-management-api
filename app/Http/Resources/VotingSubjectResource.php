<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VotingSubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadCount(['contestants', 'votes']);
        $contestants = $this->contestants()->orderBy('display_order')->orderBy('id')
            ->get(['id', 'voting_subject_id', 'name', 'display_order', 'attendee_id'])
            ->map(fn ($contestant): array => $contestant->only(['id', 'name', 'display_order', 'attendee_id']));

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'status' => $this->status,
            'mode' => $this->mode,
            'top_n' => $this->top_n,
            'generated_from_subject_id' => $this->generated_from_subject_id,
            'generated_subject' => $this->mode === 'nomination' && $this->generatedSubject
                ? (new self($this->generatedSubject))->resolve($request) : null,
            'contestant_count' => $this->contestants_count,
            'total_votes' => $this->votes_count,
            'contestants' => $contestants,
            'public_url' => rtrim((string) config('app.frontend_url'), '/')
                .($this->mode === 'nomination' ? '/nomination/' : '/vote/')
                .$this->slug,
        ];
    }
}
