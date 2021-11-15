<?php declare(strict_types=1);

namespace ApiInfo\Controller;

use Doctrine\ORM\EntityManager;
use Laminas\Authentication\AuthenticationService;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractRestfulController;
use Laminas\Mvc\MvcEvent;
use Laminas\Stdlib\RequestInterface as Request;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Mvc\Exception;
use Omeka\Site\BlockLayout\Manager as BlockLayoutManager;
use Omeka\View\Model\ApiJsonModel;

class ApiController extends AbstractRestfulController
{
    const MAX_RESULTS = 1000;

    /**
     * @var AuthenticationService
     */
    protected $authenticationService;

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var BlockLayoutManager
     */
    protected $blockLayoutManager;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var array
     */
    protected $viewOptions = [];

    /**
     * Cache for the query site.
     *
     * @var array
     */
    protected $cleanQuery;

    /**
     * @param AuthenticationService $authenticationService
     * @param EntityManager $entityManager
     * @param BlockLayoutManager $blockLayoutManager
     * @param array $config
     */
    public function __construct(
        AuthenticationService $authenticationService,
        EntityManager $entityManager,
        BlockLayoutManager $blockLayoutManager,
        array $config
    ) {
        $this->authenticationService = $authenticationService;
        $this->entityManager = $entityManager;
        $this->blockLayoutManager = $blockLayoutManager;
        $this->config = $config;
    }

    public function create($data)
    {
        return $this->returnErrorMethodNotAllowed();
    }

    public function delete($id)
    {
        return $this->returnErrorMethodNotAllowed();
    }

    public function deleteList($data)
    {
        return $this->returnErrorMethodNotAllowed();
    }

    public function get($id)
    {
        // Throw exception automatically.
        $resourceType = $this->params()->fromRoute('resource');
        $response = $this->api()->read($resourceType, $id);

        $query = $this->cleanQuery(true);
        $output = $query['output'] ?? 'default';

        if ($this->plugins->has('exportFormatter') && $this->exportFormatter()->has($output)) {
            $outputOptions = $this->getExportOptions($resourceType);
            $resource = $response->getContent();
            /** @see \BulkExport\Mvc\Controller\Plugin\ExportFormatter */
            return $this
                ->exportFormatter()
                ->get($output)
                ->format($resource, null, $outputOptions)
                ->getResponse($resourceType);
        }

        return new ApiJsonModel($response, $this->getViewOptions());

    }

    public function getList()
    {
        $response = new \Omeka\Api\Response;
        $resource = $this->params()->fromRoute('resource', '');
        $this->cleanQuery = null;

        switch ($resource) {
            case 'ping':
                $result = 1;
                break;

            case 'resources':
            case 'items':
            case 'media':
            case 'item_sets':
            case $resource === 'annotations' && $this->hasResource('annotations'):
                $query = $this->cleanQuery(true);
                $output = $query['output'] ?? 'default';
                switch ($output) {
                    case 'by_itemset':
                        $result = $this->getByItemSet($resource, $query);
                        break;
                    case 'datatables':
                        $result = $this->getDatatables($resource, $query);
                        break;
                    case 'tree':
                        $result = $this->getTree($resource, $query);
                        break;
                    case $this->plugins->has('exportFormatter') && $this->exportFormatter()->has($output):
                        $outputOptions = $this->getExportOptions($resource);
                        $resources = $this->api()->search($resource, $query, ['returnScalar' => 'id'])->getContent();
                        /** @see \BulkExport\Mvc\Controller\Plugin\ExportFormatter */
                        return $this
                            ->exportFormatter()
                            ->get($output)
                            ->format($resources, null, $outputOptions)
                            ->getResponse($resource);

                    default:
                        $query = $this->cleanQuery(false);
                        $result = $this->getInfosResources($resource === 'resources' ? null : $resource, $query);
                        break;
                }
                break;

            case 'sites':
                $result = $this->getInfosSites();
                break;

            case 'files':
                $result = $this->getInfosFiles();
                break;

            case 'site_data':
                $result = $this->getSiteData();
                break;

            // TODO Remove /api/infos/settings?
            case 'settings':
                $result = $this->getMainSettings();
                break;

            // TODO Remove /api/infos/site_settings?
            case 'site_settings':
                $result = $this->getSiteSettings();
                break;

            case 'ids':
                $result = $this->getIds();
                break;

            case 'user':
                $result = $this->getCurrentUserData();
                break;

            case 'translations':
                $result = $this->getTranslations();
                break;

            case $resource === 'coins' && $this->viewHelpers()->has('coins'):
                $result = $this->getCoins();
                break;

            case $resource === 'mappings' && $this->blockLayoutManager->has('mappingMapQuery'):
                $result = $this->getMappings();
                break;

            case $resource === 'references' && $this->getPluginManager()->has('references'):
                $result = $this->getReferences();
                break;

            case empty($resource):
                $query = $this->cleanQuery(false);
                $result = $this->getInfosResources(null, $query);
                $result['sites'] = $this->getInfosSites();
                $result['files'] = $this->getInfosFiles();
                break;

            default:
                // Other infos are managed via trigger "api.infos.resources".
                $result = $this->getInfosOthers($resource);
                // When empty, an empty result is returned instead of a bad
                // request in order to manage modules resources.
                // Furthermore, the resource name is "infos" for zip (security).
                $resource = 'infos';
                break;
        }

        if (!empty($this->cleanQuery['zip'])) {
            $this->zip($resource, $result);
            die();
        }

        $response->setContent($result);

        return new ApiJsonModel($response, $this->getViewOptions());
    }

