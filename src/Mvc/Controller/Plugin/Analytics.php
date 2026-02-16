<?php declare(strict_types=1);

namespace Analytics\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Analytics\View\Helper\Analytics as AnalyticsHelper;

/**
 * Helper to get some public stats.
 *
 * Note: There is no difference between total of page or download, because each
 * url is unique, but there are differences between positions and viewed pages
 * and downloaded files lists.
 */
class Analytics extends AbstractPlugin
{
    protected $analyticsHelper;

    public function __construct(AnalyticsHelper $analytics)
    {
        $this->analyticsHelper = $analytics;
    }

    public function __invoke(): AnalyticsHelper
    {
        return $this->analyticsHelper;
    }
}
