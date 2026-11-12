<script>
    "use strict";

    var RV_MEDIA_URL = {{ Js::from(RvMedia::getUrls()) }};

    var RV_MEDIA_CONFIG =
        {{ Js::from([
            'permissions' => RvMedia::getPermissions(),
            'translations' => trans('core/media::media.javascript'),
            'pagination' => [
                'paged' => RvMedia::getConfig('pagination.paged'),
                'posts_per_page' => RvMedia::getConfig('pagination.per_page'),
                'in_process_get_media' => false,
                'has_more' => true,
            ],
            'chunk' => RvMedia::getConfig('chunk'),
            'random_hash' => setting('media_random_hash') ?: null,
            // Null when the server reports no limit (post_max_size = 0), so the
            // upload error never claims a limit of "0 B".
            'max_upload_size' => ($maxUploadSize = (int) RvMedia::getServerConfigMaxUploadFileSize()) > 0
                ? \Botble\Base\Facades\BaseHelper::humanFilesize($maxUploadSize)
                : null,
            'default_image' => RvMedia::getDefaultImage(),
        ]) }};

    RV_MEDIA_CONFIG.translations.actions_list.other.properties =
        '{{ trans('core/media::media.javascript.actions_list.other.properties') }}';
</script>
