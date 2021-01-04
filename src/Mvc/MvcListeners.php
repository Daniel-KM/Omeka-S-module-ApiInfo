<?php declare(strict_types=1);

namespace ApiInfo\Mvc;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\Http\RouteMatch;
use Omeka\Api\Manager as ApiManager;

class MvcListeners extends AbstractListenerAggregate
{
    /**
     * @var MvcEvent
     */
    protected $event;

    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_ROUTE,
            [$this, 'appendParams']
        );
    }

    /**
     * Append the site slug and the page slug to the request params so that the
     * api can render html version of page blocks (the helper url() needs it to
     * create urls of resources).
     *
     * @param \Laminas\Mvc\MvcEvent $event
     */
    public function appendParams(MvcEvent $event): void
    {
        $routeMatch = $event->getRouteMatch();
        $matchedRouteName = $routeMatch->getMatchedRouteName();
        if ($matchedRouteName !== 'api/default') {
            return;
        }

        $query = $event->getRequest()->getQuery();
        $append = $query->get('append');
        if (empty($append)
            || (is_string($append) && $append !== 'html' && $append !== 'blocks')
            || (is_array($append) && !in_array('html', $append) && !in_array('blocks', $append))
        ) {
            return;
        }

        $services = $event->getApplication()->getServiceManager();

        /** @var \Omeka\Api\Manager $api */
        $api = $services->get('Omeka\ApiManager');

        $resourceName = $routeMatch->getParam('resource');
        $id = $routeMatch->getParam('id');

        // Append the site when the resource is site, else the default site or
        // first public site.
        if ($resourceName === 'sites') {
            if ($id) {
                try {
                    $site = $api->read('sites', $id, [], ['responseContent' => 'resource'])->getContent();
                    $routeMatch->setParam('site-slug', $site->getSlug());
                    return;
                } catch (\Omeka\Api\Exception\NotFoundException $e) {
                }
            }
        } elseif ($resourceName === 'site_pages') {
            if ($id) {
                try {
                    $page = $api->read('site_pages', $id, [], ['responseContent' => 'resource'])->getContent();
                    $routeMatch
                        ->setParam('site-slug', $page->getSite()->getSlug())
                        ->setParam('page-slug', $page->getSlug());
                    return;
                } catch (\Omeka\Api\Exception\NotFoundException $e) {
                }
            } else {
                // Check if the site slug can be determined from the query.
                $siteId = $event->getRequest()->getQuery()->get('site_id');
                if ($siteId) {
                    try {
                        $site = $api->read('sites', $siteId, [], ['responseContent' => 'resource'])->getContent();
                        $routeMatch->setParam('site-slug', $site->getSlug());
                        return;
                    } catch (\Omeka\Api\Exception\NotFoundException $e) {
                    }
                }
            }
        }

        $this->appendDefaultSite($routeMatch, $api, $services) ?? $this->appendFirstSite($routeMatch, $api);
    }

    protected function appendDefaultSite(RouteMatch $routeMatch, ApiManager $api, $services): ?\Omeka\Entity\Site
    {
        $settings = $services->get('Omeka\Settings');
        $id = $settings->get('default_site');
        if ($id) {
            try {
                $site = $api->read('sites', $id, [], ['responseContent' => 'resource'])->getContent();
                $routeMatch->setParam('site-slug', $site->getSlug());
                return $site;
            } catch (\Omeka\Api\Exception\NotFoundException $e) {
            }
        }
        return null;
    }

    protected function appendFirstSite(RouteMatch $routeMatch, ApiManager $api): ?\Omeka\Entity\Site
    {
        $sites = $api->search('sites', ['sort_by' => 'is_public', 'sort_order' => 'desc', 'limit' => 1], ['initialize' => false, 'finalize' => false])->getContent();
        if ($sites) {
            $site = reset($sites);
            $routeMatch->setParam('site-slug', $site->getSlug());
            return $site;
        }
        return null;
    }
}
