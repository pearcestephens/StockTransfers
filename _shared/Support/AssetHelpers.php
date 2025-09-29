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
     * Load shared + module CSS assets in proper order
     * 
     * @param array $exclusions Files to exclude from module assets
     * @param array $sharedExclusions Files to exclude from shared assets
     * @return string HTML for CSS includes
     */
    function load_transfer_css(array $exclusions = [], array $sharedExclusions = []): string
    {
        $loader = AssetLoader::forStockTransfers();
        
        $output = '';
        
        // 1. Load shared CSS first (foundation styles)
        $output .= "<!-- =============\n     SHARED CSS ASSETS\n     ============= -->\n";
        $sharedPath = realpath(__DIR__ . '/../assets');
        if ($sharedPath && is_dir($sharedPath)) {
            $sharedLoader = new \Modules\Transfers\Shared\Core\AssetLoader(
                basePath: $sharedPath,
                baseUrl: '/modules/transfers/_shared/assets/',
                cssExclusions: $sharedExclusions,
                useFileMtimeVersioning: true
            );
            $output .= $sharedLoader->loadCSS('css', []);
        }
        
        // 2. Load module-specific CSS (overrides/extensions)
        $output .= "<!-- =============\n     MODULE CSS ASSETS\n     ============= -->\n";
        $output .= $loader->loadCSS(additionalExclusions: $exclusions);
        
        return $output;
    }
}

if (!function_exists('load_transfer_js')) {
    /**
     * Load shared + module JS assets in proper order
     * 
     * @param array $exclusions Files to exclude from module assets
     * @param array $sharedExclusions Files to exclude from shared assets
     * @param bool $defer Whether to add defer attribute
     * @return string HTML for JS includes
     */
    function load_transfer_js(array $exclusions = [], array $sharedExclusions = [], bool $defer = true): string
    {
        $loader = AssetLoader::forStockTransfers();
        
        $output = '';
        
        // 1. Load shared JS (utilities and common functionality)
        $output .= "<!-- =============\n     SHARED JS ASSETS\n     ============= -->\n";
        $sharedPath = realpath(__DIR__ . '/../assets');
        if ($sharedPath && is_dir($sharedPath)) {
            $sharedLoader = new \Modules\Transfers\Shared\Core\AssetLoader(
                basePath: $sharedPath,
                baseUrl: '/modules/transfers/_shared/assets/',
                jsExclusions: $sharedExclusions,
                useFileMtimeVersioning: true
            );
            $output .= $sharedLoader->loadJS('js', [], $defer);
        }
        
        // 2. Load module-specific JS (depends on shared utilities)
        $output .= "<!-- =============\n     MODULE JS ASSETS\n     ============= -->\n";
        $output .= $loader->loadJS(additionalExclusions: $exclusions, defer: $defer);
        
        return $output;
    }
}

if (!function_exists('load_transfer_assets_all')) {
    /**
     * Load all assets (shared + module) in one call
     * 
     * @param array $exclusions Module exclusions ['css' => [], 'js' => []]
     * @param array $sharedExclusions Shared exclusions ['css' => [], 'js' => []]
     * @param bool $jsDefer Whether to defer JS
     * @return string HTML for all includes
     */
    function load_transfer_assets_all(
        array $exclusions = [], 
        array $sharedExclusions = [], 
        bool $jsDefer = true
    ): string {
        return AssetLoader::loadStockWithShared(
            moduleExclusions: $exclusions,
            sharedExclusions: $sharedExclusions
        );
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