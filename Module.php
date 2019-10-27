<?php

namespace ApiInfo;

use Doctrine\ORM\QueryBuilder;
use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\MvcEvent;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        /** @var \Zend\Permissions\Acl $acl */
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        // This is an api, so all rest api actions are allowed.
        $acl->allow(
            null,
            [\ApiInfo\Controller\ApiController::class]
        );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            \Collecting\Api\Representation\CollectingFormRepresentation::class,
            'rep.resource.json',
            [$this, 'filterResourceJsonLdCollectingForm']
        );

        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.search.query',
            [$this, 'apiSearchQueryMedia']
        );

        $sharedEventManager->attach(
            \Omeka\Api\Representation\ItemRepresentation::class,
            'rep.resource.json',
            [$this, 'filterJsonLd']
        );
    }

    public function filterResourceJsonLdCollectingForm(Event $event)
    {
        // To add the csrf as an additionnal prompt in the form allows to manage
        // external offline app more easily.
        $jsonLd = $event->getParam('jsonLd');
        $jsonLd['o-module-collecting:prompt'][] = [
            'o:id' => 'csrf',
            'o-module-collecting:type' => 'csrf',
            'o-module-collecting:text' => null,
            'o-module-collecting:input_type' => 'hidden',
            'o-module-collecting:select_options' => null,
            'o-module-collecting:resource_query' => (new \Zend\Form\Element\Csrf('csrf_' . $jsonLd['o:id']))->getValue(),
            'o-module-collecting:media_type' => null,
            'o-module-collecting:required' => false,
            'o:property' => null,
        ];
        $event->setParam('jsonLd', $jsonLd);
    }

    public function filterJsonLd(Event $event)
    {
        $append = $this->getServiceLocator()->get('Application')->getMvcEvent()->getRequest()
            ->getQuery()->get('append');
        if ($append !== 'urls') {
            return;
        }

        /** @var \Omeka\Api\Representation\ItemRepresentation $item */
        $item = $event->getTarget();
        $jsonLd = $event->getParam('jsonLd');

        $append = [];
        if ($thumbnail = $item->thumbnail()) {
            $append['o:thumbnail']['o:asset_url'] = $thumbnail->assetUrl();
        }

        /** @var \Omeka\Api\Representation\MediaRepresentation $media*/
        foreach ($item->media() as $media) {
            $urls = [];
            if ($thumbnail = $media->thumbnail()) {
                $urls['o:thumbnail']['o:asset_url'] = $thumbnail->assetUrl();
            }
            $urls['o:original_url'] = $media->originalUrl();
            $urls['o:thumbnail_urls'] = $media->thumbnailUrls();
            $append['o:media'][] = $urls;
        }

        $jsonLd['o-module-api-info:append'] = $append;
        $event->setParam('jsonLd', $jsonLd);
    }

    public function apiSearchQueryMedia(Event $event)
    {
        $adapter = $event->getTarget();
        $qb = $event->getParam('queryBuilder');
        $query = $event->getParam('request')->getContent();

        $expr = $qb->expr();
        $this->isOldOmeka = strtok(\Omeka\Module::VERSION, '.') < 2;
        $alias = $this->isOldOmeka ? $adapter->getEntityClass() : 'omeka_root';

        if (array_key_exists('has_original', $query)) {
            $qb->andWhere($expr->eq(
                $alias . '.hasOriginal',
                $adapter->createNamedParameter($qb, (int) (bool) $query['has_original'])
            ));
        }

        if (array_key_exists('has_thumbnails', $query)) {
            $qb->andWhere($expr->eq(
                $alias . '.hasThumbnails',
                $adapter->createNamedParameter($qb, (int) (bool) $query['has_thumbnails'])
            ));
        }

        // Used internally to get all media of a site, that should be in the
        // site pool (managed at item level).
        // Media adapter with "site_id" is something different, not related to
        // the media of the site, so the module adds the special key "items_site_id".
        if (isset($query['items_site_id']) && is_numeric($query['items_site_id'])) {
            // Get the site items pool.
            // @see ItemAdapter::buildQuery()
            $siteAdapter = $adapter->getAdapter('sites');
            try {
                $site = $siteAdapter->findEntity($query['items_site_id']);
                $params = $site->getItemPool();
                if (!is_array($params)) {
                    $params = [];
                }
                // Avoid potential infinite recursion
                unset($params['items_site_id']);

                // Limit the media with the items pool.
                $this->limitMediaQuery($qb, $params);
            } catch (\Omeka\Api\Exception\NotFoundException $e) {
            }
        }
    }

    /**
     * Limit the results with a query (generally the site query).
     *
     * @see \Annotate\Api\Adapter\AnnotationAdapter::buildQuery()
     * @see \Reference\Mvc\Controller\Plugin\Reference::limitQuery()
     *
     * @param QueryBuilder $qb
     * @param array $query
     */
    protected function limitMediaQuery(QueryBuilder $qb, array $query = null)
    {
        if (empty($query)) {
            return;
        }

        $services = $this->getServiceLocator();
        $subAdapter = $services->get('Omeka\ApiAdapterManager')->get('items');
        $subEntityClass = \Omeka\Entity\Item::class;

        if ($this->isOldOmeka) {
            $mainAlias = \Omeka\Entity\Media::class;
            $subAlias = $subEntityClass;
        } else {
            $mainAlias = 'omeka_root';
            $subAlias = 'akemo_root';
        }

        $subQb = $services->get('Omeka\EntityManager')
            ->createQueryBuilder()
            ->select($subAlias . '.id')
            ->from($subEntityClass, $subAlias);
        $subAdapter
            ->buildQuery($subQb, $query);
        $subQb
            ->groupBy($subAlias . '.id');

        // The subquery cannot manage the parameters, since there are
        // two independant queries, but they use the same aliases. Since
        // number of ids may be great, it will be possible to create a
        // temporary table. Currently, a simple string replacement of
        // aliases is used.
        // TODO Fix Omeka core for aliases in sub queries.
        $subDql = str_replace('omeka_', 'akemo_', $subQb->getDQL());
        /** @var \Doctrine\ORM\Query\Parameter $parameter */
        $subParams = $subQb->getParameters();
        foreach ($subParams as $parameter) {
            $qb->setParameter(
                str_replace('omeka_', 'akemo_', $parameter->getName()),
                $parameter->getValue(),
                $parameter->getType()
            );
        }

        $qb
            ->andWhere($qb->expr()->in($mainAlias . '.item', $subDql));
    }
}
