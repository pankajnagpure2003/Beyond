// Custom-property prefix pass for stylesheets compiled from Tabler's Sass.
//
// Since Tabler 1.5 the Sass sources author custom properties bare
// (`--primary`, `var(--card-bg)`) and Tabler's own build adds the public
// prefix with postcss-prefix-custom-properties. We compile Tabler ourselves,
// so we run the same pass with our `bb-` prefix. Mirrors
// tabler/.build/css-var-prefix.ts (tag @tabler/core@1.6.1).

import postcss from 'postcss'
import prefixCustomProperties from 'postcss-prefix-custom-properties'

// Names that must stay as they are: already-prefixed names, and variables that
// third-party stylesheets themed by Tabler read under their own names.
function ignoreList(prefix) {
    return [
        new RegExp(`^--${prefix}`), // already prefixed
        /^--tblr-/, // Tabler writes a few names pre-prefixed
        /^--apx-/, // apexcharts
        /^--bs-/, // bootstrap
        /^--fc-/, // fullcalendar
        /^--gl-/, // star-rating.js
        /^--litepicker-/, // litepicker
        /^--plyr-/, // plyr
        /^--ts-/, // tom-select
        '--section-bg', // tabler marketing sections
    ]
}

// postcss hands plugins the declaration value without its trailing comment, so a
// plugin that rewrites `decl.value` drops `/*rtl:ignore*/` markers that rtlcss
// needs later. Folding the raw value back in first keeps those comments.
const inlineValueComments = {
    postcssPlugin: 'botble-inline-value-comments',
    Once(root) {
        root.walkDecls((decl) => {
            if (decl.raws.value) decl.value = decl.raws.value.raw
        })
    },
}

export async function prefixCssVars(css, prefix) {
    const result = await postcss([
        inlineValueComments,
        prefixCustomProperties({ prefix, ignore: ignoreList(prefix) }),
    ]).process(css, { from: undefined })

    return result.css
}
