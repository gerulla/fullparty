<?php

namespace App\Http\Resources\XivPlugin;

use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class XivPluginCharacterListResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  Collection<int, Character>  $resource
     * @param  Collection<int, CharacterClass>  $characterClasses
     * @param  Collection<int, PhantomJob>  $phantomJobs
     */
    public function __construct(
        $resource,
        private readonly Collection $characterClasses,
        private readonly Collection $phantomJobs,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->resource
                ->map(fn (Character $character): array => (new XivPluginCharacterResource(
                    $character,
                    $this->characterClasses,
                    $this->phantomJobs,
                ))->toArray($request))
                ->values()
                ->all(),
        ];
    }
}
