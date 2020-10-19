<?php
namespace ApiInfo\Service\ControllerPlugin;

use ApiInfo\Mvc\Controller\Plugin\SettingsList;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SettingsListFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedNamed, array $options = null)
    {
        $whitelist = [
            // General params of the form \Omeka\Form\SettingForm.
            'installation_title',
            'time_zone',
            'pagination_per_page',
            'property_label_information',
            'default_site',
            'locale',
            'disable_jsonld_embed',
        ];

        // TODO Should we use doctrine orm or dbal connection for performance?
        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $services->get('Omeka\EntityManager');
        $qb = $entityManager->createQueryBuilder();
        $expr = $qb->expr();
        $qb
            ->select(['setting.id', 'setting.value'])
            ->from('setting', 'setting')
            // TODO How to do a "WHERE IN" with doctrine and array of strings (without quoting them manually)?
            // ->where($expr->in('setting.id', ':whitelist'))
            // ->setParameter('whitelist', $whitelist)
            ->where($expr->in('setting.id', '"' . implode('","', $whitelist) . '"'))
        ;

        return new SettingsList(
            $entityManager->getConnection(),
            $qb
        );
    }
}
