<?php declare(strict_types=1);

namespace Analytics\Mvc;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;

class MvcListeners extends AbstractListenerAggregate
{
    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        // A ViewEvent allows to add a script easierly, but it is better to use
        // MvcEvent Dispatch because this is the only one that is use in all
        // cases, including json api, xml output for module oai-pmh and error.
        // Cannot be used:
        // Events Bootstrap and Route: all services are not yet loaded.
        // Event Finish: some file requests don't finish, for example when a big
        // file is viewed but stopped before end.
        // TODO Manage Dispatch error.
        $events->attach(
            MvcEvent::EVENT_DISPATCH,
            [$this, 'processLogCurrentUrl'],
            // Lately to support rerouting (module Clean Url).
            1000
        );
    }

    /**
     * @see \Analytics\Mvc\Controller\Plugin\LogCurrentUrl
     */
    public function processLogCurrentUrl(MvcEvent $mvcEvent): void
    {
        // In case of error or a internal redirection, there may be two calls.
        // TODO Is it still useful?
        static $processed = false;

        if ($processed) {
            return;
        }

        $processed = true;

        $mvcEvent->getApplication()->getServiceManager()
            ->get('ControllerPluginManager')->get('logCurrentUrl')();
    }
}
