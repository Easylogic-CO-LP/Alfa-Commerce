<?php
/**
 * @package    Com_Alfa
 * @subpackage Tests
 */

namespace Alfa\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Example test to verify PHPUnit is working correctly.
 *
 * Replace this with actual unit tests as the test suite grows.
 */
class ExampleTest extends TestCase
{
    public function testBootstrapConstantsAreDefined(): void
    {
        $this->assertTrue(defined('_JEXEC'));
        $this->assertTrue(defined('JPATH_ROOT'));
        $this->assertTrue(defined('JPATH_ADMINISTRATOR'));
    }

    public function testProjectStructureExists(): void
    {
        $this->assertDirectoryExists(JPATH_ROOT . '/administrator/src');
        $this->assertDirectoryExists(JPATH_ROOT . '/site/src');
        $this->assertDirectoryExists(JPATH_ROOT . '/api/src');
        $this->assertDirectoryExists(JPATH_ROOT . '/plugins');
        $this->assertDirectoryExists(JPATH_ROOT . '/modules');
    }

    public function testSqlSchemaFileExists(): void
    {
        $this->assertFileExists(JPATH_ROOT . '/administrator/sql/install.mysql.utf8.sql');
    }
}
