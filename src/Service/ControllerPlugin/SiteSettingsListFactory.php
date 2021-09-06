<?php declare(strict_types=1);
namespace ApiInfo\Service\ControllerPlugin;

use ApiInfo\Mvc\Controller\Plugin\SiteSettingsList;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SiteSettingsListFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // TODO Should we use doctrine orm or dbal connection for performance?
        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $services->get('Omeka\EntityManager');
        $qb = $entityManager->createQueryBuilder();
        $expr = $qb->expr();
        $qb
            ->select(['setting.id', 'setting.value'])
            ->from('site_setting', 'setting')
            ->where($expr->eq('setting.site_id', ':site_id'))
            // Manage the special case for theme settings: returns only the
            // current one (the key is reset to "theme_settings" in plugin).
            ->andWhere($expr->orX(
                $expr->notLike('setting.id', ':theme_ids'),
                $expr->eq('setting.id', ':theme_id')
            ));

        return new SiteSettingsList(
            $entityManager->getConnection(),
            $qb
        );
    }
}
