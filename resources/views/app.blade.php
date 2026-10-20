<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="theme-color" content="#101d2e">
  <title>{{ $seo["title"] ?? "FastFind — Your content, beautifully organized" }}</title>
  <meta name="description" content="{{ $seo["description"] ?? "FastFind — discover and publish what matters." }}">
  @vite(['resources/js/app.js', 'resources/scss/app.scss'])
</head>
<body>
  <div id="app"><div class="initial-load">Loading FastFind…</div></div>
</body>
</html>
