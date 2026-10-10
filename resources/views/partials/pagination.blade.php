@if ($paginator->hasPages())
<nav class="pagination">
  @if ($paginator->onFirstPage())<span class="disabled">‹ Prev</span>@else<a href="{{ $paginator->previousPageUrl() }}">‹ Prev</a>@endif
  @foreach ($elements as $element)
    @if (is_string($element))<span class="disabled">{{ $element }}</span>@endif
    @if (is_array($element))
      @foreach ($element as $page => $url)
        @if ($page == $paginator->currentPage())<span class="active">{{ $page }}</span>@else<a href="{{ $url }}">{{ $page }}</a>@endif
      @endforeach
    @endif
  @endforeach
  @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">Next ›</a>@else<span class="disabled">Next ›</span>@endif
</nav>
@endif