    public function head($id = null)
    {
        return $this->returnErrorMethodNotAllowed();
    }

    public function options()
    {
        return $this->returnErrorMethodNotAllowed();
    }

    public function patch($id, $data)
    {
        return $this->returnErrorMethodNotAllowed();
    }

    public function replaceList($data)
    {
        return $this->returnErrorMethodNotAllowed();
    }

    public function patchList($data)
    {
        return $this->returnErrorMethodNotAllowed();
    }

    public function update($id, $data)
    {
        return $this->returnErrorMethodNotAllowed();
    }

    public function notFoundAction()
    {
        return $this->returnError(
            $this->translate('Page not found'), // @translate
            Response::STATUS_CODE_404
        );
    }

    /**
     * Validate the API request and set global options.
     *
     * @param MvcEvent $event
     * @see \Omeka\Controller\ApiController::onDispatch()
     */
    public function onDispatch(MvcEvent $event)
    {
        $request = $this->getRequest();

        // Set pretty print.
        $prettyPrint = $request->getQuery('pretty_print');
        if (null !== $prettyPrint) {
            $this->setViewOption('pretty_print', true);
        }

        // Set the JSONP callback.
        $callback = $request->getQuery('callback');
        if (null !== $callback) {
            $this->setViewOption('callback', $callback);
        }

        try {
            // Finish dispatching the request.
            $this->checkContentType($request);
            parent::onDispatch($event);
        } catch (\Exception $e) {
            $this->logger()->err((string) $e);
            return $this->getErrorResult($event, $e);
        }
    }

    /**
     * Process post data and call create
     *
     * This method is overridden from the AbstractRestfulController to allow
     * processing of multipart POSTs.
     *
     * @param Request $request
     * @return mixed
     * @see \Omeka\Controller\ApiController::processPostData()
     */
    public function processPostData(Request $request)
    {
        $contentType = $request->getHeader('content-type');
        if ($contentType->match('multipart/form-data')) {
            $content = $request->getPost('data');
            $fileData = $request->getFiles()->toArray();
        } else {
            $content = $request->getContent();
            $fileData = [];
        }
        $data = $this->jsonDecode($content);
        return $this->create($data, $fileData);
    }

    /**
     * Set a view model option.
     *
     * @param string $key
     * @param mixed $value
     * @see \Omeka\Controller\ApiController::setViewOption()
     */
    public function setViewOption($key, $value): void
    {
        $this->viewOptions[$key] = $value;
    }

    /**
     * Get all view options.
     *
     * return array
     * @see \Omeka\Controller\ApiController::getViewOption()
     */
    public function getViewOptions()
    {
        return $this->viewOptions;
    }

    /**
     * Check request content-type header to require JSON for methods with payloads.
     *
     * @param Request $request
     * @throws Exception\UnsupportedMediaTypeException
     * @see \Omeka\Controller\ApiController::checkContentType()
     */
    protected function checkContentType(Request $request): void
    {
        // Require application/json Content-Type for certain methods.
        $method = strtolower($request->getMethod());
        $contentType = $request->getHeader('content-type');
        if (in_array($method, ['post', 'put', 'patch'])
            && (
                !$contentType
                || !$contentType->match(['application/json', 'multipart/form-data'])
            )
        ) {
            $contentType = $request->getHeader('Content-Type');
            $errorMessage = sprintf(
                'Invalid Content-Type header. Expecting "application/json", got "%s".',
                $contentType ? $contentType->getMediaType() : 'none'
            );

            throw new Exception\UnsupportedMediaTypeException($errorMessage);
        }
    }

    /**
     * Check if the request contains an identifier.
     *
     * This method overrides parent in order to allow to query on one or
     * multiple ids.
     *
     * @see \Omeka\Controller\ApiController::getIdentifier()
     *
     * {@inheritDoc}
     * @see \Laminas\Mvc\Controller\AbstractRestfulController::getIdentifier()
     */
    protected function getIdentifier($routeMatch, $request)
    {
        $identifier = $this->getIdentifierName();
        return $routeMatch->getParam($identifier, false);
    }

    /**
     * Set an error result to the MvcEvent and return the result.
     *
     * @param MvcEvent $event
     * @param \Exception $error
     * @see \Omeka\Controller\ApiController::getErrorResult()
     */
    protected function getErrorResult(MvcEvent $event, \Exception $error)
    {
        $result = new ApiJsonModel(null, $this->getViewOptions());
        $result->setException($error);

        $event->setResult($result);
        return $result;
    }

    /**
     * Decode a JSON string.
     *
     * Override ZF's default to always use json_decode and to add error checking.'
     *
     * @param string
     * @return mixed
     * @throws Exception\InvalidJsonException on JSON decoding errors or if the
     * content is a scalar.
     * @see \Omeka\Controller\ApiController::jsonDecode()
     */
    protected function jsonDecode($string)
    {
        $content = json_decode($string, (bool) $this->jsonDecodeType);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception\InvalidJsonException('JSON: ' . json_last_error_msg());
        }

