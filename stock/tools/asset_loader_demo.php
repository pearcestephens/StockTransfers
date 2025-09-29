<?php
/**
 * Asset Loading Demo
 * Shows how the new AssetLoader works
 */

require_once __DIR__ . '/../_shared/Autoload.php';

echo "<h1>Asset Loading Demo</h1>\n\n";

echo "<h2>Debug Asset Discovery:</h2>\n";
echo "<pre>";
print_r(debug_transfer_assets());
echo "</pre>\n\n";

echo "<h2>Generated CSS Includes:</h2>\n";
echo "<pre>";
echo htmlspecialchars(load_transfer_css());
echo "</pre>\n\n";

echo "<h2>Generated JS Includes:</h2>\n";
echo "<pre>";
echo htmlspecialchars(load_transfer_js());
echo "</pre>\n\n";

echo "<h2>Load All Assets:</h2>\n";
echo "<pre>";
echo htmlspecialchars(load_transfer_assets());
echo "</pre>\n\n";

echo "<h2>With Exclusions (exclude dispatch.css and dispatch.js):</h2>\n";
echo "<pre>";
echo htmlspecialchars(load_transfer_assets(
    cssExclusions: ['dispatch.css'], 
    jsExclusions: ['dispatch.js']
));
echo "</pre>\n";
?>