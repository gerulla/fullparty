<?php

namespace App\Http\Resources\Moderation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportFeedbackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'template' => $this->feedback->template, 'audience' => $this->feedback->audience,
            'message' => $this->feedback->message, 'item_title' => $this->feedback->item_title,
            'created_at' => $this->feedback->created_at->toIso8601String()];
    }
}
