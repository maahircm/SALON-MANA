@props(['paginator'])
@if ($paginator->hasPages())<div class="d-flex justify-content-between align-items-center p-3 border-top no-print">
    <small class="text-muted">Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</small>{{ $paginator->links() }}
</div>@endif
