<select name="target" class="w-full px-3 py-2 bg-dark-200 rounded text-white text-sm">
    <option value="">{{ $placeholder ?? '— Aucune —' }}</option>
    @foreach($targets as $value => $label)
        <option value="{{ $value }}" @selected(($selected ?? null) === (string) $value)>{{ $label }}</option>
    @endforeach
</select>
