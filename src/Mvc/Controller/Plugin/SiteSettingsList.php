<?php
namespace ApiInfo\Mvc\Controller\Plugin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class SiteSettingsList extends AbstractPlugin
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
     * Helper to get all settings of a site.
     *
     * For theme, only settings of the current theme are returned, with key
     * "theme_settings".
     *
     * @param int $siteId
     * @return array
     */
    public function __invoke($siteId)
    {
        // Allows to check rights too.
        $site = $this->getController()->api()
            ->searchOne('sites', ['id' => $siteId], ['responseContent' => 'resource'])
            ->getContent();
        if (empty($site)) {
            return [];
        }

        // TODO Check if a dql is quicker to get site settings of all sites.
        $themeKey = 'theme_settings_' . $site->getTheme();
        $result = $this->connection
            ->executeQuery($this->qb, [
                'site_id' => $siteId,
                'theme_ids' => 'theme_settings_%',
                'theme_id' => $themeKey,
            ])
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        $result = array_map(function ($v) {
            return json_decode($v, true);
        }, $result);

        // Manage the special case for the theme settings.
        if (isset($result[$themeKey])) {
            $result['theme_settings'] = $result[$themeKey];
            unset($result[$themeKey]);
        } else {
            $result['theme_settings'] = [];
        }

        return $result;
    }
}
