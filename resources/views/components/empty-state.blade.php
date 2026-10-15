@props(['icon' => 'bi-inbox', 'title' => 'Nothing here yet'])
<div class="empty-state"><i class="bi {{ $icon }}"></i><h2 class="h6 mt-2">{{ $title }}</h2><div>{{ $slot }}</div></div>
