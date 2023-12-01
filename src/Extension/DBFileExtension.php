<?php

namespace QuinnInteractive\WebPSub\Extension;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Extension;

class DBFileExtension extends Extension
{
    // add a WebP blocker when in the CMS
    public function updateURL(&$url)
    {
        if (Controller::curr() instanceof LeftAndMain && $this->owner->getIsImage()) {
            $url = Controller::join_links($url, '?nowebp=1');
        }
    }
}
