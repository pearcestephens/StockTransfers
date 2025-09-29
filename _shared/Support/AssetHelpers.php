<?php
/**
 * File: AssetHelpers.php
 * Purpose: Global helper functions for easy asset loading
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 */

declare(strict_types=1);

use Modules\Transfers\Shared\Core\AssetLoader;

if (!function_exists('load_transfer_assets')) {
    /**
     * Simple helper to load all CSS and JS assets for transfers
     * 
     * @param array $cssExclusions Files to exclude from CSS loading
     * @param array $jsExclusions Files to exclude from JS loading
     * @return string HTML for all asset includes
     */
    function load_transfer_assets(array $cssExclusions = [], array $jsExclusions = []): string
    {
        $loader = AssetLoader::forStockTransfers();
        return $loader->loadAll(
            cssExclusions: $cssExclusions,
            jsExclusions: $jsExclusions,
            jsDefer: true
        );
    }
}

if (!function_exists('load_transfer_css')) {
    /**
     * Load only CSS assets
     * 
     * @param array $exclusions Files to exclude
     * @return string HTML for CSS includes
     */
    function load_transfer_css(array $exclusions = []): string
    {
        $loader = AssetLoader::forStockTransfers();
        return $loader->loadCSS(additionalExclusions: $exclusions);
    }
}

if (!function_exists('load_transfer_js')) {
    /**
     * Load only JS assets
     * 
     * @param array $exclusions Files to exclude
     * @param bool $defer Whether to add defer attribute
     * @return string HTML for JS includes
     */
    function load_transfer_js(array $exclusions = [], bool $defer = true): string
    {
        $loader = AssetLoader::forStockTransfers();
        return $loader->loadJS(additionalExclusions: $exclusions, defer: $defer);
    }
}

if (!function_exists('debug_transfer_assets')) {
    /**
     * Debug function to see what assets would be loaded
     * 
     * @return array Asset information
     */
    function debug_transfer_assets(): array
    {
        $loader = AssetLoader::forStockTransfers();
        
        return [
            'css_files' => $loader->getAssetInfo('assets/css', 'css'),
            'js_files' => $loader->getAssetInfo('assets/js', 'js'),
        ];
    }
}