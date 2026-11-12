@php
    $values = Arr::wrap($values ?? []);

    $attributes = (array) $attributes;

    $multiple = count($values) > 1;
@endphp

<div class="position-relative form-check-group">
    @foreach ($values as $key => $option)
        @php
            $optionAttributes = $attributes;

            // Suffix a copy, not $attributes itself - mutating it would compound the id across
            // iterations (field_a, then field_a_b, then field_a_b_c).
            if ($multiple && isset($optionAttributes['id'])) {
                $optionAttributes['id'] = $optionAttributes['id'] . '_' . $key;
            }
        @endphp

        <x-core::form.radio
            :name="$name"
            :value="$key"
            {{-- Compare as strings so a loose == cannot check the option whose value is 0
                 when nothing is selected. Null is cast to '', so a group that offers an
                 explicit empty option (e.g. "Use default setting") still selects it. --}}
            :checked="(string) $key === (string) $selected"
            :attributes="new Illuminate\View\ComponentAttributeBag($optionAttributes)"
        >
            {{ $option }}
        </x-core::form.radio>
    @endforeach
</div>
