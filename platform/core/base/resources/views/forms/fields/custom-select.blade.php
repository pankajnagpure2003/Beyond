@php
    // The select gets a unique id (the same field can render more than once), so
    // pick it here and point the label at it. Without a visible label, name the
    // select after its label text instead.
    $options['attr']['id'] ??= $name . '-select-' . rand(10000, 99999);
    $options['label_attr']['for'] ??= $options['attr']['id'];

    if (
        (! $showLabel || $options['label'] === false || ! $options['label_show'])
        && ! isset($options['attr']['aria-label'])
        && is_string($options['label'])
        && ($labelText = trim(strip_tags($options['label']))) !== ''
    ) {
        $options['attr']['aria-label'] = $labelText;
    }
@endphp

<x-core::form.field
    :showLabel="$showLabel"
    :showField="$showField"
    :options="$options"
    :name="$name"
    :prepend="$prepend ?? null"
    :append="$append ?? null"
    :showError="$showError"
    :nameKey="$nameKey"
>
    <x-slot:label>
        @if ($showLabel && $options['label'] !== false && $options['label_show'])
            {!! Form::customLabel($name, $options['label'], $options['label_attr']) !!}
        @endif
    </x-slot:label>

    @php
        if ($options['choices'] instanceof \Illuminate\Contracts\Support\Arrayable) {
            $options['choices'] = $options['choices']->toArray();
        }
    @endphp

    {!! Form::customSelect(
        $name,
        ($options['empty_value'] ? ['' => $options['empty_value']] : []) + $options['choices'],
        $options['selected'] !== null ? $options['selected'] : $options['default_value'],
        $options['attr'],
        Arr::get($options, 'optionAttrs', []),
        Arr::get($options, 'optgroupsAttributes', []),
    ) !!}
</x-core::form.field>
