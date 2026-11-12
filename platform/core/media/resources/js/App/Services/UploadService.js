import { MediaService } from './MediaService'
import { Helpers } from '../Helpers/Helpers'

export class UploadService {
    constructor() {
        this.$body = $('body')

        this.dropZone = null

        this.uploadUrl = RV_MEDIA_URL.upload_file

        this.uploadProgressBox = $('.rv-upload-progress')

        this.uploadProgressContainer = $('.rv-upload-progress .rv-upload-progress-table')

        this.uploadProgressTemplate = $('#rv_media_upload_progress_item').html()

        this.MediaService = new MediaService()

        // Pending pane timers, tracked so a finished batch cannot close the pane or
        // discard the rows of a batch queued after it.
        this.autoCloseTimer = null
        this.clearRowsTimer = null

        this.statusIcons = UploadService.buildStatusIcons()
    }

    /**
     * Pull the server-rendered status glyphs out of the icon template once, so each
     * row can be given the right one without re-rendering SVG in JavaScript.
     */
    static buildStatusIcons() {
        const $icons = $('<div></div>').html($('#rv_media_upload_icons').html())
        const pick = (selector) => $icons.find(selector).prop('outerHTML') || ''

        return {
            uploading: pick('.js-icon-uploading'),
            uploaded: pick('.js-icon-uploaded'),
            error: pick('.js-icon-error'),
            canceled: pick('.js-icon-canceled'),
            retry: pick('.js-icon-retry'),
        }
    }

    setFileIcon($row, state) {
        $row
            .find('.js-file-icon')
            .toggleClass('rv-upload-file-icon--spin', state === 'uploading')
            .html(this.statusIcons[state] || '')
    }

    init() {
        if (Helpers.hasPermission('files.create') && $('.rv-media-items').length > 0) {
            this.setupDropZone()
        }
        this.handleEvents()
    }

    setupDropZone() {
        let _self = this

        let _dropZoneConfig = this.getDropZoneConfig()

        if (_self.dropZone) {
            _self.dropZone.destroy()
        }

        _self.dropZone = new Dropzone(document.querySelector('.rv-media-items'), {
            ..._dropZoneConfig,
            thumbnailWidth: false,
            thumbnailHeight: false,
            parallelUploads: 1,
            autoQueue: true,
            clickable: '.js-dropzone-upload',
            previewsContainer: false,
            sending: function (file, xhr, formData) {
                formData.append('_token', $('meta[name="csrf-token"]').attr('content'))
                formData.append('folder_id', Helpers.getRequestParams().folder_id)
                formData.append('view_in', Helpers.getRequestParams().view_in)
                formData.append('path', file.fullPath)
            },
            chunksUploaded: (file, done) => {
                // Scoped to this file's row; it previously marked every row 100%.
                _self.getProgressRow(file).find('.progress-percent').html(`- <span class="text-info">100%</span>`)
                done()
            },
            uploadprogress: (file, progress, bytesSent) => {
                let percent = (bytesSent / file.size) * 100
                if (file.upload.chunked && percent > 99) {
                    percent = percent - 1
                }
                let percentShow = (percent > 100 ? '100' : parseInt(percent)) + '%'
                _self.getProgressRow(file)
                    .find('.progress-percent')
                    .html(`- <span class="text-info">` + percentShow + `</span>`)
            },
        })

        // A row is created as soon as the file is queued, so files waiting behind a
        // slower upload are visible rather than appearing only once they start.
        _self.dropZone.on('addedfile', (file) => {
            _self.initProgress(file)
        })

        // Dropzone reports its own client-side rejections (size, file type) here,
        // before any request is made, so keep that reason for the row.
        _self.dropZone.on('error', (file, message) => {
            if (! file.accepted && typeof message === 'string') {
                file.clientErrorMessage = message
            }
        })

        _self.dropZone.on('complete', (file) => {
            _self.changeProgressStatus(file)
        })

        _self.dropZone.on('queuecomplete', () => {
            Helpers.resetPagination()
            _self.MediaService.getMedia(true)
            _self.renderSummary()
        })
    }

    handleEvents() {
        let _self = this
        /**
         * Close upload progress pane
         */
        _self.$body
            .off('click', '.rv-upload-progress .close-pane')
            .on('click', '.rv-upload-progress .close-pane', (event) => {
                event.preventDefault()
                $('.rv-upload-progress').addClass('hide-the-pane')
                $('.rv-upload-progress .js-upload-summary').text('')

                clearTimeout(_self.autoCloseTimer)
                clearTimeout(_self.clearRowsTimer)

                _self.clearRowsTimer = setTimeout(() => _self.clearProgressRows(), 300)
            })
    }

