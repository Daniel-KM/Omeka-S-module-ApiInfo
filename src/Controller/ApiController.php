<?php
namespace ApiInfo\Controller;

use Doctrine\ORM\EntityManager;
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

        switch ($id) {
            case 'resources':
                $result = $this->getInfosResources();
                break;

            case 'files':
                $result = $this->getInfosFiles();
                break;

            case 'site_settings':
                $result = $this->getSiteSettings();
                if ($result) {
                    break;
                }
                // No break.

            default:
                return $this->returnError(
                    $this->translate('Bad Request'), // @translate
                    Response::STATUS_CODE_400
                );
        }

        $response->setContent($result);

        return new ApiJsonModel($response, $this->getViewOptions());
    }

    public function getList()
    {
        $response = new \Omeka\Api\Response;
        $list = [];
        $list['resources'] = $this->getInfosResources();
        $list += $this->getInfosFiles();
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

    protected function getInfosResources()
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
        $data['items']['total'] = $api->search('items', $query)->getTotalResults();
        $data['media']['total'] = $api->search('media', $queryMedia)->getTotalResults();
        $data['item_sets']['total'] = $api->search('item_sets', $query)->getTotalResults();

        // Allow handlers to filter the data.
        $events = $this->getEventManager();
        $args = $events->prepareArgs([
            'query' => $query,
            'data' => $data,
        ]);
        $events->trigger('api.infos.resources', $this, $args);
        $data = $args['data'];

        return $data;
    }

    protected function getInfosFiles()
    {
        $data = $this->prepareQuerySite();

        // The media adapter doesn’t allow to get/count media of a site. See Module.
        if (isset($data['site_id'])) {
            $data['items_site_id'] = $data['site_id'];
            unset($data['site_id']);
        }

        // Public/private resources are automatically managed according to user.

        // Get all medias with a file.
        $data['has_original'] = 1;

        $api = $this->api();

        $total = [];
        $total['files']['total'] = $api->search('media', $data)->getTotalResults();

        unset($data['has_original']);
        $data['has_thumbnails'] = 1;
        $total['files']['thumbnails'] = $api->search('media', $data)->getTotalResults();

        $data['has_original'] = 1;
        $total['files']['original_and_thumbnails'] = $api->search('media', $data)->getTotalResults();

        // TODO Use the entity manager to sum the file sizes (with visibility, etc.): will be needed if media > 10000.
        unset($data['has_thumbnails']);
        $sizes = $api->search('media', $data, ['returnScalar' => 'size'])->getContent();
        $total['files']['size'] = array_sum($sizes);

        return $total;
    }

    protected function getSiteSettings()
    {
        $data = $this->prepareQuerySite();
        return empty($data)
            ? null
            : $this->siteSettingsList($data['site_id']);
    }

    protected function prepareQuerySite()
    {
        $data = [];

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
            $data['site_id'] = $siteId;
        }

        return $data;
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
