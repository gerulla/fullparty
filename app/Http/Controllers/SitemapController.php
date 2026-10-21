<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ApplyLocale;
use App\Models\Activity;
use App\Services\Runs\RunAccessService;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __construct(private readonly RunAccessService $access) {}

    public function __invoke(): Response
    {
        $entries = [
            ...$this->staticEntries(),
            ...$this->activityEntries(),
        ];

        return response()
            ->view('sitemap', [
                'entries' => $entries,
            ])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function staticEntries(): array
    {
        return [
            $this->localizedEntry('home', ['locale' => null]),
            $this->localizedEntry('legal.privacy', ['locale' => null]),
            $this->localizedEntry('legal.cookies', ['locale' => null]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activityEntries(): array
    {
        return $this->access->scopeVisibleTo(Activity::query(), null)
            ->with('group:id,slug')
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'group_id', 'updated_at'])
            ->filter(fn (Activity $activity) => $activity->group !== null)
            ->map(fn (Activity $activity) => $this->localizedEntry(
                'groups.activities.overview',
                [
                    'locale' => null,
                    'group' => $activity->group,
                    'activity' => $activity,
                ],
                $activity->updated_at?->toAtomString(),
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $baseParameters
     * @return array<string, mixed>
     */
    private function localizedEntry(string $routeName, array $baseParameters, ?string $lastModified = null): array
    {
        $alternates = collect(ApplyLocale::SUPPORTED_LOCALES)
            ->mapWithKeys(function (string $locale) use ($routeName, $baseParameters): array {
                $parameters = [
                    ...$baseParameters,
                    'locale' => $locale,
                ];

                return [$locale => route($routeName, $parameters)];
            })
            ->all();

        return [
            'loc' => $alternates[config('app.locale')] ?? reset($alternates),
            'lastmod' => $lastModified,
            'alternates' => $alternates,
        ];
    }
}
