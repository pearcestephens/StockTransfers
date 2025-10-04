<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Lib;

/**
 * Asset Loader - Auto-loads CSS/JS for stock transfer pages
 * 
 * Automatically discovers and loads page-specific and feature-based assets
 * with cache-busting and dependency management.
 * 
 * @package Modules\Transfers\Stock
 * @author  Pearce Stephens <pearce.stephens@ecigdis.co.nz>
 * @since   2025-10-03
 */
class AssetLoader
{
    private string $baseDir;
    private string $baseUrl;
    private array $manifest = [];
    private array $loaded = [];
    
    /**
     * @param string $baseDir  Absolute path to stock module root
     * @param string $baseUrl  URL path to stock module
     */
    public function __construct(string $baseDir, string $baseUrl)
    {
        $this->baseDir = rtrim($baseDir, '/');
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->buildManifest();
    }
    
    /**
     * Auto-discover all CSS/JS assets and build manifest
     */
    private function buildManifest(): void
    {
        $assetsDir = $this->baseDir . '/assets';
        
        // CSS discovery
        $cssFiles = [
            'components'    => 'css/components.css',
            'lock-ui'       => 'css/lock-ui.css',
            'pack'          => 'css/pack.css',
            'receive'       => 'css/receive.css',
        ];
        
        foreach ($cssFiles as $key => $path) {
            $fullPath = $assetsDir . '/' . $path;
            if (is_file($fullPath)) {
                $this->manifest['css'][$key] = [
                    'path' => $fullPath,
                    'url'  => $this->baseUrl . '/assets/' . $path,
                    'ver'  => filemtime($fullPath),
                ];
            }
        }
        
        // JS discovery (feature-based modules)
        $jsModules = [
            // Shared utilities
            'shared/api-client'        => 'js/shared/api-client.js',
            'shared/modal'             => 'js/shared/modal.js',
            'shared/toast'             => 'js/shared/toast.js',
            
            // Pack features
            'pack/core'                => 'js/pack/core.js',
            'pack/autosave'            => 'js/pack/autosave.js',
            'pack/lock-client-advanced' => 'js/pack/lock-client-advanced.js',
            'pack/lock-client'         => 'js/pack/lock-client.js',
            'pack/validation'          => 'js/pack/validation.js',
            'pack/product-search'      => 'js/pack/product-search.js',
            'pack/shipping'            => 'js/pack/shipping.js',
            'pack/diagnostics'         => 'js/pack/diagnostics.js',
            
            // Receive features
            'receive/core'             => 'js/receive/core.js',
            'receive/barcode'          => 'js/receive/barcode.js',
            'receive/validation'       => 'js/receive/validation.js',
        ];
        
        foreach ($jsModules as $key => $path) {
            $fullPath = $assetsDir . '/' . $path;
            if (is_file($fullPath)) {
                $this->manifest['js'][$key] = [
                    'path' => $fullPath,
                    'url'  => $this->baseUrl . '/assets/' . $path,
                    'ver'  => filemtime($fullPath),
                ];
            }
        }
    }
    
    /**
     * Get version hash for cache-busting
     */
    private function getVersion(string $type, string $key): int
    {
        return $this->manifest[$type][$key]['ver'] ?? time();
    }
    
    /**
     * Load CSS file(s)
     * 
     * @param string|array $keys  CSS key(s) to load
     * @return string HTML link tags
     */
    public function css($keys): string
    {
        $keys = (array) $keys;
        $html = '';
        
        foreach ($keys as $key) {
            if (isset($this->manifest['css'][$key]) && !in_array($key, $this->loaded)) {
                $asset = $this->manifest['css'][$key];
                $url = htmlspecialchars($asset['url'] . '?v=' . $asset['ver'], ENT_QUOTES, 'UTF-8');
                $html .= sprintf(
                    '<link rel="stylesheet" href="%s" data-asset="%s">%s',
                    $url,
                    htmlspecialchars($key, ENT_QUOTES, 'UTF-8'),
                    PHP_EOL
                );
                $this->loaded[] = $key;
            }
        }
        
        return $html;
    }
    
    /**
     * Load JS module(s)
     * 
     * @param string|array $keys  JS module key(s) to load
     * @param bool $defer         Add defer attribute
     * @return string HTML script tags
     */
    public function js($keys, bool $defer = true): string
    {
        $keys = (array) $keys;
        $html = '';
        
        foreach ($keys as $key) {
            if (isset($this->manifest['js'][$key]) && !in_array($key, $this->loaded)) {
                $asset = $this->manifest['js'][$key];
                $url = htmlspecialchars($asset['url'] . '?v=' . $asset['ver'], ENT_QUOTES, 'UTF-8');
                $deferAttr = $defer ? ' defer' : '';
                $html .= sprintf(
                    '<script src="%s" data-module="%s"%s></script>%s',
                    $url,
                    htmlspecialchars($key, ENT_QUOTES, 'UTF-8'),
                    $deferAttr,
                    PHP_EOL
                );
                $this->loaded[] = $key;
            }
        }
        
        return $html;
    }
    
    /**
     * Load all assets for a specific page
     * 
     * @param string $page  Page name ('pack' or 'receive')
     * @return array ['css' => string, 'js' => string]
     */
    public function loadPage(string $page): array
    {
        $cssKeys = ['components', 'lock-ui'];
        $jsKeys = ['shared/api-client', 'shared/modal', 'shared/toast'];
        
        if ($page === 'pack') {
            $cssKeys[] = 'pack';
            $jsKeys = array_merge($jsKeys, [
                'pack/core',
                'pack/autosave',
                'pack/lock-client',
                'pack/validation',
                'pack/product-search',
                'pack/shipping',
                'pack/diagnostics',
            ]);
        } elseif ($page === 'receive') {
            $cssKeys[] = 'receive';
            $jsKeys = array_merge($jsKeys, [
                'receive/core',
                'receive/barcode',
                'receive/validation',
            ]);
        }
        
        return [
            'css' => $this->css($cssKeys),
            'js'  => $this->js($jsKeys),
        ];
    }
    
    /**
     * Get manifest for debugging
     */
    public function getManifest(): array
    {
        return $this->manifest;
    }
    
    /**
     * Check if asset exists
     */
    public function exists(string $type, string $key): bool
    {
        return isset($this->manifest[$type][$key]);
    }
}