    /**
     * Create the progress row for a queued file and keep a reference to it on the
     * file itself. Rows were previously matched by a running counter against
     * nth-child, which silently addressed the wrong row (or none at all) whenever
     * the table and the counter fell out of step.
     */
    initProgress(file) {
        // A newly queued file cancels any pending close or purge left over from an
        // earlier batch, which would otherwise remove this row while it is still live.
        clearTimeout(this.autoCloseTimer)
        clearTimeout(this.clearRowsTimer)

        const template = this.uploadProgressTemplate.replace(
            /__fileSize__/gi,
            UploadService.formatFileSize(file.size)
        )

        const $row = $(template)

        // Set as text: a file name is user-supplied and must not be parsed as HTML.
        $row.find('.file-name').text(file.name).attr('title', file.name)
        $row.find('.file-status').addClass('text-secondary').text(Helpers.trans('upload_status.uploading', 'Uploading...'))

        this.setFileIcon($row, 'uploading')

        file.$progressRow = $row

        this.uploadProgressContainer.append($row)
        this.uploadProgressBox.removeClass('hide-the-pane')
        this.uploadProgressBox.find('.js-upload-summary').text('')
        this.uploadProgressBox.find('.table').animate({ scrollTop: this.uploadProgressContainer.height() }, 150)
    }

    getProgressRow(file) {
        return file.$progressRow || $()
    }

    /**
     * Drop every progress row and release the element each file holds, so a
     * dismissed batch is neither counted again nor retained in memory.
     */
    clearProgressRows() {
        Helpers.each(this.dropZone ? this.dropZone.files : [], (file) => {
            file.$progressRow = null
        })

        this.uploadProgressContainer.empty()
    }

    changeProgressStatus(file) {
        const _self = this

        const $progressLine = _self.getProgressRow(file)

        if (!$progressLine.length) {
            return
        }

        const $label = $progressLine.find('.file-status')
        const $error = $progressLine.find('.file-error')

        const response = Helpers.jsonDecode((file.xhr && file.xhr.responseText) || '', {})

        // A canceled file never reached the server, so it is neither a success nor a
        // failure to report. Reading its (absent) response used to throw here.
        const isCanceled = file.status === 'canceled'
        const isError = ! isCanceled && (response.error === true || file.status === 'error')

        // Recorded here rather than derived from Dropzone's own status, which reports
        // success for a rejected upload: the application answers 200 with an error
        // payload, so only the check above distinguishes the two.
        file.uploadOutcome = isCanceled ? 'canceled' : isError ? 'failed' : 'uploaded'

        const state = isCanceled ? 'canceled' : isError ? 'error' : 'uploaded'

        _self.setFileIcon($progressLine, state)

        $progressLine
            .removeClass('rv-upload-row--error rv-upload-row--canceled')
            .toggleClass('rv-upload-row--error', isError)
            .toggleClass('rv-upload-row--canceled', isCanceled)

        const statusFallback = { uploaded: 'Uploaded', error: 'Error', canceled: 'Canceled' }

        $label
            .removeClass('text-success text-danger text-warning text-secondary text-muted')
            .addClass(isError ? 'text-danger' : isCanceled ? 'text-secondary' : 'text-success')
            .text(Helpers.trans(`upload_status.${state}`, statusFallback[state]))

        if (isError || isCanceled) {
            $progressLine.find('.progress-percent').html('')
        }

        if (isCanceled) {
            return
        }

        if (file.status === 'error') {
            const status = file.xhr ? file.xhr.status : 0

            if (status === 422) {
                $error.empty()
                $.each(response.errors, (key, item) => {
                    $error.append(UploadService.renderError(item)).append('<br>')
                })
            } else if (status === 500) {
                $error.html(UploadService.renderError(file.xhr.statusText))
            } else {
                // 413, or a status the server never reached: PHP rejects the request
                // before the application runs, so there is no response body to report.
                $error.html(UploadService.renderError(UploadService.getUploadErrorMessage(file)))
            }
        } else if (response.error) {
            $error.html(UploadService.renderError(response.message))
        } else if (response.data && response.data.id) {
            Helpers.addToRecent(response.data.id)
            Helpers.setSelectedFile(response.data.id)
        }

        // The error can be long (e.g. the full list of allowed types); it is clamped
        // to two lines in CSS, so expose the whole text on hover.
        if (isError) {
            $error.attr('title', $error.text())
        }

        if (isError && UploadService.isRetryable(file)) {
            _self.appendRetryButton($progressLine, file)
        }
    }

    /**
     * Only failures that could plausibly succeed on a second attempt get a retry
     * button. Retry is offered for transport-level failures (dropped connection,
     * 5xx, unexpected status) and withheld for anything deterministic:
     *  - a client-side rejection (wrong type, too big), which never left the browser;
     *  - a 422 or 413, which will be rejected the same way again;
     *  - an application error, which the server returns with HTTP 200 and an error
     *    payload (so Dropzone marks the file a success) and which is almost always a
     *    validation failure that would repeat identically.
     */
    static isRetryable(file) {
        if (file.status !== 'error' || file.clientErrorMessage) {
            return false
        }

        const status = file.xhr ? file.xhr.status : 0

        return status !== 422 && status !== 413
    }

