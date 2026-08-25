@extends('admin.layouts.app')

@section('title', 'Messagerie Support')
@section('header', 'Messagerie Support')

@section('content')
<div class="space-y-4">
    @if (session('success'))
        <div class="bg-green-500/10 border border-green-500/30 text-green-300 px-4 py-3 rounded-lg">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    @if (!$support)
        <div class="bg-yellow-500/10 border border-yellow-500/30 text-yellow-300 px-4 py-4 rounded-lg">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            Le compte support n'est pas configuré. Exécutez le seeder
            <code class="px-1 bg-dark-200 rounded">SupportUserSeeder</code> ou définissez le setting
            <code class="px-1 bg-dark-200 rounded">support_user_id</code>.
        </div>
    @else
    <div class="bg-dark-100 rounded-xl border border-dark-200 overflow-hidden"
         style="height: calc(100vh - 180px);">
        <div class="flex h-full">
            <!-- Colonne gauche : liste des conversations -->
            <aside class="w-full md:w-80 lg:w-96 border-r border-dark-200 flex flex-col {{ $selected ? 'hidden md:flex' : 'flex' }}">
                <div class="px-4 py-4 border-b border-dark-200 flex items-center justify-between">
                    <div>
                        <h3 class="text-white font-semibold">Conversations</h3>
                        <p class="text-xs text-gray-500">{{ $conversations->count() }} discussion(s)</p>
                    </div>
                    <span class="w-9 h-9 rounded-full bg-primary-500/15 text-primary-400 flex items-center justify-center">
                        <i class="fas fa-headset"></i>
                    </span>
                </div>

                <div class="flex-1 overflow-y-auto">
                    @forelse ($conversations as $conv)
                        @php $other = $conv->other_user; @endphp
                        <a href="{{ route('admin.messages.show', $conv->id) }}"
                           class="flex items-center gap-3 px-4 py-3 border-b border-dark-200/60 transition-colors
                                  {{ $selected && $selected->id === $conv->id ? 'bg-dark-200' : 'hover:bg-dark-200/60' }}">
                            <div class="relative shrink-0">
                                <div class="w-11 h-11 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 text-white flex items-center justify-center font-semibold uppercase">
                                    {{ mb_substr($other->name ?? '?', 0, 1) }}
                                </div>
                                @if ($conv->unread_count > 0)
                                    <span class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-green-500 text-white text-[10px] font-bold flex items-center justify-center">
                                        {{ $conv->unread_count > 99 ? '99+' : $conv->unread_count }}
                                    </span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between">
                                    <p class="text-sm font-medium text-white truncate">{{ $other->name ?? 'Client' }}</p>
                                    <span class="text-[11px] text-gray-500 shrink-0 ml-2">
                                        {{ optional($conv->last_message_at ?? $conv->created_at)->diffForHumans(null, true) }}
                                    </span>
                                </div>
                                <p class="text-xs truncate {{ $conv->unread_count > 0 ? 'text-gray-200 font-medium' : 'text-gray-500' }}">
                                    @if ($conv->latestMessage)
                                        @if ($conv->latestMessage->sender_id === $support->id)
                                            <i class="fas fa-reply text-[10px] mr-1"></i>
                                        @endif
                                        {{ \Illuminate\Support\Str::limit($conv->latestMessage->message ?? '📷 Image', 42) }}
                                    @else
                                        <span class="italic">Aucun message</span>
                                    @endif
                                </p>
                            </div>
                        </a>
                    @empty
                        <div class="px-4 py-10 text-center text-gray-500">
                            <i class="fas fa-inbox text-3xl mb-3"></i>
                            <p class="text-sm">Aucune conversation support pour le moment.</p>
                        </div>
                    @endforelse
                </div>
            </aside>

            <!-- Colonne droite : fil de discussion -->
            <section class="flex-1 flex flex-col {{ $selected ? 'flex' : 'hidden md:flex' }}">
                @if (!$selected)
                    <div class="flex-1 flex flex-col items-center justify-center text-gray-500">
                        <i class="fab fa-whatsapp text-6xl mb-4 opacity-40"></i>
                        <p>Sélectionnez une conversation pour afficher les messages.</p>
                    </div>
                @else
                    <!-- En-tête du fil -->
                    <div class="px-4 py-3 border-b border-dark-200 flex items-center gap-3">
                        <a href="{{ route('admin.messages.index') }}" class="md:hidden text-gray-400 hover:text-white">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 text-white flex items-center justify-center font-semibold uppercase">
                            {{ mb_substr($client->name ?? '?', 0, 1) }}
                        </div>
                        <div class="min-w-0">
                            <p class="text-white font-medium truncate">{{ $client->name ?? 'Client' }}</p>
                            <p class="text-xs text-gray-500 truncate">{{ $client->phone ?? '' }}</p>
                        </div>
                    </div>

                    <!-- Messages -->
                    <div id="messages-scroll" class="flex-1 overflow-y-auto px-4 py-4 space-y-2"
                         style="background: repeating-linear-gradient(0deg, rgba(255,255,255,0.01), rgba(255,255,255,0.01) 2px, transparent 2px, transparent 4px);">
                        @foreach ($messages as $msg)
                            @php $mine = $msg->sender_id === $support->id; @endphp
                            <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[75%] rounded-2xl px-4 py-2 text-sm shadow
                                            {{ $mine ? 'bg-primary-600 text-white rounded-br-sm' : 'bg-dark-200 text-gray-100 rounded-bl-sm' }}">
                                    @if ($msg->image_path)
                                        <img src="{{ \Illuminate\Support\Str::startsWith($msg->image_path, ['http']) ? $msg->image_path : asset('storage/' . $msg->image_path) }}"
                                             class="rounded-lg mb-1 max-h-60" alt="image">
                                    @endif
                                    @if ($msg->message)
                                        <p class="whitespace-pre-wrap break-words">{{ $msg->message }}</p>
                                    @endif
                                    <p class="text-[10px] mt-1 {{ $mine ? 'text-white/70' : 'text-gray-500' }} text-right">
                                        {{ $msg->created_at->format('d/m H:i') }}
                                        @if ($mine)
                                            <i class="fas {{ $msg->is_read ? 'fa-check-double text-blue-300' : 'fa-check' }} ml-1"></i>
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Zone de réponse -->
                    <form action="{{ route('admin.messages.reply', $selected->id) }}" method="POST"
                          class="px-4 py-3 border-t border-dark-200 flex items-end gap-3">
                        @csrf
                        <textarea name="message" rows="1" required
                                  placeholder="Écrire une réponse…"
                                  class="flex-1 resize-none px-4 py-3 bg-dark-200 border border-dark-300 rounded-2xl text-white placeholder-gray-500 focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                  onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault(); this.form.submit();}"></textarea>
                        <button type="submit"
                                class="shrink-0 w-12 h-12 rounded-full bg-primary-600 hover:bg-primary-700 text-white flex items-center justify-center transition-colors">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                @endif
            </section>
        </div>
    </div>
    @endif
</div>

@push('scripts')
<script>
    // Auto-scroll vers le dernier message.
    (function () {
        const box = document.getElementById('messages-scroll');
        if (box) box.scrollTop = box.scrollHeight;
    })();
</script>
@endpush
@endsection
