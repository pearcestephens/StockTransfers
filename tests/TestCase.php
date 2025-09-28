<?php
declare(strict_types=1);

/**
 * Basic Test Case for Stock Transfers System
 * 
 * @author Pearce Stephens <pearce.stephens@ecigdis.co.nz>
 * @copyright 2025 Ecigdis Limited
 */

namespace VapeShed\StockTransfers\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure we're in test environment
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['APP_DEBUG'] = 'true';
        
        // Mock any external dependencies if needed
        $this->setUpTestEnvironment();
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up after tests
        $this->cleanUpTestData();
    }
    
    /**
     * Set up the test environment
     */
    protected function setUpTestEnvironment(): void
    {
        // Override any configuration needed for testing
    }
    
    /**
     * Clean up test data
     */
    protected function cleanUpTestData(): void
    {
        // Clean up any test data created during tests
    }
    
    /**
     * Create a mock PDO instance for database testing
     */
    protected function mockDatabase(): \PDO
    {
        return new \PDO('sqlite::memory:');
    }
    
    /**
     * Assert that response is valid JSON
     */
    protected function assertValidJson(string $json): void
    {
        json_decode($json);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error());
    }
    
    /**
     * Assert that array has required keys
     */
    protected function assertArrayHasKeys(array $expectedKeys, array $array): void
    {
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array);
        }
    }
}