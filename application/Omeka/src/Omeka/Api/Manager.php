<?php
namespace Omeka\Api;

use Omeka\Api\Adapter\AdapterInterface;
use Omeka\Api\Exception;
use Omeka\Event\Event;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\I18n\Translator\TranslatorInterface;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * API manager service.
 */
class Manager implements ServiceLocatorAwareInterface
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * Execute a search API request.
     *
     * @param string $resource
     * @param mixed $data
     * @return Response
     */
    public function search($resource, $data = null)
    {
        $request = new Request(Request::SEARCH, $resource);
        $request->setContent($data);
        return $this->execute($request);
    }

    /**
     * Execute a create API request.
     *
     * @param string $resource
     * @param mixed $data
     * @return Response
     */
    public function create($resource, $data = null)
    {
        $request = new Request(Request::CREATE, $resource);
        $request->setContent($data);
        return $this->execute($request);
    }

    /**
     * Execute a batch create API request.
     *
     * @param string $resource
     * @param mixed $data
     * @return Response
     */
    public function batchCreate($resource, $data = null)
    {
        $request = new Request(Request::BATCH_CREATE, $resource);
        $request->setContent($data);
        return $this->execute($request);
    }

    /**
     * Execute a read API request.
     *
     * @param string $resource
     * @param mixed $id
     * @param mixed $data
     * @return Response
     */
    public function read($resource, $id, $data = null)
    {
        $request = new Request(Request::READ, $resource);
        $request->setId($id);
        $request->setContent($data);
        return $this->execute($request);
    }

    /**
     * Execute an update API request.
     *
     * @param string $resource
     * @param mixed $id
     * @param mixed $data
     * @return Response
     */
    public function update($resource, $id, $data = null)
    {
        $request = new Request(Request::UPDATE, $resource);
        $request->setId($id);
        $request->setContent($data);
        return $this->execute($request);
    }

    /**
     * Execute a delete API request.
     *
     * @param string $resource
     * @param mixed $id
     * @param mixed $data
     * @return Response
     */
    public function delete($resource, $id, $data = null)
    {
        $request = new Request(Request::DELETE, $resource);
        $request->setId($id);
        $request->setContent($data);
        return $this->execute($request);
    }

    /**
     * Execute an API request.
     * 
     * @param Request $request
     * @return Response
     */
    public function execute(Request $request)
    {
        try {
            $t = $this->getTranslator();

            // Validate the request.
            if (!$request->isValidOperation($request->getOperation())) {
                throw new Exception\BadRequestException(sprintf(
                    $t->translate('The API does not support the "%1$s" request operation.'),
                    $request->getOperation()
                ));
            }

            // Get the adapter.
            $adapter = $this->getServiceLocator()
                ->get('Omeka\ApiAdapterManager')
                ->get($request->getResource());

            // Verify that the current user has general access to this resource.
            $acl = $this->getServiceLocator()->get('Omeka\Acl');
            if (!$acl->isAllowed('current_user', $adapter, $request->getOperation())) {
                throw new Exception\PermissionDeniedException(sprintf(
                    $t->translate('Permission denied for the current user to %1$s the %2$s resource.'),
                    $request->getOperation(),
                    $adapter->getResourceId()
                ));
            }

            // Trigger the api.execute.pre event.
            $event = new Event(Event::API_EXECUTE_PRE, $adapter, array(
                'services' => $this->getServiceLocator(),
                'request' => $request,
            ));
            $adapter->getEventManager()->trigger($event);

            // Trigger the api.{operation}.pre event.
            $event = new Event(
                'api.' . $request->getOperation() . '.pre',
                $adapter, array(
                    'services' => $this->getServiceLocator(),
                    'request' => $request,
                )
            );
            $adapter->getEventManager()->trigger($event);

            switch ($request->getOperation()) {
                case Request::SEARCH:
                    $response = $adapter->search($request);
                    break;
                case Request::CREATE:
                    $response = $adapter->create($request);
                    break;
                case Request::BATCH_CREATE:
                    $response = $this->executeBatchCreate($request, $adapter);
                    break;
                case Request::READ:
                    $response = $adapter->read($request);
                    break;
                case Request::UPDATE:
                    $response = $adapter->update($request);
                    break;
                case Request::DELETE:
                    $response = $adapter->delete($request);
                    break;
                default:
                    throw new Exception\BadRequestException(sprintf(
                        $t->translate('The API does not support the "%1$s" request operation.'),
                        $request->getOperation()
                    ));
            }

            // Validate the response.
            if (!$response instanceof Response) {
                throw new Exception\BadResponseException(sprintf(
                    $t->translate('The "%1$s" operation for the "%2$s" resource adapter did not return an Omeka\Api\Response object.'),
                    $request->getOperation(),
                    $request->getResource()
                ));
            }
            if (!$response->isValidStatus($response->getStatus())) {
                throw new Exception\BadResponseException(sprintf(
                    $t->translate('The API does not support the "%1$s" response status.'),
                    $response->getStatus()
                ));
            }

            // Trigger the api.{operation}.post event.
            $event = new Event(
                'api.' . $request->getOperation() . '.post',
                $adapter,
                array(
                    'services' => $this->getServiceLocator(),
                    'request' => $request,
                    'response' => $response,
                )
            );
            $adapter->getEventManager()->trigger($event);

            // Trigger the api.execute.post event.
            $event = new Event(Event::API_EXECUTE_POST, $adapter, array(
                'services' => $this->getServiceLocator(),
                'request' => $request,
                'response' => $response,
            ));
            $adapter->getEventManager()->trigger($event);

        // Always return a Response object, regardless of exception.
        } catch (Exception\BadRequestException $e) {
            $this->getServiceLocator()->get('Omeka\Logger')->err((string) $e);
            $response = new Response;
            $response->setStatus(Response::ERROR_BAD_REQUEST);
            $response->addError(Response::ERROR_BAD_REQUEST, $e->getMessage());
        } catch (Exception\BadResponseException $e) {
            $this->getServiceLocator()->get('Omeka\Logger')->err((string) $e);
            $response = new Response;
            $response->setStatus(Response::ERROR_BAD_RESPONSE);
            $response->addError(Response::ERROR_BAD_RESPONSE, $e->getMessage());
        } catch (Exception\PermissionDeniedException $e) {
            $this->getServiceLocator()->get('Omeka\Logger')->err((string) $e);
            $response = new Response;
            $response->setStatus(Response::ERROR_PERMISSION_DENIED);
            $response->addError(Response::ERROR_PERMISSION_DENIED, $e->getMessage());
        } catch (\Exception $e) {
            $this->getServiceLocator()->get('Omeka\Logger')->err((string) $e);
            $response = new Response;
            $response->setStatus(Response::ERROR_INTERNAL);
            $response->addError(Response::ERROR_INTERNAL, $e->getMessage());
        }

        $response->setRequest($request);
        return $response;
    }

    /**
     * Execute a batch create operation.
     *
     * @param Request $request
     * @param null|AdapterInterface $adapter Custom adapter
     * @return Response
     */
    protected function executeBatchCreate(Request $request, AdapterInterface $adapter)
    {
        $t = $this->getTranslator();
        if (!is_array($request->getContent())) {
            throw new Exception\BadRequestException(
                $t->translate('Invalid batch operation request data.')
            );
        }

        // Create a simulated request for individual create events.
        $createRequest = new Request(
            Request::CREATE,
            $request->getResource()
        );

        // Trigger the create.pre event for every resource.
        foreach ($request->getContent() as $content) {
            $createRequest->setContent($content);
            $createEvent = new Event(Event::API_CREATE_PRE, $adapter, array(
                'request' => $createRequest,
            ));
            $adapter->getEventManager()->trigger($createEvent);
        }

        $response = $adapter->batchCreate($request);

        // Do not trigger create.post events if an error has occured or if the
        // response does not return valid content.
        if ($response->isError() || !is_array($response->getContent())) {
            return $response;
        }

        // Trigger the create.post event for every created resource.
        foreach ($response->getContent() as $resource) {
            $createRequest->setContent($resource);
            $createEvent = new Event(Event::API_CREATE_POST, $adapter, array(
                'request' => $createRequest,
                'response' => new Response($resource),
            ));
            $adapter->getEventManager()->trigger($createEvent);
        }

        return $response;
    }

    /**
     * Get the translator service
     *
     * return TranslatorInterface
     */
    public function getTranslator()
    {
        if (!$this->translator instanceof TranslatorInterface) {
            $this->translator = $this->getServiceLocator()->get('MvcTranslator');
        }
        return $this->translator;
    }

    /**
     * {@inheritDoc}
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->services = $serviceLocator;
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceLocator()
    {
        return $this->services;
    }
}
