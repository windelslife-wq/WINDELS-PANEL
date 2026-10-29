<?php
use PHPUnit\Framework\TestCase;

class DatabaseDsnTest extends TestCase {
    public static function setUpBeforeClass(): void {
        $root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', $root.'/system/');
        if (!defined('APPPATH')) define('APPPATH', $root.'/application/');
        require APPPATH.'config/database.php';
    }

    public function testMysqlPdoDsnSuppliesNameForIntrospection() {
        $this->assertSame('panel', marvy_db_database('', 'mysql:host=localhost;dbname=panel;charset=utf8mb4', 'pdo'));
        $this->assertSame('panel', marvy_db_database('', 'mysql:dbname=panel;host=localhost', 'PDO'));
        $this->assertSame('explicit', marvy_db_database('explicit', 'mysql:dbname=panel', 'pdo'));
        $this->assertSame('', marvy_db_database('', 'sqlite:/tmp/test.sqlite', 'pdo'));
        $this->assertSame('', marvy_db_database('', 'mysql:dbname=panel', 'mysqli'));
    }
}
