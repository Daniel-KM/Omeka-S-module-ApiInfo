<?php
namespace ApiInfo\Controller;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Mvc\Exception;
use Omeka\View\Model\ApiJsonModel;
use Zend\Authentication\AuthenticationService;
use Zend\Http\Response;
use Zend\Mvc\Controller\AbstractRestfulController;
use Zend\Mvc\MvcEvent;
use Zend\Stdlib\RequestInterface as Request;

class ApiController extends AbstractRestfulController
{
    /**
     * @var AuthenticationService
     */
    protected $authenticationService;

    /**
     * @var EntityManager
     */
    protected $entityManager;

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
    protected $querySite;

    /**
     * @param AuthenticationService $authenticationService
     * @param EntityManager $entityManager
     * @param array $config
     */
    public function __construct(
        AuthenticationService $authenticationService,
        EntityManager $entityManager,
        array $config
    ) {
        $this->authenticationService = $authenticationService;
        $this->entityManager = $entityManager;
        $this->config = $config;
    }

    public function create($data)
    {
        return $this->returnError(
            $this->translate('Method Not Allowed'), // @translate
            Response::STATUS_CODE_405
        );
    }

    public function delete($id)
    {
        return $this->returnError(
            $this->translate('Method Not Allowed'), // @translate
            Response::STATUS_CODE_405
        );
    }

    public function deleteList($data)
    {
        return $this->returnError(
            $this->translate('Method Not Allowed'), // @translate
            Response::STATUS_CODE_405
        );
    }

    public function get($id)
    {
        $response = new \Omeka\Api\Response;

        // Reset static data.
        $this->querySite = null;

        switch ($id) {
            case 'ping':
                $result = 1;
                break;
            case 'items':
            case 'media':
            case 'item_sets':
                $result = $this->getInfosResources($id);
                break;

            case 'resources':
                $result = $this->getInfosResources();
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

            // TODO Remove /api/info/site_settings?
            case 'site_settings':
                $result = $this->getSiteSettings();
                break;

            case 'ids':
                $result = $this->getIds();
                break;

            default:
                $result = $this->getInfosOthers($id);
                // When empty, an empty result is returned instead of a bad
                // request in order to manage modules resources.
                break;
        }

        $response->setContent($result);

        return new ApiJsonModel($response, $this->getViewOptions());
    }

    public function getList()
    {
        $response = new \Omeka\Api\Response;
        $list = $this->getInfosResources();
        $list['sites'] = $this->getInfosSites();
        $list['files'] = $this->getInfosFiles();
        $response->setContent($list);
        return new ApiJsonModel($response, $this->getViewOptions());
    }

    public function head($id = null)
    {
        return $this->returnError(
            $this->translate('Method Not Allowed'), // @translate
            Response::STATUS_CODE_405
        );
    }

    public function options()
    {
        return $this->returnError(
            $this->translate('Method Not Allowed'), // @translate
            Response::STATUS_CODE_405
        );
    }

    public function patch($id, $data)
    {
        return $this->returnError(
            $this->translate('Method Not Allowed'), // @translate
            Response::STATUS_CODE_405
        );
    }

    public function replaceList($data)
    {
        return $this->returnError(
            $this->translate('Method Not Allowed'), // @translate
            Response::STATUS_CODE_405
        );
    }

    public function patchList($data)
    {
        return $this->returnError(
            $this->translate('Method Not Allowed'), // @translate
            Response::STATUS_CODE_405
        );
    }

    public function update($id, $data)
    {
        return $this->returnError(
            $this->translate('Method Not Allowed'), // @translate
            Response::STATUS_CODE_405
        );
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
    public function setViewOption($key, $value)
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
    protected function checkContentType(Request $request)
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
        return $this->getAuthenticationService()->hasIdentity();
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

    protected function getInfosResources($resource = null)
    {
        $query = $this->prepareQuerySite();

        $api = $this->api();

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
            $data += $this->getInfosOthers();
        } elseif ($resource === 'media') {
            $data['total'] = $api->search('media', $queryMedia)->getTotalResults();
        } else {
            $data['total'] = $api->search($resource, $query)->getTotalResults();
        }

        return $data;
    }

    protected function getInfosSites()
    {
        $query = $this->prepareQuerySite();

        $querySite = isset($query['site_id'])
            ? ['id' => $query['site_id']]
            : [];

        $api = $this->api();

        $data = [];
        $data['total'] = $api->search('sites', $querySite)->getTotalResults();

        $data['pages'] = $api->search('site_pages', $query)->getTotalResults();

        return $data;
    }

    protected function getInfosFiles()
    {
        $query = $this->prepareQuerySite();

        // The media adapter doesn’t allow to get/count media of a site. See Module.
        if (isset($query['site_id'])) {
            $query['items_site_id'] = $query['site_id'];
            unset($query['site_id']);
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
        $query = $this->prepareQuerySite();

        // Allow handlers to filter the data.
        $data = [];
        $events = $this->getEventManager();
        $args = $events->prepareArgs([
            'query' => $query,
            'data' => $data,
            'id' => $id,
        ]);
        $events->trigger('api.infos.resources', $this, $args);
        $data = $args['data'];
        return $data;
    }

    protected function getSiteData()
    {
        $query = $this->prepareQuerySite();
        $isSingle = !empty($query['site_id']);
        if ($isSingle) {
            $query = ['id' => $query['site_id']];
            unset($query['site_id']);
        }

        $result = [];
        /** @var \Omeka\Api\Representation\SiteRepresentation[] $sites */
        $sites = $this->api()->search('sites', $query)->getContent();
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

    protected function getSiteSettings()
    {
        $query = $this->prepareQuerySite();
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
                    // Display the collecting forms directly in the site pages.
                    case 'collecting':
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
                            //     'o-module-collecting:resource_query' => (new \Zend\Form\Element\Csrf('csrf'))->getValue(),
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

    protected function prepareQuerySite()
    {
        if (!is_null($this->querySite)) {
            return $this->querySite;
        }

        $this->querySite = [];

        $api = $this->api();

        $siteId = $this->params()->fromQuery('site_id');
        if (!$siteId || !is_numeric($siteId)) {
            $siteSlug = $this->params()->fromQuery('site_slug');
            if ($siteSlug) {
                $site = $api->searchOne('sites', ['slug' => $siteSlug])->getContent();
                $siteId = $site ? $site->id() : null;
            }
        }

        if ($siteId) {
            $this->querySite['site_id'] = $siteId;
        }

        return $this->querySite;
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
            'collecting_forms',
        ];
        $types = $this->params()->fromQuery('types', []);
        $types = $types
            ? array_intersect(explode(',', $types), $defaultTypes)
            : $defaultTypes;

        $query = $this->prepareQuerySite();
        $api = $this->api();

        foreach ($types as $type) {
            try {
                $result[$type] = $api->search($type, $query, ['returnScalar' => 'id'])->getContent();
            } catch (\Exception $e) {
                // Avoid to check the modules api keys.
            }
        }

        return $result;
    }

    /**
     * @return \Zend\Authentication\AuthenticationService
     */
    protected function getAuthenticationService()
    {
        return $this->authenticationService;
    }

    /**
     * @return \Doctrine\ORM\EntityManager
     */
    protected function getEntityManager()
    {
        return $this->entityManager;
    }

    /**
     * @return array
     */
    protected function getConfig()
    {
        return $this->config;
    }
}