        if (!is_array($content)) {
            throw new Exception\InvalidJsonException('JSON: Content must be an object or array.');
        }
        return $content;
    }

    /**
     * Check if a user is logged.
     *
     * This method simplifies derivative modules that use the same code.
     *
     * @return bool
     */
    protected function isUserLogged()
    {
        return $this->authenticationService->hasIdentity();
    }

    protected function returnErrorMethodNotAllowed()
    {
        return $this->returnError(
            $this->translate('Method Not Allowed'), // @translate
            Response::STATUS_CODE_405
        );
    }

    protected function returnError($message, $statusCode = Response::STATUS_CODE_400, array $errors = null)
    {
        $response = $this->getResponse();
        $response->setStatusCode($statusCode);
        $result = [
            'status' => $statusCode,
            'message' => $message,
        ];
        if (is_array($errors)) {
            $result['errors'] = $errors;
        }
        return new ApiJsonModel($result, $this->getViewOptions());
    }

    protected function getInfosResources($resource = null, array $query = []): array
    {
        $api = $this->api();

        // Don't load entities if the only information needed is total results.
        $query['limit'] = 0;

        // The media adapter doesn’t allow to get/count media of a site. See Module.
        $queryMedia = $query;
        if (isset($queryMedia['site_id'])) {
            $queryMedia['items_site_id'] = $queryMedia['site_id'];
            unset($queryMedia['site_id']);
        }

        // Public/private resources are automatically managed according to user.
        $data = [];
        if (empty($resource) || $resource === 'resources') {
            $data['items']['total'] = $api->search('items', $query)->getTotalResults();
            $data['media']['total'] = $api->search('media', $queryMedia)->getTotalResults();
            $data['item_sets']['total'] = $api->search('item_sets', $query)->getTotalResults();
            // TODO Use a filter.
            if ($this->hasResource('annotations')) {
                $data['annotations']['total'] = $api->search('annotations', $query)->getTotalResults();
            }
            $data += $this->getInfosOthers();
        } elseif ($resource === 'media') {
            $data['total'] = $api->search('media', $queryMedia)->getTotalResults();
        } else {
            $data['total'] = $api->search($resource, $query)->getTotalResults();
        }

        return $data;
    }

    /**
     * Provide the list of resources for datatables javascript library.
     *
     * @see https://datatables.net
     *
     * @param string $resource Only items is managed currently.
     * @param array $query
     * @return array
     */
    protected function getByItemSet($resource = null, array $query = []): array
    {
        $isResource = empty($resource) || $resource === 'resources';

        if ($isResource || $resource !== 'items') {
            return $this->returnError(
                $this->translate('Multiple resources are not implemented currently (only items).'), // @translate
                Response::STATUS_CODE_501
            );
        }

        // TODO Manage pagination (included sub-resources).

        $result = [
            'total' => 0,
            'type' => 'item_sets',
            'data' => [],
        ];

        if (empty($query['item_set_id'])) {
            return $result;
        }

        $itemSets = $query['item_set_id'];
        if (!is_array($itemSets)) {
            $itemSets = [$itemSets];
        }

        $itemSets = array_values(array_unique(array_filter(array_map('intval', $itemSets))));
        if (empty($itemSets)) {
            return $result;
        }

        // TODO Remove hardcoded per_page.
        $paginationPerPage = $this->settings()->get('pagination_per_page');
        if (empty($query['per_page'])) {
            $query['per_page'] = $paginationPerPage;
        } elseif ($query['per_page'] > 1000) {
            return $this->returnError(
                $this->translate('Payload too large.'), // @translate
                Response::STATUS_CODE_413
            );
        }

        $shortTitle = $query['short_title'] ?? [];
        if (!empty($shortTitle)) {
            if (!is_array($shortTitle)) {
                $shortTitle = explode(',', $shortTitle);
            }
            $shortTitle = array_unique($shortTitle);
        }

        if (isset($query['fields'])) {
            $fields = is_array($query['fields']) ? $query['fields'] : explode(',', $query['fields']);
            if ($shortTitle) {
                // Keep title first.
                if (in_array('o:title', $fields)) {
                    array_unshift($fields, 'o:title', 'o:short_title');
                } else {
                    array_unshift($fields, 'o:short_title');
                }
            } elseif (($pos = array_search('o:short_title', $fields, true)) !== false) {
                unset($fields[$pos]);
            }
            $fields = array_unique($fields);
        } else {
            $fields = $shortTitle ? ['o:title', 'o:short_title'] : ['o:title'];
        }

        // Required to avoid to return all results.
        if (empty($query['page'])) {
            $query['page'] = 1;
        }

        $api = $this->api();

        // The media adapter doesn’t allow to get/count media of a site. See Module.
        $queryMedia = $query;
        if (isset($queryMedia['site_id'])) {
            $queryMedia['items_site_id'] = $queryMedia['site_id'];
            unset($queryMedia['site_id']);
        }

        // Public/private resources are automatically managed according to user.

        foreach ($itemSets as $itemSetId) {
            // The Omeka api doesn't allow to search an item set by id.
            try {
                $itemSet = $api->read('item_sets', ['id' => $itemSetId])->getContent();
            } catch (NotFoundException $e) {
                continue;
            }
            ++$result['total'];
            $result['data'][] = [
                'id' => $itemSetId,
                'title' => (string) $itemSet->displayTitle(),
            ];
        }

        // Currently, the simplest process to get items by item set is to loop on
        // on each item set.
        $queryNoItemSet = $query;
        unset($queryNoItemSet['item_set_id']);
        $queryMediaNoItemSet = $queryMedia;
        unset($queryMediaNoItemSet['item_set_id']);

        $append = $this->params()->fromQuery('append');
        if (!is_array($append)) {
            $append = [$append];
        }
        $appends = array_intersect((array) $append, ['urls', 'sites', 'objects', 'subjects', 'object_ids', 'subject_ids']);
        $appendObjectIds = in_array('object_ids', $appends);
        $appendSubjectIds = in_array('subject_ids', $appends);

        foreach ($result['data'] as $key => $itemSet) {
            $currentQuery = $resource === 'media'
                ? $queryMediaNoItemSet
                : $queryNoItemSet;
            $currentQuery['item_set_id'] = $itemSet['id'];
            unset($currentQuery['page']);
            unset($currentQuery['per_page']);
            unset($currentQuery['offset']);
            unset($currentQuery['limit']);
            $datas = $this->getInfosResources($resource, $currentQuery);
            $result['data'][$key]['total'] = $datas['total'];
            $data = $api->search($resource, $currentQuery)->getContent();
            foreach ($data as $res) {
                $resData = [
                    'o:id' => $res->id(),
                ];
                foreach ($fields as $field) {
                    if ($field === 'o:title') {
                        $resData['o:title'] = $res->displayTitle();
                    } elseif ($field === 'o:short_title') {
                        foreach ($shortTitle as $prop) {
                            $v = $res->value($prop);
                            if ($v) {
                                $resData['o:short_title'] = (string) $v;
                                break;
                            }
                        }
                    } else {
                        $v = $res->value($field, ['all' => true]);
                        if ($v) {
                            $resData[$field] = json_decode(json_encode($v), true);
                        }
                    }
                }

                if ($appendObjectIds) {
                    $resData['data']['object_ids'] = [];
                    // @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::objectValues()
                    // Don't add duplicate.
                    foreach ($res->values() as $property) {
                        foreach ($property['values'] as $value) {
                            if (strtok($value->type(), ':') === 'resource') {
                                $resData['data']['object_ids'][$value->valueResource()->id()] = null;
                            }
                        }
                    }
                    $resData['data']['object_ids'] = array_keys($resData['data']['object_ids']);
                }
                if ($appendSubjectIds) {
                    $resData['data']['subject_ids'] = [];
                    // @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::subjectValues()
                    // Don't add duplicate.
                    foreach ($res->subjectValues() as $valueData) {
                        foreach ($valueData as $value) {
                            $resData['data']['subject_ids'][$value->valueResource()->id()] = null;
                        }
                    }
                    $resData['data']['subject_ids'] = array_keys($resData['data']['subject_ids']);
                }
                $result['data'][$key][$resource][] = $resData;
            }
        }

        return $result;
    }

    /**
     * Provide the full results for datatables javascript library.
     *
     * @see https://datatables.net
     *
     * @param string $resource
     * @param array $query
     * @return array
     */
    protected function getDatatables($resource = null, array $query = []): array
    {
        $isResource = empty($resource) || $resource === 'resources';

        if ($isResource) {
            return $this->returnError(
                $this->translate('Multiple resources are not implemented currently.'), // @translate
                Response::STATUS_CODE_501
            );
        }

        // TODO Remove hardcoded per_page.
        $paginationPerPage = $this->settings()->get('pagination_per_page');
        if (empty($query['per_page'])) {
            $query['per_page'] = $paginationPerPage;
        } elseif ($query['per_page'] > 1000) {
            return $this->returnError(
                $this->translate('Payload too large.'), // @translate
                Response::STATUS_CODE_413
            );
        }

        // Required to avoid to return all results.
        if (empty($query['page'])) {
            $query['page'] = 1;
        }

        $api = $this->api();

        // The media adapter doesn’t allow to get/count media of a site. See Module.
        $queryMedia = $query;
        if (isset($queryMedia['site_id'])) {
            $queryMedia['items_site_id'] = $queryMedia['site_id'];
            unset($queryMedia['site_id']);
        }

        // TODO Cache results for datatables (with "draw"), but it can be cached by datatables itself.

        // Public/private resources are automatically managed according to user.

        $datas = $this->getInfosResources($resource, $this->cleanQuery(false));
        $data = [
            'draw' => $query['draw'] ?? 0,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
        ];
        $data['recordsTotal'] = $datas['total'];
        $data['recordsFiltered'] = $datas['total'];
        if ($resource === 'media') {
            $data['data'] = $api->search('media', $queryMedia)->getContent();
        } else {
            $data['data'] = $api->search($resource, $query)->getContent();
        }

        return $data;
    }

    /**
     * Provide the full results for a tree of resources, mainly for d3js.
     */
    protected function getTree(string $resource = null, array $query = []): array
    {
        $isResource = empty($resource) || $resource === 'resources';

        if ($isResource) {
            return $this->returnError(
                $this->translate('Multiple resources are not implemented currently.'), // @translate
                Response::STATUS_CODE_501
            );
        }

        $api = $this->api();

        $termTitle = $query['tree_title'] ?? null;
        $termName = $query['tree_name'] ?? null;
        $termParent = $query['tree_parent'] ?? null;
        $termChild = $query['tree_child'] ?? 'dcterms:hasPart';
        $termBase = $query['tree_base'] ?? $termChild;
        $withMedia = !empty($query['with_media']);

        if ($termTitle === 'o:title') {
            $termTitle = null;
        }
        if ($termName === 'o:title') {
            $termName = null;
        }

        // If there is a parent term, get only the resource without parents.
        if ($termParent) {
            $query['property'][] = ['joiner' => 'and', 'type' => 'nex', 'property' => $termParent];
        } else {
            $termParent = 'dcterms:isPartOf';
        }
        $roots = $api->search($resource, $query)->getContent();
        if (empty($roots)) {
            return [];
        }

        $titleAndName = function (AbstractResourceEntityRepresentation $resource) use ($termTitle, $termName): array {
            $title = $termTitle
                ? (string) $resource->value($termTitle)
                : '';
            if (!strlen($title)) {
                $title = $resource->displayTitle();
            }
            $name = (string) $resource->value($termName);
            return [$title, $name];
        };

        $baseResources = function (AbstractResourceEntityRepresentation $resource) use ($termBase): array {
            $values = $resource->value($termBase, ['all' => true, 'type' => ['resource', 'resource:item', 'resource:itemset', 'resource:media', 'resource:annotation']]);
            return array_map(function ($v) {
                return $v->valueResource();
            }, $values);
        };

        $childrenResources = function (AbstractResourceEntityRepresentation $resource) use ($termChild): array {
            $values = $resource->value($termChild, ['all' => true, 'type' => ['resource', 'resource:item', 'resource:itemset', 'resource:media', 'resource:annotation']]);
            return array_map(function ($v) {
                return $v->valueResource();
            }, $values);
        };

        if (count($roots) === 1) {
            $root = reset($roots);
            [$title, $name] = $titleAndName($root);
            $tree = [
                'id' => $root->id(),
                'name' => $name ?: '0',
                'title' => $title,
                'children' => $baseResources($root),
            ];
            if ($withMedia) {
                $tree['medias'] = $api->search('media', ['item_id' => $root], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
                $tree['medias'] = array_map('intval', array_values($tree['medias']));
            }
        } elseif (!empty($query['item_set_id'])
            && $itemSet = $api->searchOne('item_sets', ['id' => $query['item_set_id']])->getContent()
        ) {
            [$title, $name] = $titleAndName($itemSet);
            $tree = [
                'id' => $itemSet->id(),
                'name' => $name ?: '0',
                'title' => $title,
                'children' => $roots,
            ];
        } else {
            $tree = [
                'id' => null,
                'name' => '0',
                'title' => $this->translate('Tree'), // @translate,
                'children' => $roots,
            ];
        }

        $maxLevel = 100;
        $buildTree = null;
        $buildTree = function (array $branchs, array &$listIds, $parentId = null, $level = 0) use ($maxLevel, $titleAndName, $childrenResources, $withMedia, $api, &$buildTree): array {
            if ($level > $maxLevel) {
                return [];
            }
            $subtree = [];
            foreach ($branchs as $branch) {
                $id = $branch->id();
                if ($id === $parentId || in_array($id, $listIds)) {
                    continue;
                }
                $listIds[] = $id;
                [$title, $name] = $titleAndName($branch);
                $newSubTree = [
                    'id' => $id,
                    'name' => $name ?: $title,
                    'title' => $title,
                    'children' => $buildTree($childrenResources($branch), $listIds, $id, $level + 1),
                ];
                if ($withMedia) {
                    $newSubTree['medias'] = $api->search('media', ['item_id' => $id], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
                    $newSubTree['medias'] = array_map('intval', array_values($newSubTree['medias']));
                }
                $subtree[] = $newSubTree;
            }
            return $subtree;
        };

        $listIds = [];
        $tree['children'] = $buildTree($tree['children'], $listIds, $tree['id']);
        return $tree;
    }

    protected function getInfosSites()
    {
        $query = $this->cleanQuery(false);
        $query = isset($query['site_id'])
            ? ['id' => $query['site_id']]
            : [];

        // Don't load entities if the only information needed is total results.
        $query['limit'] = 0;

        $api = $this->api();

        $data = [];
        $data['total'] = $api->search('sites', $query)->getTotalResults();
        $data['pages'] = $api->search('site_pages', $query)->getTotalResults();

        return $data;
    }

    protected function getInfosFiles()
    {
        $query = $this->cleanQuery(false);

        // The media adapter doesn’t allow to get/count media of a site. See Module.
        if (isset($query['site_id'])) {
            $query['items_site_id'] = $query['site_id'];
            unset($query['site_id']);
        }

        // Don't load entities if the only information needed is total results.
        if (empty($query['limit'])) {
            $query['limit'] = 0;
        }

        // Public/private resources are automatically managed according to user.

        // Get all medias with a file.
        $query['has_original'] = 1;

        $api = $this->api();

        $data = [];
        $data['total'] = $api->search('media', $query)->getTotalResults();

        unset($query['has_original']);
        $query['has_thumbnails'] = 1;
        $data['thumbnails'] = $api->search('media', $query)->getTotalResults();

        $query['has_original'] = 1;
        $data['original_and_thumbnails'] = $api->search('media', $query)->getTotalResults();

        // TODO Use the entity manager to sum the file sizes (with visibility, etc.): will be needed if media > 10000.
        unset($query['has_thumbnails']);
        $sizes = $api->search('media', $query, ['returnScalar' => 'size'])->getContent();
        $data['size'] = array_sum($sizes);

        return $data;
    }

    protected function getInfosOthers($id = null)
    {
        $query = $this->cleanQuery(false);

        // Allow handlers to filter the data.
        $data = [];
        $events = $this->getEventManager();
        $args = $events->prepareArgs([
            'query' => $query,
            'data' => $data,
            'id' => $id,
        ]);
        $events->trigger('api.infos.resources', $this, $args);
        return $args['data'];
    }

    protected function getSiteData()
    {
        $query = $this->cleanQuery(false);
        $isSingle = !empty($query['id']) || !empty($query['site_id']);
        if ($isSingle) {
            $query = ['id' => empty($query['site_id']) ? $query['id'] : $query['site_id']];
            unset($query['site_id']);
        }

        $api = $this->api();

        $result = [];
        if ($isSingle) {
            try {
                $site = $api->read('sites', ['id' => $query['id']])->getContent();
            } catch (NotFoundException $e) {
                return null;
            }
            $sites = [$site];
        } else {
            $sites = $api->search('sites', $query)->getContent();
        }

        /** @var \Omeka\Api\Representation\SiteRepresentation[] $sites */
        foreach ($sites as $site) {
            $data = json_decode(json_encode($site), true);
            $data['o:setting'] = $this->siteSettingsList($site->id());
            $data['o:page'] = $this->sitePages($site);
            $result[] = $data;
        }

        if ($isSingle) {
            $result = reset($result);
        }

        return $result;
    }

    protected function getMainSettings()
    {
        return $this->settingsList();
    }

    protected function getSiteSettings()
    {
        $query = $this->cleanQuery(false);
        if ($query) {
            return $this->siteSettingsList($query['site_id']);
        }

        $result = [];
        $sites = $this->api()->search('sites', [], ['responseContent' => 'resource'])->getContent();
        foreach ($sites as $site) {
            $siteId = $site->getId();
            $data = [];
            $data['o:id'] = $siteId;
            $data['o:slug'] = $site->getSlug();
            $data['o:setting'] = $this->siteSettingsList($siteId);
            $result[] = $data;
        }
        return $result;
    }

    protected function sitePages(SiteRepresentation $site)
    {
        $result = [];

        $api = $this->api();
        foreach ($site->pages() as $page) {
            $data = json_decode(json_encode($page), true);
            foreach ($data['o:block'] as $key => $block) {
                switch ($block['o:layout']) {
                    case 'assets':
                        // Fix relative urls.
                        $siteBaseUrl = '/s/' . $site->slug() . '/page/';
                        foreach ($block['o:data']['assets'] as $k => $asset) {
                            if (!empty($asset['url']) && mb_substr($asset['url'], 0, 1) !== '/') {
                                $data['o:block'][$key]['o:data']['assets'][$k]['url'] = $siteBaseUrl . $asset['url'];
                            }
                        }
                        break;

                    // Display the collecting forms directly in the site pages.
                    case 'collecting':
                        // Fix issue when there is no form.
                        if (empty($block['o:data']['forms'])) {
                            break;
                        }
                        foreach ($block['o:data']['forms'] as $k => $formId) {
                            /** @var \Collecting\Api\Representation\CollectingFormRepresentation $collectingForm */
                            $collectingForm = $api
                                ->searchOne('collecting_forms', ['id' => $formId])
                                ->getContent();
                            // The hidden csrf is added automatically to json.
                            // $collectingForm = json_decode(json_encode($collectingForm), true);
                            // $collectingForm['o-module-collecting:prompt'][] = [
                            //     'o:id' => 'csrf',
                            //     'o-module-collecting:type' => 'csrf',
                            //     'o-module-collecting:text' => null,
                            //     'o-module-collecting:input_type' => 'hidden',
                            //     'o-module-collecting:select_options' => null,
                            //     'o-module-collecting:resource_query' => (new \Laminas\Form\Element\Csrf('csrf'))->getValue(),
                            //     'o-module-collecting:media_type' => null,
                            //     'o-module-collecting:required' => false,
                            //     'o:property' => null,
                            // ];
                            $data['o:block'][$key]['o:data']['forms'][$k] = $collectingForm;
                        }
                        break;
                }
            }
            $result[] = $data;
        }

        return $result;
    }

    /**
     * Clean the query, in particular for the site id, either site id or site slug.
     *
     * @bool $keepPagination
     * @return array
     */
    protected function cleanQuery($keepPagination = false)
    {
        if (!is_null($this->cleanQuery)) {
            if ($keepPagination) {
                return $this->cleanQuery;
            }

            $query = $this->cleanQuery;
            unset($query['page']);
            unset($query['per_page']);
            unset($query['offset']);
            unset($query['limit']);
            return $query;
        }

        $query = $this->params()->fromQuery();
        if (empty($query['site_id']) && !empty($query['site_slug'])) {
            $siteSlug = $query['site_slug'];
            if ($siteSlug) {
                $api = $this->api();
                $siteId = $api->searchOne('sites', ['slug' => $siteSlug], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
                if ($siteId) {
                    $query['site_id'] = $siteId;
                }
            }
        }

        $this->cleanQuery = $query;
        return $this->cleanQuery;
    }

    protected function getIds()
    {
        $result = [];

        // Check if only some types are required.
        $defaultTypes = [
            'items',
            'media',
            'item_sets',
            'vocabularies',
            'properties',
            'resource_classes',
            'resource_templates',
            'sites',
            'site_pages',
            // Modules.
            'annotations',
            'collecting_forms',
        ];
        $types = $this->params()->fromQuery('types', []);
        $types = $types
            ? array_intersect(explode(',', $types), $defaultTypes)
            : $defaultTypes;

        $query = $this->cleanQuery(false);
        $api = $this->api();

        $rank = $query['rank'] ?? null;
        $prevnext = $query['prevnext'] ?? null;

        foreach ($types as $type) {
            try {
                $result[$type] = $api->search($type, $query, ['returnScalar' => 'id'])->getContent();
                // Since Omeka 3, the keys are returned too as id, that is useless.
                $result[$type] = array_map('intval', array_values($result[$type]));
                if ($rank) {
                    $rankResource = array_search((int) $rank, $result[$type], true);
                    $result[$type] = $rankResource === false
                        ? []
                        : array_slice($result[$type], $rankResource, 1, true);
                } elseif ($prevnext) {
                    $rankResource = array_search((int) $prevnext, $result[$type], true);
                    if ($rankResource === false) {
                        $result[$type] = [];
                    } else {
                        $result[$type] = $rankResource === 0
                            ? array_slice($result[$type], $rankResource, 2, true)
                            : array_slice($result[$type], $rankResource - 1, 3, true);
                    }
                }
            } catch (\Exception $e) {
                // Avoid to check the modules api keys.
            }
        }

        return $result;
    }

    protected function getCurrentUserData()
    {
        /** @var \Omeka\Entity\User $user */
        $user = $this->authenticationService->getIdentity();
        if (!$user) {
            // Return empty array instead of null to simplify check.
            return [];
        }

        return $this->api()->read('users', ['id' => $user->getId()])->getContent()
            ->jsonSerialize();
    }

    protected function getTranslations()
    {
        $translate = $this->viewHelpers()->get('translate');
        $translatorTextDomain = $translate->getTranslatorTextDomain();
        /** @var \Laminas\I18n\Translator\Translator $translator */
        $translator = $translate->getTranslator()->getDelegatedTranslator();

        // TODO How to get all the English strings?
        // $locale = $translator->getLocale();
        $locale = $this->params()->fromQuery('locale', 'en');
        $translations = $translator->getAllMessages($translatorTextDomain, $locale);
        return is_null($translations)
            ? []
            : $translations->getArrayCopy();
    }

    /**
     * @see \BulkExport\Controller\OutputController::outputAction()
     */
    protected function getExportOptions(?string $resourceType = 'resources'): array
    {
        $query = $this->cleanQuery(true);
        $siteId = $query['site_id'] ?? null;
        try {
            $site = $this->api()->read('sites', ['id' => $siteId], [], ['responseContent' => 'resource'])->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            $site = null;
        }

        $isSiteRequest = !empty($site);
        if ($isSiteRequest) {
            $settings = $this->siteSettings();
            $settings->setTargetId($siteId);
        } else {
            $settings = $this->settings();
        }

        $resourceLimit = $settings->get('bulkexport_limit') ?: 1000;

        $options = [];
        $options['site_slug'] = $isSiteRequest ? $site->getSlug() : null;
        $options['metadata'] = $settings->get('bulkexport_metadata', []);
        $options['metadata_exclude'] = $settings->get('bulkexport_metadata_exclude', []);
        $options['format_fields'] = $settings->get('bulkexport_format_fields', 'name');
        $options['format_generic'] = $settings->get('bulkexport_format_generic', 'string');
        $options['format_resource'] = $settings->get('bulkexport_format_resource', 'url_title');
        $options['format_resource_property'] = $settings->get('bulkexport_format_resource_property', 'dcterms:identifier');
        $options['format_uri'] = $settings->get('bulkexport_format_uri', 'uri_label');
        $options['template'] = $settings->get('bulkexport_template');
        $options['is_admin_request'] = !$isSiteRequest;
        $options['resource_type'] = $resourceType;
        $options['limit'] = $resourceLimit;

        return $options;
    }

    protected function getCoins()
    {
        $query = $this->cleanQuery();

        $result = [];
        $coins = $this->viewHelpers()->get('coins');
        $response = $this->api()->search('items', $query);
        foreach ($response->getContent() as $resource) {
            $result[$resource->id()] = $coins($resource);
        }

        return $result;
    }

    protected function getMappings()
    {
        $query = $this->cleanQuery();

        // To simplify timeline building, use the original block.
        /** @var \Mapping\Site\BlockLayout\MapQuery $mapQueryBlock */
        $mapQueryBlock = $this->blockLayoutManager->get('mappingMapQuery');

        // @see \Mapping\Site\BlockLayout\MapQuery::render()

        if (!empty($query['block_id'])) {
            /** @var \Omeka\Entity\SitePageBlock $block */
            $block = $this->entityManager->find(\Omeka\Entity\SitePageBlock::class, (int) $query['block_id']);
            if (!$block) {
                return [];
            }
            $data = $block->getData();
            parse_str($data['query'], $query);
            $query = array_merge($query, [
                'site_id' => $block->getPage()->getSite()->getId(),
                'has_markers' => true,
                'limit' => self::MAX_RESULTS,
            ]);
        } else {
            // Search only for items with markers that are in the current site, and
            // set a reasonable item limit.
            $query = array_merge($query, [
                // 'site_id' => $block->getPage()->getSite()->getId(),
                'has_markers' => true,
                'limit' => self::MAX_RESULTS,
            ]);
            $defaultMapping = [
                'basemap_provider' => null,
                'max_zoom' => null,
                'min_zoom' => null,
                'bounds' => null,
                'wms' => [],
            ];
            $mapping = !empty($query['mapping']) && is_array($query['mapping'])
                ? $query['mapping'] + $defaultMapping
                : $defaultMapping;
            $defaultTimeline = [
                'title_headline' => '',
                'title_text' => '',
                'fly_to' => null,
                'show_contemporaneous' => null,
                'timenav_position' => null,
                'data_type_properties' => null,
            ];
            $timeline = !empty($query['timeline']) && is_array($query['timeline'])
                ? $query['timeline'] + $defaultTimeline
                : $defaultTimeline;
            unset($query['mapping'], $query['timeline']);
            $data = $mapping + ['timeline' => $timeline];
        }

        $timelineIsAvailable = $mapQueryBlock->timelineIsAvailable();
        $isTimeline = $timelineIsAvailable
            && !empty($data['timeline']['data_type_properties']);

        // Get markers (and events, if applicable) from the attached items.
        $events = [];
        $markers = [];

        $api = $this->api();
        $response = $api->search('items', $query);
        foreach ($response->getContent() as $item) {
            if ($isTimeline) {
                // Set the timeline event for this item.
                // The method needs the view only to access the api, so the
                // controller is fine.
                $event = $mapQueryBlock->getTimelineEvent($item, $query['timeline']['data_type_properties'], $this);
                if ($event) {
                    $events[] = $event;
                }
            }
            // Set the map markers for this item.
            $itemMarkers = $api->search('mapping_markers', ['item_id' => $item->id()])->getContent();
            $markers = array_merge($markers, $itemMarkers);
        }

        $mapping = $data;
        unset($mapping['timeline'], $mapping['query']);

        $result = [
            'query' => $query,
            'mapping' => $mapping,
            'markers' => $markers,
        ];

        // TODO Support the attachements of the block.
        if ($isTimeline) {
            // The php renderer is required in the block, but not used.
            $result['timeline'] = $mapQueryBlock->getTimelineData($events, $data, new \Laminas\View\Renderer\PhpRenderer);
            $result['timelineOptions'] = $mapQueryBlock->getTimelineOptions($data);
        }

        return $result;
    }

    /**
     * @return array
     */
    protected function getReferences()
    {
        $query = $this->cleanQuery();

        // Field may be an array.
        // Empty string field means meta results.
        $field = $query['metadata'] ?? [];
        $fields = is_array($field) ? $field : [$field];
        $fields = array_unique($fields);

        // Deprecated: support old format.
        if (empty($fields)) {
            $field = $query['field'] ?? [];
            $fields = is_array($field) ? $field : [$field];
            $fields = array_unique($fields);
        }

        unset($query['metadata']);

        $options = $query;
        if (array_key_exists('query', $options)) {
            $query = is_array($options['query']) ? $options['query'] : ['text' => $options['query']];
        }

        if (isset($options['option']) && is_array($options['option'])) {
            $options = $options['option'];
        }

        unset($query['query']);
        unset($query['option']);
        unset($options['query']);
        unset($options['option']);

        // Text is full text, but full text doesn't work via api.
        if (array_key_exists('text', $query) && strlen($query['text'])) {
            $query['property'][] = [
                'joiner' => 'and',
                'property' => '',
                'type' => 'in',
                'text' => $query['text'],
            ];
        }
        unset($query['text']);
        unset($query['per_page']);
        unset($query['page']);
        unset($query['sort_by']);
        unset($query['sort_order']);
        unset($query['offset']);
        unset($query['limit']);

        return $this->references($fields, $query, $options)->list();
    }

    /**
     * @return array
     */
    protected function getConfig()
    {
        return $this->config;
    }

    /**
     * @return bool
     */
    protected function hasResource($resourceName)
    {
        return (bool) @$this->getConfig()['api_adapters']['invokables'][$resourceName];
    }

    protected function zip(string $resource, $result)
    {
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

        $basename = $resource;

        $options = new \ZipStream\Option\Archive();
        $options->setSendHttpHeaders(true);

        $zip = new \ZipStream\ZipStream("$basename.zip", $options);

        $content = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $zip->addFile("$basename.json", $content);

        $zip->finish();
    }
}
