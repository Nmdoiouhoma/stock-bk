<?php

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DatabaseConnectionTest extends KernelTestCase
{
    public function testDatabaseConnection(): void
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);

        // Déclenche la connexion via une requête légère
        $result = $connection->executeQuery('SELECT 1')->fetchOne();

        $this->assertEquals(1, $result, 'La connexion à la base de données a échoué.');
    }

    public function testDatabaseVersion(): void
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);

        $version = $connection->executeQuery('SELECT version()')->fetchOne();

        $this->assertStringContainsStringIgnoringCase('postgresql', $version, 'La base de données retournée n\'est pas PostgreSQL.');
    }
}
