<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;

final class XivGearSnapshot
{
    public const SLOTS = ['Weapon', 'OffHand', 'Head', 'Body', 'Hand', 'Legs', 'Feet', 'Ears', 'Neck', 'Wrist', 'RingLeft', 'RingRight'];

    public const STATS = ['hp', 'strength', 'dexterity', 'intelligence', 'mind', 'vitality', 'crit', 'dhit', 'determination', 'skillspeed', 'spellspeed', 'piety', 'tenacity'];

    public static function validUrl(mixed $url): bool
    {
        if (! is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https' && in_array(strtolower($parts['host'] ?? ''), ['xivgear.app', 'www.xivgear.app', 'share.xivgear.app'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']);
    }

    public static function valid(mixed $snapshot): bool
    {
        if (! is_array($snapshot) || strlen(json_encode($snapshot)) > 100000) {
            return false;
        }
        $rules = [
            'version' => ['required', 'integer', 'in:1'],
            'sourceUrl' => ['required', fn ($attribute, $value, $fail) => self::validUrl($value) ?: $fail('Invalid URL')],
            'importedAt' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'name' => ['required', 'string', 'max:500'], 'description' => ['present', 'string', 'max:5000'],
            'job' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'], 'level' => ['required', 'integer', 'between:1,200'],
            'partyBonus' => ['required', 'integer', 'between:0,5'], 'itemLevel' => ['required', 'integer', 'between:0,2000'],
            'itemLevelSync' => ['present', 'nullable', 'integer', 'between:1,2000'],
            'gcd' => ['present', 'nullable', 'numeric', 'between:0.1,5'],
            'stats' => ['required', 'array:'.implode(',', self::STATS)],
            'stats.*' => ['integer', 'between:0,10000000'],
            'items' => ['required', 'array', 'min:1', 'max:12'],
            'items.*' => ['array:slot,id,names,icon,itemLevel,materia,relicStats'],
            'items.*.slot' => ['required', 'distinct', 'in:'.implode(',', self::SLOTS)],
            'items.*.materia' => ['present', 'array', 'max:5'],
            'items.*.materia.*' => ['array:id,names,icon,itemLevel'],
            'items.*.relicStats' => ['present', 'array:'.implode(',', self::STATS)],
            'items.*.relicStats.*' => ['integer', 'between:0,10000'],
            'food' => ['present', 'nullable', 'array:id,names,icon,itemLevel'],
        ];
        foreach (['items.*', 'items.*.materia.*', 'food'] as $prefix) {
            foreach (['id' => ['integer', 'between:1,1000000'], 'names' => ['array:en,de,fr,ja'], 'icon' => ['nullable', 'integer', 'between:1,999999'], 'itemLevel' => ['integer', 'between:0,2000']] as $key => $rule) {
                $rules["$prefix.$key"] = [$key === 'icon' ? ($prefix === 'food' ? 'sometimes' : 'present') : ($prefix === 'food' ? 'required_with:food' : 'required'), ...$rule];
            }
            foreach (['en', 'de', 'fr', 'ja'] as $locale) {
                $rules["$prefix.names.$locale"] = [$prefix === 'food' ? 'required_with:food' : 'required', 'string', 'max:500'];
            }
        }

        return ! array_diff(array_keys($snapshot), ['version', 'sourceUrl', 'importedAt', 'name', 'description', 'job', 'level', 'partyBonus', 'itemLevel', 'itemLevelSync', 'gcd', 'stats', 'items', 'food'])
            && Validator::make($snapshot, $rules)->passes();
    }
}
