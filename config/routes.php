<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use TacticMedia\ScimServerBundle\Controller\ScimController;

return static function (RoutingConfigurator $routes): void {
    $routes->add(ScimController::ROUTE, '/{path}')
        ->controller('tacticmedia_scim_server.controller')
        ->requirements(['path' => '.*'])
        ->defaults(['path' => ''])
    ;
};
