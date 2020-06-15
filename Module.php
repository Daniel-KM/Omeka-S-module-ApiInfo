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
            \Omeka\Api\Representation\SitePageRepresentation::class,
            'rep.resource.json',
            [$this, 'filterResourceJsonSitePage']
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

    public function filterResourceJsonSitePage(Event $event)
    {
        // TODO Normalize this process to avoid to serve a csrf: it may not be needed for a contact form.
        // To add the csrf in the contact us block allows to contact us by api.
        /** @var \Omeka\Api\Representation\SitePageBlockRepresentation $block */
        $jsonLd = $event->getParam('jsonLd');
        foreach ($jsonLd['o:block'] as $key => $block) {
            if ($block->layout() === 'contactUs') {
                $jsonBlock = $block->jsonSerialize();
                $jsonBlock['o:data']['csrf'] = (new \Zend\Form\Element\Csrf('csrf_' . $jsonLd['o:id']))->getValue();
                $jsonLd['o:block'][$key] = $jsonBlock;
            }
        }
        $event->setParam('jsonLd', $jsonLd);
    }

    public function filterJsonLd(Event $event)
    {
        $services = $this->getServiceLocator();
        $append = $services->get('Application')->getMvcEvent()->getRequest()
            ->getQuery()->get('append');
        if (!is_array($append)) {
            $append = [$append];
        }
        $appends = array_intersect((array) $append, ['urls', 'sites', 'objects', 'subjects']);
        if (empty($appends)) {
            return;
        }

        /** @var \Omeka\Api\Representation\ItemRepresentation $item */
        $item = $event->getTarget();
        $jsonLd = $event->getParam('jsonLd');

        $toAppend = [];

        foreach ($appends as $append) {
            switch ($append) {
                case 'urls':
                    if ($thumbnail = $item->thumbnail()) {
                        $toAppend['o:thumbnail']['o:asset_url'] = $thumbnail->assetUrl();
                    }

                    /** @var \Omeka\Api\Representation\MediaRepresentation $media*/
                    foreach ($item->media() as $media) {
                        $urls = [];
                        if ($thumbnail = $media->thumbnail()) {
                            $urls['o:thumbnail']['o:asset_url'] = $thumbnail->assetUrl();
                        }
                        $urls['o:original_url'] = $media->originalUrl();
                        $urls['o:thumbnail_urls'] = $media->thumbnailUrls();
                        $toAppend['o:media'][] = $urls;
                    }
                    break;
                case 'sites':
                    $api = $services->get('Omeka\ApiManager');
                    $siteIds = $api->search('sites', [], ['returnScalar' => 'id'])->getContent();
                    $siteIds = array_map('intval', $siteIds);
                    foreach ($siteIds as $siteId) {
                        $hasItem = $api->search('items', ['id' => $item->id(), 'site_id' => $siteId])->getTotalResults();
                        if ($hasItem) {
                            $toAppend['o:site'][] = $siteId;
                        }
                    }
                    break;
                case 'objects':
                    // @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::objectValues()
                    foreach ($item->values() as $term => $propertyData) {
                        foreach ($propertyData['values'] as $value) {
                            if (strtok($value->type(), ':') !== 'resource') {
                                continue;
                            }
                            $v = $value->valueResource();
                            $resourceClass = $v->resourceClass();
                            $resourceTemplate = $v->resourceTemplate();
                            $toAppend['o:object'][$term][] = [
                                '@id' => $v->apiUrl(),
                                'o:id' => $v->id(),
                                'o:type' => $v->getResourceJsonLdType(),
                                'o:resource_class' => $resourceClass ? $resourceClass->getReference() : null,
                                'o:resource_template' => $resourceTemplate ? $resourceTemplate->getReference() : null,
                                'o:is_public' => $v->isPublic(),
                                'o:title' => $v->displayTitle(),
                            ];
                        }
                    }
                    break;
                case 'subjects':
                    foreach ($item->subjectValues() as $term => $values) {
                        foreach ($values as $value) {
                            $v = $value->resource();
                            $resourceClass = $v->resourceClass();
                            $resourceTemplate = $v->resourceTemplate();
                            $toAppend['o:subject'][$term][] = [
                                '@id' => $v->apiUrl(),
                                'o:id' => $v->id(),
                                'o:type' => $v->getResourceJsonLdType(),
                                'o:resource_class' => $resourceClass ? $resourceClass->getReference() : null,
                                'o:resource_template' => $resourceTemplate ? $resourceTemplate->getReference() : null,
                                'o:is_public' => $v->isPublic(),
                                'o:title' => $v->displayTitle(),
                            ];
                        }
                    }
                    break;
                default:
                    break;
            }
        }

        $jsonLd['o-module-api-info:append'] = $toAppend;
        $event->setParam('jsonLd', $jsonLd);
    }

    public function apiSearchQueryMedia(Event $event)
    {
        $adapter = $event->getTarget();
        $qb = $event->getParam('queryBuilder');
        $query = $event->getParam('request')->getContent();

        $expr = $qb->expr();
        $this->isOldOmeka = \Omeka\Module::VERSION < 2;
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
