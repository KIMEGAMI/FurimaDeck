<urlset
    xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
>
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
        <lastmod>{{ $url['lastmod'] }}</lastmod>
        <changefreq>{{ $url['changefreq'] }}</changefreq>
        <priority>{{ $url['priority'] }}</priority>
        @if (! empty($url['image']))
            <image:image>
                <image:loc>{{ $url['image'] }}</image:loc>
                @if (! empty($url['image_title']))
                    <image:title>{{ $url['image_title'] }}</image:title>
                @endif
            </image:image>
        @endif
    </url>
@endforeach
</urlset>
