class PluginAnalytics {
    static initCharts() {
        const analyticsData = window.analyticsStats || {}
        const $statsChart = $('#stats-chart')
        const $worldMap = $('#world-map')

        let statArray = []
        $.each(analyticsData.stats, (index, el) => {
            statArray.push({ axis: el.axis, visitors: el.visitors, pageViews: el.pageViews })
        })

        if ($statsChart.length) {
            new Morris.Area({
                element: 'stats-chart',
                resize: true,
                data: statArray,
                xkey: 'axis',
                ykeys: ['visitors', 'pageViews'],
                labels: [analyticsData.translations.visits, analyticsData.translations.pageViews],
                lineColors: ['#d6336c', '#4299e1'],
                hideHover: 'auto',
                parseTime: false,
            })
        }

        let visitorsData = {}

        $.each(analyticsData.countryStats, (index, el) => {
            visitorsData[el[0]] = el[1]
        })

        if ($worldMap.length) {
            // jVectorMap interpolates its scale between hex colors, so the palette
            // is picked here rather than read from CSS variables.
            const isDarkMode = document.documentElement.getAttribute('data-bs-theme') === 'dark'
            const primaryColor =
                getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim() || '#206bc4'
            const mapColors = isDarkMode
                ? { land: '#374151', border: '#1f2937', scaleStart: '#4b5563' }
                : { land: '#f6f8fb', border: '#dce1e7', scaleStart: '#ffffff' }

            $worldMap.vectorMap({
                map: 'world_mill_en',
                backgroundColor: 'transparent',
                regionStyle: {
                    initial: {
                        fill: mapColors.land,
                        stroke: mapColors.border,
                        'stroke-width': 2,
                    },
                },
                series: {
                    regions: [
                        {
                            values: visitorsData,
                            scale: [mapColors.scaleStart, primaryColor],
                            normalizeFunction: 'polynomial',
                        },
                    ],
                },
                onRegionLabelShow: (e, el, code) => {
                    if (typeof visitorsData[code] !== 'undefined') {
                        el.html(el.html() + ': ' + visitorsData[code] + ' ' + analyticsData.translations.visits)
                    }
                },
            })
        }
    }
}

$(() => {
    const $analyticsGeneral = $('#widget_analytics_general')

    BDashboard.loadWidget($analyticsGeneral.find('.widget-content'), $analyticsGeneral.data('url'), null, () => {
        PluginAnalytics.initCharts()

        let stats = window.analyticsStats.stats || []

        if (!stats[1]?.visitors) {
            $analyticsGeneral.find('.stats-warning').addClass('d-block')
            $analyticsGeneral.find('.stats-warning').removeClass('d-none')
        } else {
            $analyticsGeneral.find('.stats-warning').addClass('d-none')
            $analyticsGeneral.find('.stats-warning').removeClass('d-block')
        }
    })
    BDashboard.loadWidget($('#widget_analytics_page').find('.widget-content'), $('#widget_analytics_page').data('url'))
    BDashboard.loadWidget(
        $('#widget_analytics_browser').find('.widget-content'),
        $('#widget_analytics_browser').data('url')
    )
    BDashboard.loadWidget(
        $('#widget_analytics_referrer').find('.widget-content'),
        $('#widget_analytics_referrer').data('url')
    )
})
