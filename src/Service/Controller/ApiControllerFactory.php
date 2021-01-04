<?php declare(strict_types=1);

namespace ApiInfo\Service\Controller;

use ApiInfo\Controller\ApiController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ApiControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ApiController(
            $services->get('Omeka\AuthenticationService'),
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\BlockLayoutManager'),
            $services->get('Config')
        );
    }
}
