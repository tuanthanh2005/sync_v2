@if (! empty($seoSsr) && is_array($seoSsr))
<noscript>
    <main style="max-width: 820px; margin: 2rem auto; padding: 1.5rem; font-family: system-ui, -apple-system, sans-serif; line-height: 1.65;">
        @if (! empty($seoSsr['breadcrumbs']) && is_array($seoSsr['breadcrumbs']))
            <nav aria-label="{{ __('Breadcrumb') }}" style="font-size: 0.875rem; margin-bottom: 1rem;">
                @foreach ($seoSsr['breadcrumbs'] as $idx => $crumb)
                    @if ($idx > 0) / @endif
                    <a href="{{ $crumb['href'] }}">{{ $crumb['label'] }}</a>
                @endforeach
            </nav>
        @endif

        <article>
            @if (! empty($seoSsr['heading']))
                <h1>{{ $seoSsr['heading'] }}</h1>
            @endif

            @if (! empty($seoSsr['author']) || ! empty($seoSsr['published_at']))
                <p style="font-size: 0.9rem; color: #475569;">
                    @if (! empty($seoSsr['author']))
                        <span>{{ $seoSsr['author'] }}</span>
                    @endif
                    @if (! empty($seoSsr['published_at']))
                        · <time datetime="{{ $seoSsr['published_at'] }}">{{ $seoSsr['published_at'] }}</time>
                    @endif
                </p>
            @endif

            @if (! empty($seoSsr['description']))
                <p>{{ $seoSsr['description'] }}</p>
            @endif

            @if (! empty($seoSsr['body']))
                <div>{!! nl2br(e($seoSsr['body'])) !!}</div>
            @endif

            @if (! empty($seoSsr['images']) && is_array($seoSsr['images']))
                <div style="margin: 1rem 0;">
                    @foreach ($seoSsr['images'] as $img)
                        <img src="{{ $img['url'] }}" alt="{{ $img['alt'] ?? ($seoSsr['heading'] ?? 'Image') }}" @if (! empty($img['width'])) width="{{ $img['width'] }}" @endif @if (! empty($img['height'])) height="{{ $img['height'] }}" @endif loading="lazy" style="max-width: 100%; height: auto;">
                    @endforeach
                </div>
            @endif
        </article>

        @if (! empty($seoSsr['comments']) && is_array($seoSsr['comments']))
            <section aria-label="{{ __('Public Comments') }}" style="margin-top: 2rem;">
                <h2>{{ __('Comments') }}</h2>
                @foreach ($seoSsr['comments'] as $comment)
                    <div style="border-top: 1px solid #e2e8f0; padding: 0.75rem 0;">
                        <strong>{{ $comment['author'] }}</strong>
                        <p>{{ $comment['body'] }}</p>
                    </div>
                @endforeach
            </section>
        @endif

        @if (! empty($seoSsr['posts']) && is_array($seoSsr['posts']))
            <section aria-label="{{ __('Discussions') }}" style="margin-top: 2rem;">
                @foreach ($seoSsr['posts'] as $item)
                    <article style="border-top: 1px solid #e2e8f0; padding: 1rem 0;">
                        <h2><a href="{{ $item['href'] }}">{{ $item['title'] }}</a></h2>
                        @if (! empty($item['author']))
                            <p style="font-size: 0.85rem; color: #64748b;">{{ $item['author'] }}</p>
                        @endif
                        @if (! empty($item['excerpt']))
                            <p>{{ $item['excerpt'] }}</p>
                        @endif
                    </article>
                @endforeach
            </section>
        @endif

        @if (! empty($seoSsr['links']) && is_array($seoSsr['links']))
            <nav aria-label="{{ __('Related Links') }}" style="margin-top: 2rem; border-top: 1px solid #e2e8f0; padding-top: 1rem;">
                <h2>{{ __('Related') }}</h2>
                <ul>
                    @foreach ($seoSsr['links'] as $link)
                        <li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif

        @if (! empty($seoSsr['prev_url']) || ! empty($seoSsr['next_url']))
            <nav aria-label="{{ __('Pagination') }}" style="margin-top: 1.5rem; display: flex; gap: 1rem;">
                @if (! empty($seoSsr['prev_url']))
                    <a rel="prev" href="{{ $seoSsr['prev_url'] }}">{{ __('Previous Page') }}</a>
                @endif
                @if (! empty($seoSsr['next_url']))
                    <a rel="next" href="{{ $seoSsr['next_url'] }}">{{ __('Next Page') }}</a>
                @endif
            </nav>
        @endif
    </main>
</noscript>
@endif
