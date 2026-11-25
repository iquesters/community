{{-- Record section: proof files. Click opens them in the browser (images, PDF); the icon downloads. --}}
@if ($files->isNotEmpty())
    <h6 class="fs-6 mb-2">Proof files</h6>
    <div class="d-flex flex-wrap gap-3">
        @foreach ($files as $proof)
            @php($url = route('community.proofs.download', $proof->uid))
            <div class="border rounded p-2 small" style="width: 11rem;">
                <a href="{{ $url }}" target="_blank" rel="noopener" class="d-block text-decoration-none" title="Open {{ $proof->original_name }}">
                    @if (str_starts_with((string) $proof->mime_type, 'image/'))
                        <img src="{{ $url }}" alt="{{ $proof->label }}" class="w-100 rounded border bg-body-tertiary" style="height: 7rem; object-fit: contain;" loading="lazy">
                    @else
                        <span class="d-flex align-items-center justify-content-center rounded border bg-body-tertiary text-danger" style="height: 7rem;">
                            <i class="fas fa-file-pdf fa-3x" aria-hidden="true"></i>
                        </span>
                    @endif
                </a>
                <div class="text-muted mt-2 text-truncate" title="{{ $proof->label }}">{{ $proof->label }}</div>
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <a href="{{ $url }}" target="_blank" rel="noopener" class="text-truncate">{{ $proof->original_name }}</a>
                    <a href="{{ $url }}?download=1" title="Download" class="text-muted"><i class="fas fa-download" aria-hidden="true"></i></a>
                </div>
            </div>
        @endforeach
    </div>
@endif
