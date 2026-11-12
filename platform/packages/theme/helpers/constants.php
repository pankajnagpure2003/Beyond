<?php

if (! defined('THEME_FRONT_FOOTER')) {
    define('THEME_FRONT_FOOTER', 'theme-front-footer');
}

if (! defined('THEME_FRONT_HEADER')) {
    define('THEME_FRONT_HEADER', 'theme-front-header');
}

if (! defined('THEME_FRONT_BODY')) {
    define('THEME_FRONT_BODY', 'theme-front-body');
}

if (! defined('THEME_MODULE_SCREEN_NAME')) {
    define('THEME_MODULE_SCREEN_NAME', 'theme');
}

if (! defined('THEME_OPTIONS_MODULE_SCREEN_NAME')) {
    define('THEME_OPTIONS_MODULE_SCREEN_NAME', 'theme-options');
}

if (! defined('THEME_OPTIONS_ACTION_META_BOXES')) {
    define('THEME_OPTIONS_ACTION_META_BOXES', 'theme-options-action-meta-boxes');
}

if (! defined('RENDERING_THEME_OPTIONS_PAGE')) {
    define('RENDERING_THEME_OPTIONS_PAGE', 'rendering-theme-options-page');
}

/**
 * Filters the content sections listed in llms.txt / llms-full.txt.
 *
 * Receives an array of sections, each: ['model' => class-string, 'heading' => string,
 * 'limit' => int]. Plugins add their own slugable models (products, docs, ...) here.
 */
if (! defined('FILTER_LLMS_TXT_SECTIONS')) {
    define('FILTER_LLMS_TXT_SECTIONS', 'filter_llms_txt_sections');
}

/**
 * Filters the absolute path of the robots.txt file the admin panel reads and writes.
 *
 * Receives the default `public_path('robots.txt')`. Multi-tenant setups point each
 * store at its own file, so one store editing robots.txt cannot overwrite another's.
 */
if (! defined('FILTER_ROBOTS_TXT_PATH')) {
    define('FILTER_ROBOTS_TXT_PATH', 'filter_robots_txt_path');
}

/**
 * Filters the absolute path of the theme's custom CSS file (style.integration.css).
 *
 * Receives the default path under the published theme assets folder. A filter that
 * changes the file NAME is safe (the <link> tag is built from basename()); a filter
 * that moves the file OUT of the theme's `css` folder is not, since the public URL
 * is still resolved relative to that folder.
 */
if (! defined('FILTER_THEME_STYLE_INTEGRATION_PATH')) {
    define('FILTER_THEME_STYLE_INTEGRATION_PATH', 'filter_theme_style_integration_path');
}
