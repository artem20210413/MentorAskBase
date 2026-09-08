<div class="flex h-screen flex-col bg-neutral-900">
    <header class="border-b border-neutral-800 bg-neutral-900 px-6 py-4">
        <h1 class="text-lg font-semibold text-neutral-100">Асистент бази знань</h1>
        <p class="text-sm text-neutral-400">Задайте питання — відповідь формується на основі завантажених документів.</p>
    </header>

    <main class="flex-1 space-y-4 overflow-y-auto px-6 py-6" id="chat-log">
        @forelse ($messages as $message)
            <div class="flex {{ $message['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-xl rounded-2xl px-4 py-3
                    {{ $message['role'] === 'user' ? 'bg-indigo-600 text-white' : 'bg-neutral-800 text-neutral-100 border border-neutral-700' }}">
                    <p class="whitespace-pre-line">{{ $message['content'] }}</p>

                    @if (! empty($message['sources']))
                        <div class="mt-2 border-t border-white/10 pt-2 text-xs opacity-60">
                            Джерела: {{ implode(', ', $message['sources']) }}
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="flex h-full items-center justify-center text-neutral-500">
                Поставте перше питання, щоб почати розмову.
            </div>
        @endforelse

        @if ($isSending)
            <div class="flex justify-start">
                <div class="rounded-2xl border border-neutral-700 bg-neutral-800 px-4 py-3 text-neutral-400">
                    Друкує…
                </div>
            </div>
        @endif

        @if ($error)
            <div class="rounded-lg bg-red-950 px-4 py-2 text-sm text-red-400">
                {{ $error }}
            </div>
        @endif
    </main>

    <form wire:submit="ask" class="border-t border-neutral-800 bg-neutral-900 px-6 py-4">
        <div class="flex gap-3">
            <input
                type="text"
                wire:model="question"
                placeholder="Напишіть питання..."
                autocomplete="off"
                class="flex-1 rounded-full border border-neutral-700 bg-neutral-800 px-4 py-2 text-neutral-100 placeholder:text-neutral-500 focus:border-indigo-500 focus:outline-none"
                @disabled($isSending)
            >
            <button
                type="submit"
                class="rounded-full bg-indigo-600 px-6 py-2 font-medium text-white transition hover:bg-indigo-500 disabled:opacity-50"
                @disabled($isSending)
            >
                Надіслати
            </button>
        </div>
    </form>
</div>

<script>
    document.addEventListener('livewire:navigated', scrollChatToBottom);
    document.addEventListener('livewire:updated', scrollChatToBottom);

    function scrollChatToBottom() {
        const log = document.getElementById('chat-log');
        if (log) log.scrollTop = log.scrollHeight;
    }
</script>
