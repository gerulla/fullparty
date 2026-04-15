<?php

namespace App\Http\Middleware;

use App\Models\Group;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
		return array_merge(parent::share($request), [
			'flash' => [
				'success' => fn () => $request->session()->get('success'),
				'error' => fn () => $request->session()->get('error'),
				'data' => fn () => $request->session()->get('flash_data', []),],
			'auth' => [
				'user' => fn () => $request->user()
					? array_merge(
						$request->user()->load(['primaryCharacter', 'socialAccounts'])->toArray(),
						[
							'primary_character' => $request->user()->primaryCharacter,
							'social_accounts' => $request->user()->socialAccounts,
						]
					)
					: null,
			],
			'navigation' => [
				'group_quick_links' => fn () => $request->user()
					? [
						'owned' => $this->serializeGroupQuickLinks(
							$request->user()->ownedGroups()->get(['id', 'name', 'slug'])
						),
						'moderated' => $this->serializeGroupQuickLinks(
							$request->user()->moderatedGroups()->get(['groups.id', 'groups.name', 'groups.slug'])
						),
						'member' => $this->serializeGroupQuickLinks(
							$request->user()->memberGroups()->get(['groups.id', 'groups.name', 'groups.slug'])
						),
					]
					: [
						'owned' => [],
						'moderated' => [],
						'member' => [],
					],
			],
			'lookups' => [
				'datacenters' => fn () => collect(config('datacenters.values', []))
					->map(fn (string $value) => [
						'label' => $value,
						'value' => $value,
					])
					->values()
					->all(),
			],
            'locale' => [
                'current' => fn () => app()->getLocale(),
                'fallback' => fn () => config('app.fallback_locale'),
                'available' => fn () => \App\Http\Middleware\ApplyLocale::SUPPORTED_LOCALES,
            ],
		]);
    }

	/**
	 * @param \Illuminate\Support\Collection<int, Group> $groups
	 * @return array<int, array<string, string|int>>
	 */
	private function serializeGroupQuickLinks($groups): array
	{
		return $groups
			->sortBy('name')
			->values()
			->map(fn (Group $group) => [
				'id' => $group->id,
				'name' => $group->name,
				'slug' => $group->slug,
				'href' => route('groups.dashboard', $group, false),
			])
			->all();
	}
}
