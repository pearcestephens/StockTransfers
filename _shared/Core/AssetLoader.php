<?php
/**
 * File: AssetLoader.php
 * Purpose: Automatically loads CSS and JS assets from standardized directories
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: None (pure PHP)
 */

declare(strict_types=1);

namespace Modules\Transfers\Shared\Core;

/**
 * AssetLoader - Automatically discovers and loads CSS/JS assets
 * 
 * Features:
 * - Auto-discovers files in assets/css/ and assets/js/ directories
 * - Supports versioning via ?v= parameter
 * - Conditional loading (only load specific files if needed)
 * - Cache-busting via file modification time
 * - Proper HTML5 output with defer/async options
 */
class AssetLoader
{
    private string $basePath;
    private string $baseUrl;
    private array $cssExclusions;
    private array $jsExclusions;
    private bool $useFileMtimeVersioning;

    public function __construct(
        string $basePath,
        string $baseUrl = '',
        array $cssExclusions = [],
        array $jsExclusions = [],
        bool $useFileMtimeVersioning = true
    ) {
        $this->basePath = rtrim($basePath, '/');
        $this->baseUrl = $baseUrl ?: $this->detectBaseUrl();
        $this->cssExclusions = $cssExclusions;
        $this->jsExclusions = $jsExclusions;
        $this->useFileMtimeVersioning = $useFileMtimeVersioning;
    }

    /**
     * Auto-detect base URL from current request
     */
    private function detectBaseUrl(): string
    {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Try to detect the module path from the current script
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (strpos($scriptName, '/modules/transfers/') !== false) {
            $modulePath = substr($scriptName, 0, strpos($scriptName, '/modules/transfers/') + strlen('/modules/transfers/'));
            return $protocol . $host . $modulePath;
        }
        
        return $protocol . $host . '/modules/transfers/';
    }

    /**
     * Get version parameter for cache busting
     */
    private function getVersion(string $filePath): string
    {
        if (!$this->useFileMtimeVersioning || !file_exists($filePath)) {
            return '1.0';
        }
        
        return (string) filemtime($filePath);
    }

    /**
     * Scan directory for asset files
     */
    private function scanAssets(string $directory, string $extension, array $exclusions = []): array
    {
        $fullPath = $this->basePath . '/' . $directory;
        if (!is_dir($fullPath)) {
            return [];
        }

        $files = [];
        $iterator = new \DirectoryIterator($fullPath);
        
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot() || $fileInfo->isDir()) {
                continue;
            }

            $filename = $fileInfo->getFilename();
            if (pathinfo($filename, PATHINFO_EXTENSION) !== $extension) {
                continue;
            }

            // Skip excluded files
            if (in_array($filename, $exclusions, true)) {
                continue;
            }

            $files[] = [
                'filename' => $filename,
                'path' => $fullPath . '/' . $filename,
                'url' => $this->baseUrl . $directory . '/' . $filename,
                'size' => $fileInfo->getSize(),
                'mtime' => $fileInfo->getMTime()
            ];
        }

        // Sort by filename for consistent loading order
        usort($files, function($a, $b) {
            return strcmp($a['filename'], $b['filename']);
        });

        return $files;
    }

    /**
     * Generate CSS link tags for all CSS files
     */
    public function loadCSS(string $directory = 'assets/css', array $additionalExclusions = []): string
    {
        $exclusions = array_merge($this->cssExclusions, $additionalExclusions);
        $cssFiles = $this->scanAssets($directory, 'css', $exclusions);
        
        if (empty($cssFiles)) {
            return "<!-- No CSS files found in {$directory} -->\n";
        }

        $output = "<!-- Auto-loaded CSS files from {$directory} -->\n";
        
        foreach ($cssFiles as $file) {
            $version = $this->getVersion($file['path']);
            $url = $file['url'] . '?v=' . $version;
            $output .= '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }

        return $output;
    }

    /**
     * Generate script tags for all JS files
     */
    public function loadJS(
        string $directory = 'assets/js', 
        array $additionalExclusions = [], 
        bool $defer = true,
        bool $async = false
    ): string {
        $exclusions = array_merge($this->jsExclusions, $additionalExclusions);
        $jsFiles = $this->scanAssets($directory, 'js', $exclusions);
        
        if (empty($jsFiles)) {
            return "<!-- No JS files found in {$directory} -->\n";
        }

        $output = "<!-- Auto-loaded JS files from {$directory} -->\n";
        
        $attributes = [];
        if ($defer) $attributes[] = 'defer';
        if ($async) $attributes[] = 'async';
        $attributeString = empty($attributes) ? '' : ' ' . implode(' ', $attributes);

        foreach ($jsFiles as $file) {
            $version = $this->getVersion($file['path']);
            $url = $file['url'] . '?v=' . $version;
            $output .= '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $attributeString . '></script>' . "\n";
        }

        return $output;
    }

    /**
     * Get information about discovered assets (for debugging)
     */
    public function getAssetInfo(string $directory, string $extension): array
    {
        return $this->scanAssets($directory, $extension);
    }

    /**
     * Load both CSS and JS in optimal order
     */
    public function loadAll(
        string $cssDirectory = 'assets/css',
        string $jsDirectory = 'assets/js',
        array $cssExclusions = [],
        array $jsExclusions = [],
        bool $jsDefer = true
    ): string {
        $output = '';
        $output .= $this->loadCSS($cssDirectory, $cssExclusions);
        $output .= $this->loadJS($jsDirectory, $jsExclusions, $jsDefer);
        return $output;
    }

    /**
     * Create a pre-configured instance for stock transfers
     */
    public static function forStockTransfers(string $basePath = null): self
    {
        // Point to the stock subfolder where assets are located
        $basePath = $basePath ?: realpath(__DIR__ . '/../../../stock');
        
        return new self(
            basePath: $basePath,
            baseUrl: '/modules/transfers/stock/', // Point to stock subfolder for URLs
            cssExclusions: [], // Could exclude development/test files
            jsExclusions: [],  // Could exclude development/test files
            useFileMtimeVersioning: true
        );
    }
}