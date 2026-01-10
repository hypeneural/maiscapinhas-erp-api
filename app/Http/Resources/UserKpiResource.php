<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array $resource
 */
class UserKpiResource extends JsonResource
{
    /**
     * Disable wrapping for this resource.
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'filters' => $this->resource['filters'],
            'totals' => $this->resource['totals'],
            'age' => $this->resource['age'],
            'tenure' => $this->resource['tenure'],
            'distribution' => $this->resource['distribution'],
        ];
    }
}