    appendRetryButton($row, file) {
        const _self = this

        const $retry = $('<button type="button" class="btn btn-link btn-sm p-0 js-retry-upload"></button>')
            .html(this.statusIcons.retry)
            .append($('<span></span>').text(Helpers.trans('upload_status.retry', 'Retry')))

        $retry.on('click', (event) => {
            event.preventDefault()
            _self.retryUpload(file)
        })

        // Sits where the percentage was, in the status column.
        $row.find('.progress-percent').empty().append($retry)
    }

    /**
     * Re-queue the same file for a fresh attempt. Removing then re-adding it resets
     * Dropzone's per-file upload state; the old row is dropped so the retry gets a
     * clean one through the normal addedfile -> initProgress path.
     */
    retryUpload(file) {
        this.getProgressRow(file).remove()

        file.$progressRow = null
        file.uploadOutcome = null

        this.dropZone.removeFile(file)
        this.dropZone.addFile(file)
    }

    /**
     * Report the outcome of the whole queue, and keep the pane open when anything
     * needs attention instead of hiding the result after a few seconds.
     */
    renderSummary() {
        const counts = { uploaded: 0, failed: 0, canceled: 0 }

        // Dropzone keeps every file it has ever handled, so the summary counts only
        // files whose row is still on screen. Otherwise dismissing the pane and
        // uploading again would report the previous batch as well.
        Helpers.each(this.dropZone.files, (file) => {
            if (file.uploadOutcome && this.getProgressRow(file).parent().length) {
                counts[file.uploadOutcome]++
            }
        })

        // Each part is coloured by its meaning so the outcome reads at a glance and
        // does not depend on the wording alone.
        const partClass = { uploaded: 'text-success', failed: 'text-danger', canceled: 'text-secondary' }
        const $summary = this.uploadProgressBox.find('.js-upload-summary').empty()

        ;['uploaded', 'failed', 'canceled']
            .filter((key) => counts[key] > 0)
            .forEach((key, index) => {
                if (index) {
                    $summary.append(document.createTextNode(' · '))
                }

                $summary.append(
                    $('<span></span>')
                        .addClass(partClass[key])
                        .text(Helpers.trans(`upload_summary.${key}`, `:count ${key}`).replace(':count', counts[key]))
                )
            })

        clearTimeout(this.autoCloseTimer)

        if (counts.failed === 0 && counts.canceled === 0) {
            this.autoCloseTimer = setTimeout(() => {
                $('.rv-upload-progress .close-pane').trigger('click')
            }, 5000)
        }
    }

    /**
     * Error messages carry no markup, and some of them echo back a file name the
     * uploader chose, so they are rendered as text rather than as HTML.
     */
    static renderError(message) {
        return $('<span class="text-danger"></span>').text(message == null ? '' : message)
    }

    /**
     * Actionable text for an upload that failed without a usable response body.
     */
    static getUploadErrorMessage(file) {
        // Rejected before any request was made: report why, rather than blaming the
        // connection for a file the browser refused to send.
        if (file.clientErrorMessage) {
            return file.clientErrorMessage
        }

        const status = file.xhr ? file.xhr.status : 0

        // The request was rejected before it reached the application, so the only
        // useful thing to report is the server limit.
        if (status === 413) {
            const maxUploadSize = Helpers.config('max_upload_size')

            if (!maxUploadSize) {
                return Helpers.trans(
                    'upload_error.too_large_unknown_limit',
                    'This file is larger than the server upload limit.'
                )
            }

            return Helpers.trans('upload_error.too_large', 'This file is larger than the server upload limit of :size.')
                .replace(':size', maxUploadSize)
        }

        // Status 0 means the request never completed: a dropped connection, an
        // offline client or an aborted retry. It is not necessarily a size problem,
        // so it must not send the user off to edit php.ini.
        if (status === 0) {
            return Helpers.trans('upload_error.incomplete', 'The upload did not complete. Please try again.')
        }

        return Helpers.trans('upload_error.unknown', 'Upload failed (error :code).').replace(':code', status)
    }

    static formatFileSize(bytes, si = false) {
        let thresh = si ? 1000 : 1024
        if (Math.abs(bytes) < thresh) {
            return bytes + ' B'
        }
        let units = ['KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB']
        let u = -1
        do {
            bytes /= thresh
            ++u
        } while (Math.abs(bytes) >= thresh && u < units.length - 1)

        return bytes.toFixed(1) + ' ' + units[u]
    }

    getDropZoneConfig() {
        return {
            url: this.uploadUrl,
            uploadMultiple: !RV_MEDIA_CONFIG.chunk.enabled,
            chunking: RV_MEDIA_CONFIG.chunk.enabled,
            forceChunking: true, // forces chunking when file.size < chunkSize
            parallelChunkUploads: false, // allows chunks to be uploaded in parallel (this is independent of the parallelUploads option)
            chunkSize: RV_MEDIA_CONFIG.chunk.chunk_size, // chunk size 1,000,000 bytes (~1MB)
            retryChunks: true, // retry chunks on failure
            retryChunksLimit: 3, // retry maximum of 3 times (default is 3)
            timeout: 0, // MB,
            maxFilesize: RV_MEDIA_CONFIG.chunk.max_file_size, // MB
            maxFiles: null, // max files upload,
        }
    }

}
