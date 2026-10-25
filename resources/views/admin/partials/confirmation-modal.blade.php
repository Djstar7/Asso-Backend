@php($confirmationLocale = auth()->user()->preferredLocale())
<dialog id="confirmModal" class="asso-confirmation"
        aria-modal="true" aria-labelledby="confirmModalTitle" aria-describedby="confirmModalMessage"
        data-default-title="{{ __('admin_confirmation.title', [], $confirmationLocale) }}"
        data-default-message="{{ __('admin_confirmation.message', [], $confirmationLocale) }}"
        data-default-accept="{{ __('admin_confirmation.accept', [], $confirmationLocale) }}">
    <div id="confirmModalBox" class="bg-dark-100 border border-dark-200 rounded-2xl shadow-2xl p-6">
        <div class="flex items-start gap-4">
            <div class="w-12 h-12 rounded-full bg-primary-500/15 text-primary-400 flex items-center justify-center flex-shrink-0" aria-hidden="true">
                <i class="fas fa-triangle-exclamation text-xl"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h3 id="confirmModalTitle" class="text-lg font-bold text-white mb-1">{{ __('admin_confirmation.title', [], $confirmationLocale) }}</h3>
                <p id="confirmModalMessage" class="text-sm text-gray-300 leading-relaxed whitespace-pre-line break-words"></p>
            </div>
        </div>
        <div class="flex flex-wrap justify-end gap-3 mt-6">
            <button type="button" id="confirmModalCancel" autofocus
                    class="px-4 py-2.5 rounded-lg bg-dark-200 text-gray-200 hover:bg-dark-300 transition-colors font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-400">
                {{ __('admin_confirmation.cancel', [], $confirmationLocale) }}
            </button>
            <button type="button" id="confirmModalOk"
                    class="px-5 py-2.5 rounded-lg bg-primary-600 text-white hover:bg-primary-700 transition-colors font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-400">
                {{ __('admin_confirmation.accept', [], $confirmationLocale) }}
            </button>
        </div>
    </div>
</dialog>
<style>
    .asso-confirmation { padding: 0; border: 0; margin: auto; width: calc(100% - 2rem); max-width: 28rem; max-height: calc(100dvh - 2rem); overflow-y: auto; background: transparent; color: #f4f4f5; }
    .asso-confirmation:not([open]) { display: none; }
    .asso-confirmation::backdrop { background: rgb(0 0 0 / 65%); backdrop-filter: blur(4px); }
</style>
<script src="{{ asset('js/admin-confirmation.js') }}"></script>
