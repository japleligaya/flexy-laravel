<?php

namespace App\Database;

use Illuminate\Database\Connectors\PostgresConnector;
use InvalidArgumentException;

class NeonPostgresConnector extends PostgresConnector
{
    protected function getDsn(array $config)
    {
        if (! preg_match('/\A(ep-[a-z0-9-]+)\.[a-z0-9.-]+\.neon\.tech\z/', $config['host'] ?? '', $endpoint)) {
            throw new InvalidArgumentException('The Neon configuration requires a valid Neon hostname.');
        }

        if (isset($config['sslrootcert'])) {
            $config['sslrootcert'] = "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $config['sslrootcert'])."'";
        }

        return parent::getDsn($config).";options='endpoint=".$endpoint[1]."'";
    }
}
