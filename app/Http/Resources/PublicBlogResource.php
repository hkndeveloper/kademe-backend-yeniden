<?php

namespace App\Http\Resources;

use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicBlogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->excerpt,
            'excerpt' => $this->excerpt,
            'cover_image_path' => $this->cover_image_path,
            'cover_image' => $this->cover_image_path ? MediaStorage::url($this->cover_image_path) : null,
            'content' => $this->content,
            'status' => $this->status,
            'published_at' => optional($this->published_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'category' => $this->whenLoaded('category', function () {
                return $this->category ? [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                ] : null;
            }),
        ];
    }
}
