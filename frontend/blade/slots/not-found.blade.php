{{--
    Suggestions on the shop's 404 page.

    Rendered through `<x-plugin-slot name="not-found" />`, which the active theme has to call —
    core defines the hook but every pixel of the storefront lives in a theme template, so there
    is no position core can occupy on its own. A theme that does not call it renders nothing,
    which is why the setting behind this is off by default.

    Opting in is shipping this file. There is nothing to register, and a disabled or blocked
    plugin is absent from the storefront manifest, so its view namespace is never registered
    and the region renders empty. The 404 page still serves either way.

    `$slotData` carries whatever the calling page knew. `path` is the one thing needed here; a
    theme that passes nothing still renders safely, it just has nothing to suggest.
--}}
@php
    use Plugin\RedirectManager\Backend\Models\RedirectSetting;
    use Plugin\RedirectManager\Backend\Services\PathSuggester;

    $suggestions = [];

    try {
        if (RedirectSetting::current()->storefront_suggestions) {
            $suggestions = app(PathSuggester::class)->suggest($slotData['path'] ?? request()->path(), 3);
        }
    } catch (\Throwable $e) {
        // A 404 page that fails to render is a 500, and a visitor who mistyped a URL should
        // never see one. Nothing to suggest is the correct outcome of anything going wrong.
        report($e);
    }
@endphp

@if (! empty($suggestions))
    <div class="redirect-manager-suggestions">
        <p>Were you looking for one of these?</p>

        <ul>
            @foreach ($suggestions as $suggestion)
                <li>
                    <a href="{{ url('/' . $suggestion['path']) }}">{{ $suggestion['label'] }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
