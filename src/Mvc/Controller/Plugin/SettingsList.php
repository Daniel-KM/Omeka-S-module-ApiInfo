<?php declare(strict_types=1);
namespace ApiInfo\Mvc\Controller\Plugin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class SettingsList extends AbstractPlugin
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var QueryBuilder
     */
    protected $qb;

    /**
     * @param Connection $connection
     * @param QueryBuilder $qb
     */
    public function __construct(Connection $connection, QueryBuilder $qb)
    {
        $this->connection = $connection;
        $this->qb = $qb;
    }

    /**
     * Helper to get all main settings.
     *
     * @return array
     */
    public function __invoke()
    {
        $result = $this->connection
            ->executeQuery($this->qb)
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        return array_map(function ($v) {
            return json_decode($v, true);
        }, $result);
    }
}
