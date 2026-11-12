const SALE_POPUP_DISMISSED_STORAGE_KEY = 'bb_sale_popup_dismissed_until'

// Returns the number of hours the popup should stay hidden after a visitor closes it.
// 0 (or a missing/invalid value) disables the behaviour entirely.
let getSalePopupHideDuration = function () {
    const hours = parseFloat($('.js-sale-popup-container').data('hide-duration-after-closed'))

    return isNaN(hours) || hours <= 0 ? 0 : hours
}

let isSalePopupDismissed = function () {
    if (!getSalePopupHideDuration()) {
        return false
    }

    try {
        const dismissedUntil = parseInt(window.localStorage.getItem(SALE_POPUP_DISMISSED_STORAGE_KEY), 10)

        if (isNaN(dismissedUntil)) {
            return false
        }

        if (Date.now() >= dismissedUntil) {
            window.localStorage.removeItem(SALE_POPUP_DISMISSED_STORAGE_KEY)

            return false
        }

        return true
    } catch (error) {
        // localStorage can be unavailable (private mode, blocked cookies) - fall back to always showing.
        return false
    }
}

let dismissSalePopup = function () {
    const hours = getSalePopupHideDuration()

    if (!hours) {
        return
    }

    try {
        window.localStorage.setItem(SALE_POPUP_DISMISSED_STORAGE_KEY, Date.now() + hours * 60 * 60 * 1000)
    } catch (error) {
        // Ignore storage errors - the popup will simply reappear on the next page load.
    }
}

let salesPopup = function ($popupContainer) {
    // Check if we should show on mobile
    const showOnMobile = $('.js-sale-popup-container').data('show-on-mobile') === true;

    // If we're on mobile and showOnMobile is false, return early
    if (!showOnMobile && $(window).width() < 768) {
        return
    }

    const stt = $popupContainer.data('stt')

    if (stt === undefined) {
        return
    }

    const limit = stt.limit - 1
    const popupType = stt.pp_type
    const arrTitle = JSON.parse($('#title-sale-popup').html())
    const arrUrl = stt.url
    const arrImage = stt.image
    const arrID = stt.id
    const arrLocation = JSON.parse($('#location-sale-popup').html())
    const arrTime = JSON.parse($('#time-sale-popup').html())
    const classUp = stt.classUp
    const classDown = stt.classDown[classUp]
    let starTimeout
    let stayTimeout

    const salePopupImg = $('.js-sale-popup-img')
    const salePopupLink = $('.js-sale-popup-a')
    const salePopupTitle = $('.js-sale-popup-tt')
    const salePopupLocation = $('.js-sale-popup-location')
    const salePopupTimeAgo = $('.js-sale-popup-ago')
    const salePopupQuickView = $('.sale-popup-quick-view')

    let index = 0
    const min = 0
    const max = arrUrl.length - 1
    const max2 = arrLocation.length - 1
    const max3 = arrTime.length - 1
    const starTime = stt.starTime * stt.starTimeUnit
    const stayTime = stt.stayTime * stt.stayTimeUnit

    let getRandomInt = function (min, max) {
        return Math.floor(Math.random() * (max - min + 1)) + min
    }

    let updateData = function (index) {
        let img = arrImage[index]

        salePopupImg.attr('src', img).attr('srcset', img)

        salePopupTitle.text(arrTitle[index])

        salePopupLink.attr('href', arrUrl[index])

        const quickViewUrl = salePopupQuickView.attr('data-base-url') + '/ajax/quick-view/' + arrID[index]

        salePopupQuickView.attr('href', quickViewUrl).attr('data-url', quickViewUrl)

        salePopupLocation.text(arrLocation[getRandomInt(min, max2)])

        salePopupTimeAgo.text(arrTime[getRandomInt(min, max3)])

        showSalesPopUp()
    }

    let loadSalesPopup = function () {
        if (popupType == '1') {
            updateData(index)
            ++index
            if (index > limit || index > max) {
                index = 0
            }
        } else {
            updateData(getRandomInt(min, max))
        }

        stayTimeout = setTimeout(function () {
            unloadSalesPopup()
        }, stayTime)
    }

    let unloadSalesPopup = function () {
        hideSalesPopUp()
        starTimeout = setTimeout(function () {
            loadSalesPopup()
        }, starTime)
    }

    let showSalesPopUp = function () {
        $popupContainer.removeClass('hidden').addClass(classUp).removeClass(classDown)
    }

    let hideSalesPopUp = function () {
        $popupContainer.removeClass(classUp).addClass(classDown)
    }

    $(document).on('click', '.sale-popup-close', function (e) {
        e.preventDefault()
        hideSalesPopUp()
        clearTimeout(stayTimeout)
        clearTimeout(starTimeout)
        dismissSalePopup()
    })

    $popupContainer.on('open-sale-popup', function () {
        unloadSalesPopup()
    })

    unloadSalesPopup()
}

$(document).ready(function () {
    const $popupContainer = $('.js-sale-popup-container.hidden')

    if ($popupContainer.length && !isSalePopupDismissed()) {
        setTimeout(() => {
            $popupContainer.removeClass('hidden')

            // Assuming $popupContainer is a jQuery object
            const url = $popupContainer.data('include');

            fetch(url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json', // Sending JSON
                    'Accept': 'application/json' // Requesting JSON response
                }
            })
                .then(response => {
                    // Check if the response is okay and parse it as JSON
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(({ data }) => {
                    // Insert the fetched HTML into the $popupContainer
                    $popupContainer.html(data);

                    if (typeof Theme.lazyLoadInstance !== 'undefined') {
                        Theme.lazyLoadInstance.update()
                    }

                    // Call salesPopup with the newly added content
                    salesPopup($popupContainer.find('.sale-popup-container-wrap'));
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                });
        }, 3000)
    }
})
