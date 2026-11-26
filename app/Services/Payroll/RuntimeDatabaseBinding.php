<?php

namespace App\Services\Payroll;

use Illuminate\Database\Connection;
use PDO;

class RuntimeDatabaseBinding
{
    public function read(Connection $connection, string $deployment): array
    {
        $host = $connection->getConfig('host');
        abort_unless($connection->getDriverName() === 'pgsql'
            && is_string($host) && preg_match('/\A[a-z0-9.-]+\z/i', $host), 503);

        $statement = $connection->getPdo()->query(
            'SELECT current_database() AS database_name, current_schema() AS schema_name'
        );
        $row = $statement ? $statement->fetch(PDO::FETCH_ASSOC) : false;
        abort_unless(is_string($row['database_name'] ?? null) && is_string($row['schema_name'] ?? null), 503);

        return [
            'runtime_host_sha256' => hash('sha256', strtolower($host)),
            'database' => $row['database_name'],
            'schema_name' => $row['schema_name'],
            'runtime_deployment' => $deployment,
            'api_version' => 'v1',
        ];
    }
}
