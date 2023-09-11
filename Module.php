<?php declare(strict_types=1);

namespace ApiInfo;

use Doctrine\ORM\QueryBuilder;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Model\ViewModel;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /** @var \Omeka\Permissions\Acl $acl */
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        // This is an api, so all rest api actions are allowed.
        $acl->allow(
            null,
            [\ApiInfo\Controller\ApiController::class]
        );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Api\Representation\ItemRepresentation::class,
            'rep.resource.json',
            [$this, 'filterJsonLdResource']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Representation\MediaRepresentation::class,
            'rep.resource.json',
            [$this, 'filterJsonLdResource']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Representation\ItemSetRepresentation::class,
            'rep.resource.json',
            [$this, 'filterJsonLdResource']
                );
        $sharedEventManager->attach(
            \Annotate\Api\Representation\AnnotationRepresentation::class,
            'rep.resource.json',
            [$this, 'filterJsonLdResource']
        );

        $sharedEventManager->attach(
            \Omeka\Api\Representation\ResourceTemplateRepresentation::class,
            'rep.resource.json',
            [$this, 'filterJsonLdResourceTemplate']
        );
        $sharedEventManager->attach(
            \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation::class,
            'rep.resource.json',
            [$this, 'filterJsonLdResourceTemplate']
        );

        $sharedEventManager->attach(
            \Omeka\Api\Representation\SitePageRepresentation::class,
            'rep.resource.json',
            [$this, 'filterJsonLdSitePage']
        );

        $sharedEventManager->attach(
            \Collecting\Api\Representation\CollectingFormRepresentation::class,
            'rep.resource.json',
            [$this, 'filterJsonLdCollectingForm']
        );

        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.search.query',
            [$this, 'apiSearchQueryMedia']
        );
    }

    public function filterJsonLdResource(Event $event): void
    {
        $services = $this->getServiceLocator();
        $query = $services->get('Application')->getMvcEvent()->getRequest()->getQuery();
        $append = $query->get('append');
        if (!is_array($append)) {
            $append = [$append];
        }
        $appends = array_intersect((array) $append, ['urls', 'sites', 'objects', 'subjects', 'object_ids', 'subject_ids', 'owner_name']);
        if (empty($appends)) {
            return;
        }

        $shortTitle = $query->get('short_title');
        if (!empty($shortTitle)) {
            if (!is_array($shortTitle)) {
                $shortTitle = explode(',', $shortTitle);
            }
            $shortTitle = array_unique($shortTitle);
        }

        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $resource = $event->getTarget();
        $jsonLd = $event->getParam('jsonLd');

        $toAppend = [];

        foreach ($appends as $append) {
            switch ($append) {
                case 'owner_name':
                    if (!empty($jsonLd['o:owner'])) {
                        $jsonLd['o:owner'] = json_decode(json_encode($jsonLd['o:owner']), true);
                        $jsonLd['o:owner']['o:name'] = $resource->owner()->name();
                    }
                    break;

                // Urls is useless since version 3.
                case 'urls':
                    // TODO Move to default key as owner.
                    if ($thumbnail = $resource->thumbnail()) {
                        $toAppend['o:thumbnail']['o:asset_url'] = $thumbnail->assetUrl();
                    }
                    if ($resource->resourceName() !== 'items') {
                        break;
                    }
                    /** @var \Omeka\Api\Representation\MediaRepresentation $media*/
                    foreach ($resource->media() as $media) {
                        $urls = [];
                        if ($thumbnail = $media->thumbnail()) {
                            $urls['o:thumbnail']['o:asset_url'] = $thumbnail->assetUrl();
                        }
                        $urls['o:original_url'] = $media->originalUrl();
                        $urls['o:thumbnail_urls'] = $media->thumbnailUrls();
                        $toAppend['o:media'][] = $urls;
                    }
                    break;

                /** @deprecated Since Omeka v3, sites are listed in items (but still needed for item sets). */
                case 'sites':
                    if ($resource->resourceName() !== 'items') {
                        break;
                    }
                    $api = $services->get('Omeka\ApiManager');
                    $siteIds = $api->search('sites', [], ['returnScalar' => 'id'])->getContent();
                    $siteIds = array_map('intval', $siteIds);
                    foreach ($siteIds as $siteId) {
                        // Only needed info is to check if the sites have items.
                        $hasItem = $api->search('items', ['site_id' => $siteId, 'limit' => 0])->getTotalResults();
                        if ($hasItem) {
                            $toAppend['o:site'][] = $siteId;
                        }
                    }
                    break;

                case 'objects':
                    /** @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::objectValues() */
                    foreach ($resource->values() as $term => $propertyData) {
                        foreach ($propertyData['values'] as $value) {
                            $v = $value->valueResource();
                            if (!$v) {
                                continue;
                            }
                            $resourceClass = $v->resourceClass();
                            $resourceTemplate = $v->resourceTemplate();
                            $r = [
                                '@id' => $v->apiUrl(),
                                'o:id' => $v->id(),
                                'o:type' => $v->getResourceJsonLdType(),
                                'o:resource_class' => $resourceClass ? $resourceClass->getReference() : null,
                                'o:resource_template' => $resourceTemplate ? $resourceTemplate->getReference() : null,
                                'o:is_public' => $v->isPublic(),
                                'o:title' => $v->displayTitle(),
                            ];
                            if ($shortTitle) {
                                foreach ($shortTitle as $prop) {
                                    $vv = $v->value($prop);
                                    if ($vv) {
                                        $r['o:short_title'] = (string) $vv;
                                        break;
                                    }
                                }
                            }
                            $toAppend['o:object'][$term][] = $r;
                        }
                    }
                    break;

                case 'subjects':
                    /** @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::subjectValues() */
                    foreach ($resource->subjectValues() as $term => $values) {
                        foreach ($values as $value) {
                            $v = $value['val']->resource();
                            $resourceClass = $v->resourceClass();
                            $resourceTemplate = $v->resourceTemplate();
                            $r = [
                                '@id' => $v->apiUrl(),
                                'o:id' => $v->id(),
                                'o:type' => $v->getResourceJsonLdType(),
                                'o:resource_class' => $resourceClass ? $resourceClass->getReference() : null,
                                'o:resource_template' => $resourceTemplate ? $resourceTemplate->getReference() : null,
                                'o:is_public' => $v->isPublic(),
                                'o:title' => $v->displayTitle(),
                            ];
                            if ($shortTitle) {
                                foreach ($shortTitle as $prop) {
                                    $vv = $v->value($prop);
                                    if ($vv) {
                                        $r['o:short_title'] = (string) $vv;
                                        break;
                                    }
                                }
                            }
                            $toAppend['o:subject'][$term][] = $r;
                        }
                    }
                    break;

                case 'object_ids':
                    /** @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::objectValues() */
                    // Don't add duplicate.
                    $ids = [];
                    foreach ($this->values() as $property) {
                        foreach ($property['values'] as $value) {
                            $v = $value->resource();
                            if ($v) {
                                $ids[$v->id()] = null;
                            }
                        }
                    }
                    if (count($ids)) {
                        $toAppend['object_ids'] = array_keys($ids);
                    }
                    break;

                case 'subject_ids':
                    /** @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::subjectValues() */
                    // Don't add duplicate.
                    $ids = [];
                    foreach ($resource->subjectValues() as $values) {
                        foreach ($values as $value) {
                            $ids[$value['val']->valueResource()->id()] = null;
                        }
                    }
                    if (count($ids)) {
                        $toAppend['subject_ids'] = array_keys($ids);
                    }
                    break;

                default:
                    break;
            }
        }

        $jsonLd['o-module-api-info:append'] = $toAppend;
        $event->setParam('jsonLd', $jsonLd);
    }

    public function filterJsonLdResourceTemplate(Event $event): void
    {
        $services = $this->getServiceLocator();
        $query = $services->get('Application')->getMvcEvent()->getRequest()->getQuery();
        $append = $query->get('append');
        if (!is_array($append)) {
            $append = [$append];
        }
        $appendables = ['all', 'term', 'label', 'comment'];
        $appends = array_intersect(array_unique($append), $appendables);
        if (empty($appends)) {
            return;
        }

        $appendAll = in_array('all', $appendables) || count($appendables) >= 3;
        $appendTerm = $appendAll || in_array('term', $appendables);
        $appendLabel = $appendAll || in_array('label', $appendables);
        $appendComment = $appendAll || in_array('comment', $appendables);

        // If language is not set, this is the language of the installation.
        $locale = $query->get('locale');
        $translator = $services->get('MvcTranslator');
        if ($locale) {
            if (extension_loaded('intl')) {
                \Locale::setDefault($locale);
            }
            $translator->getDelegatedTranslator()->setLocale($locale);
        }

        $jsonLd = $event->getParam('jsonLd');

        $properties = $this->getPropertiesById();

        if ($jsonLd['o:resource_class']) {
            $classRef = $jsonLd['o:resource_class'];
            $class = $services->get('Omeka\ApiManager')->read('resource_classes', ['id' => $classRef->id()], [], ['initialize' => false])->getContent();
            $classRef = $jsonLd['o:resource_class'];
            $jsonLd['o:resource_class'] = $classRef->jsonSerialize();
            if ($appendTerm) {
                $jsonLd['o:resource_class']['o:term'] = $class->term();
            }
            if ($appendLabel) {
                $jsonLd['o:resource_class']['o:label'] = $translator->translate($class->label());
            }
            if ($appendComment) {
                $jsonLd['o:resource_class']['o:comment'] = $translator->translate($class->comment());
            }
        }

        foreach (['o:title_property', 'o:description_property'] as $key) {
            if (!empty($jsonLd[$key])) {
                $jsonLd[$key] = $jsonLd[$key]->jsonSerialize();
                $property = $properties[$jsonLd[$key]['o:id']];
                if ($appendTerm) {
                    $jsonLd[$key]['o:term'] = $property['term'];
                }
                if ($appendLabel) {
                    $jsonLd[$key]['o:label'] = $translator->translate($property['label']);
                }
                if ($appendComment) {
                    $jsonLd[$key]['o:comment'] = $translator->translate($property['comment']);
                }
            }
        }

        /** @var \Omeka\Api\Representation\ResourceTemplatePropertyRepresentation $rtp */
        foreach ($jsonLd['o:resource_template_property'] as $key => $rtp) {
            $property = $rtp->property();
            $rtp = $rtp->jsonSerialize();
            $rtp['o:property'] = $property->getReference()->jsonSerialize();
            $rtp['o:property']['o:term'] = $properties[$rtp['o:property']['o:id']]['term'];
            $rtp['o:property']['o:label'] = $translator->translate($properties[$rtp['o:property']['o:id']]['label']);
            $jsonLd['o:resource_template_property'][$key] = $rtp;
        }

        $event->setParam('jsonLd', $jsonLd);
    }

    public function filterJsonLdSitePage(Event $event): void
    {
        // TODO Normalize this process to avoid to serve a csrf: it may not be needed for a contact form.
        // To add the csrf in the contact us block allows to contact us by api.
        /** @var \Omeka\Api\Representation\SitePageBlockRepresentation $block */
        $jsonLd = $event->getParam('jsonLd');
        foreach ($jsonLd['o:block'] as $key => $block) {
            if ($block->layout() === 'contactUs') {
                $jsonBlock = $block->jsonSerialize();
                $jsonBlock['o:data']['csrf'] = (new \Laminas\Form\Element\Csrf('csrf_' . $jsonLd['o:id']))->getValue();
                $jsonLd['o:block'][$key] = $jsonBlock;
            }
        }

        $services = $this->getServiceLocator();
        $query = $services->get('Application')->getMvcEvent()->getRequest()->getQuery();
        $append = $query->get('append') ?: [];
        if (!is_array($append)) {
            $append = [$append];
        }
        $appends = array_intersect((array) $append, ['html', 'blocks']);
        foreach ($appends as $append) {
            switch ($append) {
                case 'html':
                    $jsonLd['o-module-api-info:append']['html'] = $this->fetchPageHtml($jsonLd, false);
                    break;
                case 'blocks':
                    $jsonLd['o-module-api-info:append']['blocks'] = $this->fetchPageHtml($jsonLd, true);
                    break;
            }
        }

        $event->setParam('jsonLd', $jsonLd);
    }

    public function filterJsonLdCollectingForm(Event $event): void
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
            'o-module-collecting:resource_query' => (new \Laminas\Form\Element\Csrf('csrf_' . $jsonLd['o:id']))->getValue(),
            'o-module-collecting:media_type' => null,
            'o-module-collecting:required' => false,
            'o:property' => null,
        ];
        $event->setParam('jsonLd', $jsonLd);
    }

    public function apiSearchQueryMedia(Event $event): void
    {
        $adapter = $event->getTarget();
        $qb = $event->getParam('queryBuilder');
        $query = $event->getParam('request')->getContent();

        $expr = $qb->expr();

        if (array_key_exists('has_original', $query) && (string) $query['has_original'] !== '') {
            $qb->andWhere($expr->eq(
                'omeka_root.hasOriginal',
                $adapter->createNamedParameter($qb, (int) (bool) $query['has_original'])
            ));
        }

        if (array_key_exists('has_thumbnails', $query) && (string) $query['has_thumbnails'] !== '') {
            $qb->andWhere($expr->eq(
                'omeka_root.hasThumbnails',
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
     * @see \Reference\Mvc\Controller\Plugin\References::limitQuery()
     *
     * @param QueryBuilder $qb
     * @param array $query
     */
    protected function limitMediaQuery(QueryBuilder $qb, array $query = null): void
    {
        if (empty($query)) {
            return;
        }

        $services = $this->getServiceLocator();
        $subAdapter = $services->get('Omeka\ApiAdapterManager')->get('items');
        $subEntityClass = \Omeka\Entity\Item::class;

        $subQb = $services->get('Omeka\EntityManager')
            ->createQueryBuilder()
            ->select('akemo_root.id')
            ->from($subEntityClass, 'akemo_root');
        $subAdapter
            ->buildQuery($subQb, $query);
        $subQb
            ->groupBy('akemo_root.id');

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
            ->andWhere($qb->expr()->in('omeka_root.item', $subDql));
    }

    protected function fetchPageHtml(array $jsonLd, bool $blocksOnly = false)
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');

        /** @var \Omeka\Api\Representation\SitePageRepresentation $page */
        $page = $api->read('site_pages', ['id' => $jsonLd['o:id']])->getContent();
        $site = $page->site();

        // Create the page as the controller do, without the layout.
        /** @var \Laminas\View\Renderer\PhpRenderer $viewRenderer */
        $viewRenderer = $services->get('ViewRenderer');

        /** @see \Omeka\Mvc\MvcListeners::preparePublicSite() */
        // Need to set the site for site settings.
        $siteSettings = $services->get('Omeka\Settings\Site');
        $siteSettings->setTargetId($site->id());

        // Enable the theme in the view stack to allow to use specific template.
        $services->get('ControllerPluginManager')->get('currentSite')->setSite($site);

        /**
         * @see \Omeka\Mvc\MvcListeners::preparePublicSite()
         * @var \Omeka\Site\Theme\Manager $themeManager
         */
        $themeManager = $services->get('Omeka\Site\ThemeManager');
        $currentTheme = $themeManager->getTheme($site->theme());
        if ($currentTheme->getState() === \Omeka\Site\Theme\Manager::STATE_ACTIVE) {
            // Add the theme view templates to the path stack.
            $services->get('ViewTemplatePathStack')->addPath($currentTheme->getPath('view'));
            // Load theme view helpers on-demand.
            $helpers = $currentTheme->getIni('helpers');
            if (is_array($helpers)) {
                foreach ($helpers as $helper) {
                    $factory = function ($pluginManager) use ($site, $helper, $currentTheme) {
                        require_once $currentTheme->getPath('helper', "$helper.php");
                        $helperClass = sprintf('\OmekaTheme\Helper\%s', $helper);
                        return new $helperClass;
                    };
                    $services->get('ViewHelperManager')->setFactory($helper, $factory);
                }
            }
        }

        // Set the runtime locale and translator language to the configured site
        // locale.
        $locale = $siteSettings->get('locale');
        if ($locale) {
            if (extension_loaded('intl')) {
                \Locale::setDefault($locale);
            }
            $services->get('MvcTranslator')->getDelegatedTranslator()->setLocale($locale);
        }

        $slug = $page->slug();
        $pageBodyClass = 'page site-page-' . preg_replace('([^a-zA-Z0-9\-])', '-', $slug);

        /** @see \Omeka\Controller\Site\PageController::showAction() */
        $viewHelpers = $services->get('ViewHelperManager');
        $viewHelpers->get('sitePagePagination')->setPage($page);

        $view = new ViewModel([
            'site' => $page->site(),
            'page' => $page,
            'pageBodyClass' => $pageBodyClass,
            'displayNavigation' => false,
        ]);

        if ($blocksOnly) {
            $view
                ->setVariable('pageViewModel', $view)
                ->setTemplate('omeka/site/page/content');
            // TODO Some blocks are not renderable currently.
            try {
                $content = $viewRenderer->render($view);
            } catch (\Exception$e) {
                $content = $e;
            }
        } else {
            $view
                ->setTemplate('omeka/site/page/show');
            $contentView = clone $view;
            $contentView
                ->setTemplate('omeka/site/page/content')
                ->setVariable('pageViewModel', $view);
            // FIXME Why add content as child to view is not working? Some blocks fail for router.
            // $view->addChild($contentView, 'content');
            try {
                $content = $viewRenderer->render($contentView);
            } catch (\Exception$e) {
                $content = $e;
            }
            $view->setVariable('content', $content);
            try {
                $content = $viewRenderer->render($view);
            } catch (\Exception$e) {
                $content = $e;
            }
        }

        return $content;
    }

    protected function getPropertiesById()
    {
        static $properties;

        if (isset($properties)) {
            return $properties;
        }

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $qb = $connection->createQueryBuilder();
        $qb
            ->select(
                'property.id AS id',
                'CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
                'property.label AS label',
                'property.comment AS comment',
                // Only the previous selects are needed, but some databases
                // require "order by" or "group by" value to be in the select,
                // in particular to fix the "only_full_group_by" issue.
                'vocabulary.id AS vocabulary_id'
            )
            ->distinct()
            ->from('property', 'property')
            ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('property.id', 'asc')
            ->addGroupBy('property.id')
        ;
        $properties = $connection->executeQuery($qb)->fetchAllAssociative();
        $properties = array_combine(array_column($properties, 'id'), $properties);
        return $properties;
    }
}
