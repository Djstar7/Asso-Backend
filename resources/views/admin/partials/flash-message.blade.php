@php
    $flashLocale = auth()->user()?->preferredLocale() ?? 'fr';
    $flashType = session('error') ? 'error' : 'success';
    $flashMessage = session('error') ?: session('success');
@endphp
<div id="adminFlash" class="admin-flash" data-flash-type="{{ $flashType }}"
     role="{{ $flashType === 'error' ? 'alert' : 'status' }}" aria-live="{{ $flashType === 'error' ? 'assertive' : 'polite' }}"
     aria-atomic="true" @if(!$flashMessage) hidden @endif>
    <i data-flash-icon class="fas {{ $flashType === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' }}" aria-hidden="true"></i>
    <div data-flash-message>{{ $flashMessage }}</div>
    <button type="button" data-flash-dismiss aria-label="{{ __('admin_flash.close', [], $flashLocale) }}">&times;</button>
</div>
<style>
    .admin-flash { display:flex; align-items:center; gap:.625rem; margin-bottom:1.5rem; padding:.75rem 1rem; border-left:4px solid #22c55e; border-radius:.5rem; background:#f0fdf4; color:#15803d; box-shadow:0 1px 2px rgb(0 0 0 / 5%); font-size:1rem; line-height:1.5; }
    .admin-flash[hidden] { display:none !important; }
    .admin-flash[data-flash-type="error"] { border-color:#ef4444; background:#2c1414; color:#fca5a5; }
    .admin-flash [data-flash-message] { flex:1; min-width:0; overflow-wrap:anywhere; }
    .admin-flash [data-flash-dismiss] { flex-shrink:0; width:1.5rem; height:1.5rem; border:0; padding:0; background:transparent; color:inherit; cursor:pointer; font-size:1.5rem; line-height:1; }
    .admin-flash [data-flash-dismiss]:focus-visible { outline:2px solid currentColor; outline-offset:3px; }
</style>
<script src="{{ asset('js/admin-flash.js') }}"></script>
