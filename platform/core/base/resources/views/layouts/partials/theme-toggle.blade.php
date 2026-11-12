@php
    $currentThemeMode = AdminHelper::themeMode();
    $themeModeIcons = [
        'light' => 'ti ti-sun',
        'dark' => 'ti ti-moon',
        'system' => 'ti ti-device-desktop',
    ];
@endphp

<div class="nav-item dropdown">
    <button
        type="button"
        class="px-0 nav-link"
        data-bs-toggle="dropdown"
        aria-expanded="false"
        aria-label="{{ trans('core/setting::setting.admin_appearance.theme_mode') }}"
        title="{{ trans('core/setting::setting.admin_appearance.theme_mode') }}"
    >
        <x-core::icon :name="$themeModeIcons[$currentThemeMode]" />
    </button>
    <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
        @foreach ($themeModeIcons as $themeMode => $icon)
            <a
                href="{{ route('toggle-theme-mode', ['theme' => $themeMode]) }}"
                @class(['dropdown-item', 'active' => $themeMode === $currentThemeMode])
                @if ($themeMode === $currentThemeMode) aria-current="true" @endif
            >
                <x-core::icon
                    :name="$icon"
                    class="dropdown-item-icon"
                />
                {{ trans("core/setting::setting.admin_appearance.$themeMode") }}
            </a>
        @endforeach
    </div>
</div>
