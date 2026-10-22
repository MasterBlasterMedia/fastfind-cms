<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="theme-color" content="#101d2e">
  <title>{{ $head['title'] ?? $seo["title"] ?? "FastFind — Your content, beautifully organized" }}</title>
  <meta name="description" content="{{ $head['description'] ?? $seo["description"] ?? "FastFind — discover and publish what matters." }}">
  @if(!empty($head['canonical']))<link rel="canonical" href="{{ $head['canonical'] }}">@endif
  @foreach(($head['meta'] ?? []) as $name => $value)
    @if(str_starts_with($name, 'og:'))<meta property="{{ $name }}" content="{{ $value }}">
    @else<meta name="{{ $name }}" content="{{ $value }}">@endif
  @endforeach
  @if(!empty($head['structured_data']))
    <script type="application/ld+json">{!! json_encode($head['structured_data'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
  @endif
  @vite(['resources/js/app.js', 'resources/scss/app.scss'])
</head>
<body>
  <div id="app"><div class="initial-load">Loading FastFind…</div></div>
</body>
</html>
