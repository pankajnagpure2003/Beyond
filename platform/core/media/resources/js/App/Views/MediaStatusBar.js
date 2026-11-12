/**
 * Footer status bar summarising the current media view: folder/file counts,
 * total size and per-type counts.
 *
 * The numbers and their labels come from the server (see
 * MediaFileRepository::getStats), so they stay correct even though the list is
 * paginated by infinite scroll, and pluralisation follows the active locale.
 */
export class MediaStatusBar {
    render(stats) {
        // The server only sends stats for the first page; later pages of an
        // infinite scroll leave the bar as it is.
        if (!stats || !stats.labels) {
            return
        }

        // Resolved per render so the bar also works when the media markup is
        // injected later, as it is in the picker modal.
        const $container = $('.rv-media-status-bar')

        if (!$container.length) {
            return
        }

        // Hidden until the first response so an empty bar does not flash on load.
        $container.removeClass('d-none')

        // The file count is always shown (an empty folder is worth stating);
        // everything else appears only when there is something to report.
        this.renderItem($container, 'folders', stats.total_folders, stats.labels.folders)
        this.renderItem($container, 'files', stats.total_files, stats.labels.files, true)
        this.renderItem($container, 'size', stats.total_size, stats.labels.total_size)
        this.renderItem($container, 'images', stats.image_count, stats.labels.images)
        this.renderItem($container, 'videos', stats.video_count, stats.labels.videos)
        this.renderItem($container, 'documents', stats.document_count, stats.labels.documents)
    }

    renderItem($container, name, count, label, alwaysShow = false) {
        const $item = $container.find(`.js-status-${name}`)

        if (!parseInt(count, 10) && !alwaysShow) {
            $item.addClass('d-none')

            return
        }

        $item.find(`.js-status-${name}-text`).text(label)
        $item.removeClass('d-none')
    }
}
