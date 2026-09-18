<div data-xivgear-summary>
    @foreach ($snapshots as $snapshot)
        <section>
            <strong>{{ $snapshot->name }}</strong>
            <p>{{ $snapshot->description }}</p>
            <p>{{ $snapshot->job }} · {{ __('xivgear.ilevel', ['value' => $snapshot->itemLevel]) }}</p>
            <ul>
                @foreach ($snapshot->items as $item)
                    <li>{{ __('xivgear.slots.'.$item->slot) }}: {{ $item->names->{app()->getLocale()} ?? $item->names->en }}</li>
                @endforeach
            </ul>
            <a href="{{ $snapshot->sourceUrl }}" rel="nofollow noopener noreferrer">{{ __('xivgear.open_source') }}</a>
        </section>
    @endforeach
</div>
